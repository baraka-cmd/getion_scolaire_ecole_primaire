<?php

defined('ACCESS') or die('Accès interdit');

final class AssistantImportService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Stocke un fichier hors de la zone publique et prépare un aperçu sans écrire
     * dans les tables métier.
     *
     * @return array<string, mixed>
     */
    public function uploadPreview(int $userId, int $conversationId, array $file): array
    {
        if (!$this->conversationBelongsToUser($conversationId, $userId)) {
            throw new RuntimeException('Conversation introuvable ou expirée.');
        }
        $validated = $this->validateUpload($file);
        $storageDirectory = STORAGE_PATH . 'assistant_uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Impossible de préparer le stockage sécurisé.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $validated['extension'];
        $storedPath = $storageDirectory . $storedName;
        if (!move_uploaded_file($validated['tmp_name'], $storedPath)) {
            throw new RuntimeException('Impossible de stocker le fichier.');
        }
        chmod($storedPath, 0600);

        $status = 'pending';
        $report = [
            'type' => $validated['type'],
            'rows' => [],
            'errors' => [],
            'duplicates' => [],
            'message' => null,
        ];

        try {
            if ($validated['type'] === 'csv') {
                $report = $this->previewCsv($storedPath);
                $status = 'validated';
            } else {
                $report['message'] = 'Image reçue. Une validation OCR explicite est nécessaire avant tout import.';
            }
        } catch (Throwable $e) {
            if (is_file($storedPath)) {
                unlink($storedPath);
            }
            throw $e;
        }

        try {
            $attachmentId = $this->db->insert(
                'INSERT INTO assistant_attachments
                    (conversation_id, utilisateur_id, nom_original, nom_stockage,
                     chemin_stockage, mime_type, extension, taille_octets,
                     empreinte_sha256, statut)
                 VALUES (:conversation_id, :user_id, :original, :stored, :path,
                         :mime, :extension, :size, :hash, :status)',
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'original' => $validated['original_name'],
                    'stored' => $storedName,
                    'path' => 'storage/assistant_uploads/' . $storedName,
                    'mime' => $validated['mime'],
                    'extension' => $validated['extension'],
                    'size' => $validated['size'],
                    'hash' => hash_file('sha256', $storedPath),
                    'status' => $status,
                ]
            );

            $importId = $this->db->insert(
                'INSERT INTO assistant_imports
                    (attachment_id, utilisateur_id, statut, lignes_total,
                     lignes_valides, lignes_ignorees, lignes_erreur, rapport_json)
                 VALUES (:attachment_id, :user_id, :status, :total, :valid, :ignored, :errors, :report)',
                [
                    'attachment_id' => $attachmentId,
                    'user_id' => $userId,
                    'status' => $validated['type'] === 'csv' ? 'preview' : 'failed',
                    'total' => count($report['rows']),
                    'valid' => count(array_filter($report['rows'], static fn (array $row): bool => $row['status'] === 'ready')),
                    'ignored' => count(array_filter($report['rows'], static fn (array $row): bool => $row['status'] === 'duplicate')),
                    'errors' => count($report['errors']),
                    'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]
            );
        } catch (Throwable $e) {
            if (is_file($storedPath)) {
                unlink($storedPath);
            }
            throw $e;
        }

        return [
            'import_id' => $importId,
            'attachment_id' => $attachmentId,
            'type' => $validated['type'],
            'report' => $report,
        ];
    }

    public function cancel(int $userId, int $importId): void
    {
        $import = $this->db->fetchOne(
            'SELECT ai.id, aa.id AS attachment_id, aa.chemin_stockage
             FROM assistant_imports ai
             INNER JOIN assistant_attachments aa ON aa.id = ai.attachment_id
             WHERE ai.id = :id AND ai.utilisateur_id = :user_id
               AND ai.statut = :status',
            ['id' => $importId, 'user_id' => $userId, 'status' => 'preview']
        );
        if (!$import) {
            throw new RuntimeException('Aperçu introuvable ou déjà traité.');
        }

        $this->db->delete(
            'DELETE FROM assistant_imports WHERE id = :id AND utilisateur_id = :user_id AND statut = :status',
            ['id' => $importId, 'user_id' => $userId, 'status' => 'preview']
        );
        $this->db->delete(
            'DELETE FROM assistant_attachments WHERE id = :id AND utilisateur_id = :user_id',
            ['id' => $import['attachment_id'], 'user_id' => $userId]
        );

        $path = ROOT_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $import['chemin_stockage']);
        if (is_file($path) && !unlink($path)) {
            error_log('Assistant: impossible de supprimer le fichier annulé ' . $path);
        }
    }

    /**
     * Confirme l'aperçu et importe uniquement les lignes revalidées.
     */
    public function confirm(int $userId, int $importId): array
    {
        $import = $this->db->fetchOne(
            'SELECT ai.*, aa.conversation_id, aa.chemin_stockage, aa.empreinte_sha256,
                    aa.statut AS attachment_status
             FROM assistant_imports ai
             INNER JOIN assistant_attachments aa ON aa.id = ai.attachment_id
             WHERE ai.id = :id AND ai.utilisateur_id = :user_id
               AND ai.statut = :status',
            ['id' => $importId, 'user_id' => $userId, 'status' => 'preview']
        );
        if (!$import) {
            throw new RuntimeException('Import introuvable, déjà traité ou non autorisé.');
        }
        if ($import['attachment_status'] !== 'validated' || !str_ends_with($import['chemin_stockage'], '.csv')) {
            throw new RuntimeException('Seuls les CSV validés peuvent être importés.');
        }

        $path = ROOT_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $import['chemin_stockage']);
        if (!is_file($path) || !hash_equals($import['empreinte_sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Le fichier a été modifié ou n’est plus disponible.');
        }

        $preview = $this->previewCsv($path);
        $readyRows = array_values(array_filter(
            $preview['rows'],
            static fn (array $row): bool => $row['status'] === 'ready'
        ));
        if ($readyRows === []) {
            throw new RuntimeException('Aucune ligne valide à importer.');
        }

        $this->db->beginTransaction();
        try {
            $imported = 0;
            $errors = $preview['errors'];
            foreach ($readyRows as $row) {
                if ($this->studentExists($row['data'])) {
                    $errors[] = ['ligne' => $row['line'], 'message' => 'Doublon détecté au moment de la confirmation.'];
                    continue;
                }
                $capacity = $this->db->fetchOne(
                    'SELECT c.capacite_max,
                            (SELECT COUNT(*) FROM inscriptions i
                             WHERE i.classe_id = c.id
                               AND i.annee_scolaire_id = :count_year_id
                               AND i.statut = :status) AS effectif
                     FROM classes c
                     WHERE c.id = :class_id AND c.annee_scolaire_id = :class_year_id
                       AND c.est_actif = 1
                     FOR UPDATE',
                    [
                        'class_id' => $row['data']['classe_id'],
                        'count_year_id' => $row['data']['annee_scolaire_id'],
                        'class_year_id' => $row['data']['annee_scolaire_id'],
                        'status' => 'Actif',
                    ]
                );
                if (!$capacity || (int) $capacity['effectif'] >= (int) $capacity['capacite_max']) {
                    $errors[] = ['ligne' => $row['line'], 'message' => 'Capacité maximale de la classe atteinte.'];
                    continue;
                }

                $nextId = (int) $this->db->fetchColumn('SELECT COALESCE(MAX(id), 0) + 1 FROM eleves FOR UPDATE');
                $studentId = $this->db->insert(
                    'INSERT INTO eleves
                        (matricule, nom, prenom, date_naissance, sexe,
                         date_inscription, est_actif)
                     VALUES (:matricule, :nom, :prenom, :date_naissance,
                             :sexe, CURDATE(), 1)',
                    [
                        'matricule' => 'EL-' . date('y') . '-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT),
                        'nom' => formatNom($row['data']['nom']),
                        'prenom' => formatNom($row['data']['prenom']),
                        'date_naissance' => $row['data']['date_naissance'],
                        'sexe' => $row['data']['sexe'],
                    ]
                );
                $this->db->query(
                    'INSERT INTO inscriptions
                        (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                     VALUES (:student_id, :class_id, :year_id, CURDATE(), :status)',
                    [
                        'student_id' => $studentId,
                        'class_id' => $row['data']['classe_id'],
                        'year_id' => $row['data']['annee_scolaire_id'],
                        'status' => 'Actif',
                    ]
                );
                $imported++;
            }

            $finalReport = [
                'rows' => $preview['rows'],
                'errors' => $errors,
                'imported' => $imported,
                'message' => 'Import confirmé. Les lignes invalides ou doublons n’ont pas été insérés.',
            ];
            $this->db->update(
                'UPDATE assistant_imports
                 SET statut = :status, lignes_valides = :valid, lignes_erreur = :errors,
                     rapport_json = :report, confirmed_at = NOW(), completed_at = NOW()
                 WHERE id = :id AND utilisateur_id = :user_id',
                [
                    'status' => 'completed',
                    'valid' => $imported,
                    'errors' => count($errors),
                    'report' => json_encode($finalReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'id' => $importId,
                    'user_id' => $userId,
                ]
            );
            $this->db->update(
                'UPDATE assistant_attachments SET statut = :status WHERE id = :id AND utilisateur_id = :user_id',
                ['status' => 'imported', 'id' => $import['attachment_id'], 'user_id' => $userId]
            );
            $this->db->commit();
            return ['imported' => $imported, 'errors' => $errors, 'report' => $finalReport];
        } catch (Throwable $e) {
            $this->db->rollback();
            $this->db->update(
                'UPDATE assistant_imports SET statut = :status, rapport_json = :report WHERE id = :id AND utilisateur_id = :user_id',
                [
                    'status' => 'failed',
                    'report' => json_encode(['message' => 'Échec transactionnel de l’import.'], JSON_UNESCAPED_UNICODE),
                    'id' => $importId,
                    'user_id' => $userId,
                ]
            );
            throw $e;
        }
    }

    private function conversationBelongsToUser(int $conversationId, int $userId): bool
    {
        return $this->db->exists(
            'SELECT id FROM assistant_conversations
             WHERE id = :conversation_id AND utilisateur_id = :user_id AND expires_at > NOW()',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );
    }

    /** @return array<string, mixed> */
    private function validateUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException('Fichier invalide ou transfert interrompu.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > ASSISTANT_UPLOAD_MAX_SIZE) {
            throw new InvalidArgumentException('Taille de fichier non autorisée.');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $types = [
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];
        if (!isset($types[$extension]) || !is_string($mime) || !in_array($mime, $types[$extension], true)) {
            throw new InvalidArgumentException('Type de fichier non autorisé ou incohérent.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false) {
            throw new InvalidArgumentException('Image invalide.');
        }
        return [
            'tmp_name' => $file['tmp_name'],
            'original_name' => basename((string) $file['name']),
            'extension' => $extension,
            'mime' => $mime,
            'size' => $size,
            'type' => $extension === 'csv' ? 'csv' : 'image',
        ];
    }

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>, duplicates: array<int, mixed>, message: ?string} */
    private function previewCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire le CSV.');
        }
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = is_string($firstLine) && str_contains($firstLine, ';') ? ';' : ',';
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            throw new InvalidArgumentException('CSV vide ou sans en-tête.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(static fn ($header): string => trim(mb_strtolower(str_replace([' ', '-'], '_', (string) $header))), $headers);
        $required = ['nom', 'prenom', 'date_naissance', 'sexe', 'classe'];
        $missing = array_diff($required, $headers);
        if ($missing !== []) {
            fclose($handle);
            throw new InvalidArgumentException('Colonnes obligatoires manquantes : ' . implode(', ', $missing));
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count($rows) >= ASSISTANT_IMPORT_MAX_ROWS) {
                $errors[] = ['ligne' => $line, 'message' => 'Nombre maximal de lignes dépassé.'];
                break;
            }
            if (count($data) !== count($headers)) {
                $errors[] = ['ligne' => $line, 'message' => 'Nombre de colonnes invalide.'];
                continue;
            }
            $raw = array_combine($headers, $data);
            $dataRow = $this->normalizeStudentRow($raw ?: []);
            if ($dataRow['error'] !== null) {
                $errors[] = ['ligne' => $line, 'message' => $dataRow['error']];
                continue;
            }
            $key = mb_strtolower($dataRow['data']['nom'] . '|' . $dataRow['data']['prenom'] . '|' . $dataRow['data']['date_naissance']);
            if (isset($seen[$key]) || $this->studentExists($dataRow['data'])) {
                $rows[] = ['line' => $line, 'status' => 'duplicate', 'data' => $dataRow['data']];
                $seen[$key] = true;
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['line' => $line, 'status' => 'ready', 'data' => $dataRow['data']];
        }
        fclose($handle);
        return ['rows' => $rows, 'errors' => $errors, 'duplicates' => [], 'message' => null];
    }

    /** @return array{data: array<string, mixed>, error: ?string} */
    private function normalizeStudentRow(array $row): array
    {
        $nom = trim((string) ($row['nom'] ?? ''));
        $prenom = trim((string) ($row['prenom'] ?? ''));
        $date = trim((string) ($row['date_naissance'] ?? ''));
        $sexe = strtoupper(trim((string) ($row['sexe'] ?? '')));
        $classe = trim((string) ($row['classe'] ?? ''));
        if ($nom === '' || $prenom === '' || $classe === '' || !in_array($sexe, ['M', 'F'], true)) {
            return ['data' => [], 'error' => 'Nom, prénom, classe et sexe M/F sont obligatoires.'];
        }
        $parsed = date_parse($date);
        if (!$parsed || !checkdate((int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year'])) {
            return ['data' => [], 'error' => 'Date de naissance invalide.'];
        }
        $dateSql = sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']);
        $yearId = $this->resolveYearId(trim((string) ($row['annee_scolaire'] ?? '')));
        $classId = $this->db->fetchColumn(
            'SELECT id FROM classes WHERE nom = :name AND annee_scolaire_id = :year_id AND est_actif = 1',
            ['name' => $classe, 'year_id' => $yearId]
        );
        if (!$yearId || !$classId) {
            return ['data' => [], 'error' => 'Classe ou année scolaire introuvable.'];
        }
        return ['data' => ['nom' => $nom, 'prenom' => $prenom, 'date_naissance' => $dateSql, 'sexe' => $sexe, 'classe_id' => (int) $classId, 'annee_scolaire_id' => (int) $yearId], 'error' => null];
    }

    private function resolveYearId(string $value): ?int
    {
        if ($value === '') {
            $id = $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
            return $id ? (int) $id : null;
        }
        $id = $this->db->fetchColumn('SELECT id FROM annees_scolaires WHERE libelle = :value OR libelle LIKE :prefix LIMIT 1', ['value' => $value, 'prefix' => $value . '%']);
        return $id ? (int) $id : null;
    }

    private function studentExists(array $data): bool
    {
        return $this->db->exists(
            'SELECT id FROM eleves WHERE LOWER(nom) = LOWER(:nom) AND LOWER(prenom) = LOWER(:prenom) AND date_naissance = :date_naissance',
            ['nom' => formatNom($data['nom']), 'prenom' => formatNom($data['prenom']), 'date_naissance' => $data['date_naissance']]
        );
    }
}
