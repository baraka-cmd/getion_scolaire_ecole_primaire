<?php

defined('ACCESS') or die('Accès interdit');

final class AssistantRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function purgeExpiredConversations(): int
    {
        $attachments = $this->db->fetchAll(
            'SELECT aa.chemin_stockage
             FROM assistant_attachments aa
             INNER JOIN assistant_conversations ac ON ac.id = aa.conversation_id
             WHERE ac.expires_at <= NOW()'
        );
        foreach ($attachments as $attachment) {
            $path = ROOT_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachment['chemin_stockage']);
            if (is_file($path) && !unlink($path)) {
                error_log('Assistant: impossible de supprimer la pièce jointe expirée ' . $path);
            }
        }

        return $this->db->delete(
            'DELETE FROM assistant_conversations WHERE expires_at <= NOW()'
        );
    }

    public function createConversation(int $userId): int
    {
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . ASSISTANT_HISTORY_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        return $this->db->insert(
            'INSERT INTO assistant_conversations (utilisateur_id, expires_at)
             VALUES (:user_id, :expires_at)',
            ['user_id' => $userId, 'expires_at' => $expiresAt]
        );
    }

    public function deleteConversation(int $conversationId, int $userId): void
    {
        $attachments = $this->db->fetchAll(
            'SELECT aa.chemin_stockage
             FROM assistant_attachments aa
             INNER JOIN assistant_conversations ac ON ac.id = aa.conversation_id
             WHERE aa.conversation_id = :conversation_id
               AND ac.utilisateur_id = :user_id',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );

        $this->db->delete(
            'DELETE FROM assistant_conversations
             WHERE id = :conversation_id AND utilisateur_id = :user_id',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );

        foreach ($attachments as $attachment) {
            $path = ROOT_PATH . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachment['chemin_stockage']);
            if (is_file($path) && !unlink($path)) {
                error_log('Assistant: impossible de supprimer la pièce jointe ' . $path);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    /** @return array{items: array<int, array<string, mixed>>, has_more: bool} */
    public function listConversations(int $userId, int $page = 1, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $items = $this->db->fetchAll(
            "SELECT id, titre, last_message_at, created_at, expires_at
             FROM assistant_conversations
             WHERE utilisateur_id = :user_id AND expires_at > NOW()
             ORDER BY COALESCE(last_message_at, created_at) DESC
             LIMIT {$limit} OFFSET {$offset}",
            ['user_id' => $userId]
        );

        return [
            'items' => $items,
            'has_more' => count($items) === $limit,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listMessages(int $userId, int $conversationId): array
    {
        return $this->db->fetchAll(
            'SELECT am.id, am.role, am.contenu, am.intention, am.metadata_json, am.created_at
             FROM assistant_messages am
             INNER JOIN assistant_conversations ac ON ac.id = am.conversation_id
             WHERE am.conversation_id = :conversation_id
               AND ac.utilisateur_id = :user_id AND ac.expires_at > NOW()
             ORDER BY am.created_at ASC, am.id ASC',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );
    }

    /** @return array<int, array{role: string, contenu: string}> */
    public function recentConversationContext(int $userId, int $conversationId, int $limit = 12): array
    {
        $limit = max(1, min($limit, 20));
        $rows = $this->db->fetchAll(
            "SELECT am.role, am.contenu
             FROM assistant_messages am
             INNER JOIN assistant_conversations ac ON ac.id = am.conversation_id
             WHERE am.conversation_id = :conversation_id
               AND ac.utilisateur_id = :user_id
               AND ac.expires_at > NOW()
             ORDER BY am.created_at DESC, am.id DESC
             LIMIT {$limit}",
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );
        return array_reverse($rows);
    }

    public function activeSchoolYearId(): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1'
        );
    }

    public function resolveSchoolYearId(?string $term): ?int
    {
        if ($term === null || trim($term) === '' || in_array(mb_strtolower(trim($term), 'UTF-8'), ['active', 'actuelle', 'courante'], true)) {
            return $this->activeSchoolYearId();
        }
        $row = $this->db->fetchOne(
            'SELECT id FROM annees_scolaires
             WHERE libelle = :term OR libelle LIKE :prefix
             ORDER BY est_active DESC, date_debut DESC LIMIT 1',
            ['term' => trim($term), 'prefix' => trim($term) . '%']
        );
        return $row ? (int) $row['id'] : null;
    }

    /** @return array<int, array{id: int, nom: string, niveau: ?string}> */
    public function listActiveClasses(): array
    {
        return $this->db->fetchAll(
            'SELECT id, nom, niveau
             FROM classes
             WHERE annee_scolaire_id = :year_id
             ORDER BY nom',
            ['year_id' => $this->activeSchoolYearId()]
        );
    }

    public function resolveClassId(?string $term): ?int
    {
        if ($term === null || trim($term) === '') {
            return null;
        }
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value), 'UTF-8');
            return strtr($value, [
                'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            ]);
        };
        $needle = preg_replace('/\s+/', ' ', $normalize($term)) ?? $normalize($term);
        $classes = $this->listActiveClasses();
        foreach ($classes as $class) {
            $haystack = $normalize($class['nom'] . ' ' . ($class['niveau'] ?? ''));
            if ($needle !== '' && (str_contains($haystack, $needle) || str_contains($needle, $haystack))) {
                return (int) $class['id'];
            }
            if (
                ($needle === 'premiere annee' || $needle === '1ere annee' || $needle === '1 annee')
                && preg_match('/(^|\D)1(\D|$)|premiere|premier/', $haystack)
            ) {
                return (int) $class['id'];
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listSubjects(?int $classId = null): array
    {
        $params = [];
        $classClause = '';
        if ($classId !== null) {
            $classClause = 'AND cm.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        return $this->db->fetchAll(
            "SELECT DISTINCT m.id, m.code, m.libelle, m.est_groupe
             FROM matieres m
             LEFT JOIN classe_matieres cm
               ON cm.matiere_id = m.id AND cm.est_active = 1
             WHERE m.est_actif = 1 {$classClause}
             ORDER BY m.est_groupe DESC, m.libelle",
            $params
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listParents(?int $classId = null): array
    {
        $params = [];
        $classClause = '';
        if ($classId !== null) {
            $classClause = 'AND i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        return $this->db->fetchAll(
            "SELECT DISTINCT p.nom, p.prenom, p.lien, p.telephone_principal, p.email,
                    e.nom AS eleve_nom, e.prenom AS eleve_prenom
             FROM parents_tuteurs p
             INNER JOIN eleve_parent ep ON ep.parent_id = p.id
             INNER JOIN eleves e ON e.id = ep.eleve_id AND e.est_actif = 1
             INNER JOIN inscriptions i
               ON i.eleve_id = e.id
              AND i.annee_scolaire_id = :year_id
              AND i.statut = 'Actif'
             {$classClause}
             ORDER BY p.nom, p.prenom",
            array_merge(['year_id' => $this->activeSchoolYearId()], $params)
        );
    }

    /** @return array<string, mixed> */
    public function paymentSummary(?int $classId = null, ?string $status = null, ?int $yearId = null): array
    {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        $classClause = '';
        if ($classId !== null) {
            $classClause = 'AND i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        if ($status !== null && in_array($status, ['Payé', 'Partiel', 'Impayé'], true)) {
            $classClause .= ' AND p.statut = :payment_status';
            $params['payment_status'] = $status;
        }
        $summary = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT p.eleve_id) AS eleves,
                   COALESCE(SUM(p.montant_total), 0) AS attendu,
                   COALESCE(SUM(p.montant_paye), 0) AS paye,
                   COALESCE(SUM(p.montant_total - p.montant_paye), 0) AS reste
            FROM paiements p
            INNER JOIN inscriptions i
              ON i.eleve_id = p.eleve_id
             AND i.annee_scolaire_id = :year_id
             AND i.statut = 'Actif'
            WHERE 1 = 1 {$classClause}",
            $params
        ) ?: [];
        $statuses = $this->db->fetchAll(
            "SELECT p.statut, COUNT(*) AS total
            FROM paiements p
            INNER JOIN inscriptions i
              ON i.eleve_id = p.eleve_id
             AND i.annee_scolaire_id = :year_id
             AND i.statut = 'Actif'
            WHERE 1 = 1 {$classClause}
            GROUP BY p.statut
            ORDER BY p.statut",
            $params
        );
        $summary['statuts'] = $statuses;
        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    public function listUnpaidStudents(?int $classId = null, ?int $yearId = null, int $limit = 100): array
    {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        $filters = ['i.annee_scolaire_id = :year_id', 'i.statut = \'Actif\'', 'p.montant_total > p.montant_paye'];
        if ($classId !== null) {
            $filters[] = 'i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAll(
            "SELECT e.nom, e.prenom, c.nom AS classe,
                    COALESCE(SUM(p.montant_total - p.montant_paye), 0) AS reste
             FROM paiements p
             INNER JOIN eleves e ON e.id = p.eleve_id AND e.est_actif = 1
             INNER JOIN inscriptions i ON i.eleve_id = e.id
             LEFT JOIN classes c ON c.id = i.classe_id
             WHERE " . implode(' AND ', $filters) . "
             GROUP BY e.id, e.nom, e.prenom, c.nom
             ORDER BY reste DESC, e.nom, e.prenom
             LIMIT {$limit}",
            $params
        );
    }

    /** @return array<string, mixed> */
    public function absenceSummary(?int $classId = null, ?string $startDate = null, ?string $endDate = null, ?int $yearId = null): array
    {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        $filters = [];
        if ($classId !== null) {
            $filters[] = 'i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        if ($startDate !== null) {
            $filters[] = 'a.date_absence >= :start_date';
            $params['start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $filters[] = 'a.date_absence <= :end_date';
            $params['end_date'] = $endDate;
        }
        $where = $filters === [] ? '' : 'AND ' . implode(' AND ', $filters);
        $summary = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN a.est_justifiee = 1 THEN 1 ELSE 0 END), 0) AS justifiees,
                   COALESCE(SUM(CASE WHEN a.est_justifiee = 0 THEN 1 ELSE 0 END), 0) AS non_justifiees
            FROM absences a
            INNER JOIN inscriptions i
              ON i.eleve_id = a.eleve_id
             AND i.annee_scolaire_id = :year_id
             AND i.statut = 'Actif'
            WHERE a.type_absence = 'Absence' {$where}",
            $params
        ) ?: [];
        $summary['top_absents'] = $this->db->fetchAll(
            "SELECT e.nom, e.prenom, c.nom AS classe, COUNT(a.id) AS total
            FROM absences a
            INNER JOIN eleves e ON e.id = a.eleve_id
            INNER JOIN inscriptions i
              ON i.eleve_id = e.id
             AND i.annee_scolaire_id = :year_id
             AND i.statut = 'Actif'
            LEFT JOIN classes c ON c.id = i.classe_id
            WHERE a.type_absence = 'Absence' {$where}
            GROUP BY e.id, e.nom, e.prenom, c.nom
            ORDER BY total DESC, e.nom
            LIMIT 10",
            $params
        );
        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    public function listAbsences(
        ?int $classId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null,
        ?int $yearId = null,
        int $limit = 100
    ): array {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        $filters = ['i.annee_scolaire_id = :year_id', 'i.statut = \'Actif\'', 'a.type_absence = \'Absence\''];
        if ($classId !== null) {
            $filters[] = 'i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        if ($startDate !== null) {
            $filters[] = 'a.date_absence >= :start_date';
            $params['start_date'] = $startDate;
        }
        if ($endDate !== null) {
            $filters[] = 'a.date_absence <= :end_date';
            $params['end_date'] = $endDate;
        }
        if ($status !== null && in_array($status, ['Justifiée', 'Non justifiée'], true)) {
            $filters[] = $status === 'Justifiée' ? 'a.est_justifiee = 1' : 'a.est_justifiee = 0';
        }
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAll(
            "SELECT e.nom, e.prenom, c.nom AS classe, a.date_absence,
                    a.est_justifiee, a.motif
             FROM absences a
             INNER JOIN eleves e ON e.id = a.eleve_id AND e.est_actif = 1
             INNER JOIN inscriptions i ON i.eleve_id = e.id
             LEFT JOIN classes c ON c.id = i.classe_id
             WHERE " . implode(' AND ', $filters) . "
             ORDER BY a.date_absence DESC, e.nom, e.prenom
             LIMIT {$limit}",
            $params
        );
    }

    /** @return array<string, mixed> */
    public function resultSummary(?int $classId = null, ?int $trimesterId = null, ?string $decision = null, bool $topOnly = false, ?int $yearId = null): array
    {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        $filters = ['t.annee_scolaire_id = :year_id'];
        if ($classId !== null) {
            $filters[] = 'r.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        if ($trimesterId !== null) {
            $filters[] = 'r.trimestre_id = :trimester_id';
            $params['trimester_id'] = $trimesterId;
        }
        if ($decision === '__failure__') {
            $filters[] = "r.decision IN ('Ajourné', 'Redouble', 'Exclu')";
        } elseif ($decision !== null) {
            $filters[] = 'r.decision = :decision';
            $params['decision'] = $decision;
        }
        $where = implode(' AND ', $filters);
        $summary = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN r.decision = 'Passe' THEN 1 ELSE 0 END), 0) AS reussis,
                   COALESCE(SUM(CASE WHEN r.decision IN ('Ajourné', 'Redouble', 'Exclu') THEN 1 ELSE 0 END), 0) AS echoues,
                   ROUND(COALESCE(AVG(r.moyenne_generale), 0), 2) AS moyenne
            FROM resultats_trimestriels r
            INNER JOIN trimestres t ON t.id = r.trimestre_id
            WHERE {$where}",
            $params
        ) ?: [];
        $order = $topOnly
            ? 'ORDER BY r.moyenne_generale DESC, r.rang IS NULL, r.rang, e.nom, e.prenom'
            : 'ORDER BY r.rang IS NULL, r.rang, e.nom, e.prenom';
        $limit = $topOnly ? 3 : 200;
        $summary['items'] = $this->db->fetchAll(
            "SELECT e.nom, e.prenom, c.nom AS classe, r.moyenne_generale, r.decision, r.rang
            FROM resultats_trimestriels r
            INNER JOIN trimestres t ON t.id = r.trimestre_id
            INNER JOIN eleves e ON e.id = r.eleve_id
            LEFT JOIN classes c ON c.id = r.classe_id
            WHERE {$where}
            {$order}
            LIMIT {$limit}",
            $params
        );
        return $summary;
    }

    public function resolveActiveTrimesterId(?string $term = null): ?int
    {
        return $this->resolveTrimesterId($term, $this->activeSchoolYearId());
    }

    public function resolveTrimesterId(?string $term, ?int $yearId = null): ?int
    {
        $params = ['year_id' => $yearId ?: $this->activeSchoolYearId()];
        if ($term !== null && trim($term) !== '') {
            $term = trim($term);
            $normalized = mb_strtolower($term, 'UTF-8');
            $normalized = strtr($normalized, [
               'à' => 'a', 'â' => 'a', 'ä' => 'a',
               'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
               'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
               'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            ]);
            $number = preg_replace('/\D+/', '', $normalized) ?? '';
            if ($number === '') {
               $number = match (true) {
                   str_contains($normalized, 'premier'), str_contains($normalized, 'premiere') => '1',
                   str_contains($normalized, 'deuxieme'), str_contains($normalized, 'deuxième') => '2',
                   str_contains($normalized, 'troisieme'), str_contains($normalized, 'troisième') => '3',
                   default => '',
               };
            }
            $row = $this->db->fetchOne(
               'SELECT id FROM trimestres
               WHERE annee_scolaire_id = :year_id
                 AND (libelle LIKE :term OR CAST(numero AS CHAR) = :number
                      OR LOWER(libelle) LIKE :ordinal_term)
               ORDER BY numero LIMIT 1',
               [
                  'year_id' => $params['year_id'],
                  'term' => '%' . $term . '%',
                  'number' => $number,
                  'ordinal_term' => '%' . $normalized . '%',
               ]
            );
            return $row ? (int) $row['id'] : null;
        }
        $row = $this->db->fetchOne(
            'SELECT id FROM trimestres
            WHERE annee_scolaire_id = :year_id AND est_actif = 1
            ORDER BY numero LIMIT 1',
            $params
        );
        return $row ? (int) $row['id'] : null;
    }

    public function conversationBelongsToUser(int $conversationId, int $userId): bool
    {
        return $this->db->exists(
            'SELECT id FROM assistant_conversations
             WHERE id = :conversation_id AND utilisateur_id = :user_id AND expires_at > NOW()',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );
    }

    public function getConversationTitle(int $conversationId, int $userId): string
    {
        $conversation = $this->db->fetchOne(
            'SELECT titre FROM assistant_conversations
             WHERE id = :conversation_id AND utilisateur_id = :user_id',
            ['conversation_id' => $conversationId, 'user_id' => $userId]
        );

        return (string) ($conversation['titre'] ?? 'Nouvelle conversation');
    }

    public function countActiveStudents(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM inscriptions
             WHERE annee_scolaire_id = (
                 SELECT annee_scolaire_active_id
                 FROM parametres_ecole
                 LIMIT 1
             )
             AND statut = 'Actif'"
        );
    }

    /** @return array{garcons: int, filles: int, total: int} */
    public function countActiveStudentsByGender(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT e.sexe, COUNT(*) AS total
             FROM eleves e
             INNER JOIN inscriptions i ON i.eleve_id = e.id
             WHERE i.annee_scolaire_id = (
                 SELECT annee_scolaire_active_id
                 FROM parametres_ecole
                 LIMIT 1
             )
             AND i.statut = 'Actif'
             AND e.est_actif = 1
             GROUP BY e.sexe"
        );

        $counts = ['garcons' => 0, 'filles' => 0];
        foreach ($rows as $row) {
            if (($row['sexe'] ?? '') === 'M') {
                $counts['garcons'] = (int) $row['total'];
            } elseif (($row['sexe'] ?? '') === 'F') {
                $counts['filles'] = (int) $row['total'];
            }
        }

        $counts['total'] = $counts['garcons'] + $counts['filles'];
        return $counts;
    }

    public function countActiveClasses(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM classes
             WHERE annee_scolaire_id = (
                 SELECT annee_scolaire_active_id
                 FROM parametres_ecole
                 LIMIT 1
             )"
        );
    }

    /** @return array<int, array{nom: string, prenom: string, classe: ?string}> */
    public function listActiveStudents(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        return $this->db->fetchAll(
            "SELECT e.nom, e.prenom, c.nom AS classe
             FROM eleves e
             INNER JOIN inscriptions i ON i.eleve_id = e.id
             LEFT JOIN classes c ON c.id = i.classe_id
             WHERE i.annee_scolaire_id = (
                 SELECT annee_scolaire_active_id
                 FROM parametres_ecole
                 LIMIT 1
             )
             AND i.statut = 'Actif'
             AND e.est_actif = 1
             ORDER BY e.nom ASC, e.prenom ASC
             LIMIT {$limit}"
        );
    }

    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?string $intent = null,
        ?array $metadata = null
    ): int {
        $allowedRoles = ['user', 'assistant', 'system', 'tool'];
        if (!in_array($role, $allowedRoles, true)) {
            throw new InvalidArgumentException('Rôle de message invalide.');
        }

        $metadataJson = $metadata === null
            ? null
            : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $messageId = $this->db->insert(
            'INSERT INTO assistant_messages
                (conversation_id, role, contenu, intention, metadata_json)
             VALUES (:conversation_id, :role, :content, :intent, :metadata)',
            [
                'conversation_id' => $conversationId,
                'role' => $role,
                'content' => $content,
                'intent' => $intent,
                'metadata' => $metadataJson,
            ]
        );

        if ($role === 'user') {
            $title = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
            $title = mb_substr($title, 0, 157, 'UTF-8');
            if (mb_strlen($content, 'UTF-8') > 157) {
                $title .= '...';
            }
            $this->db->update(
                'UPDATE assistant_conversations
                 SET titre = :title
                 WHERE id = :conversation_id
                   AND titre = :default_title',
                [
                    'title' => $title !== '' ? $title : 'Nouvelle conversation',
                    'conversation_id' => $conversationId,
                    'default_title' => 'Nouvelle conversation',
                ]
            );
        }

        $this->db->update(
            'UPDATE assistant_conversations
             SET last_message_at = NOW(), updated_at = NOW()
             WHERE id = :conversation_id',
            ['conversation_id' => $conversationId]
        );

        return $messageId;
    }

    public function consumeRateLimit(int $userId): bool
    {
        $now = new DateTimeImmutable();
        $startedAt = $now->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->query(
                'INSERT IGNORE INTO assistant_rate_limits
                    (utilisateur_id, window_started_at, request_count)
                 VALUES (:user_id, :started_at, 0)',
                ['user_id' => $userId, 'started_at' => $startedAt]
            );

            $limit = $this->db->fetchOne(
                'SELECT window_started_at, request_count
                 FROM assistant_rate_limits
                 WHERE utilisateur_id = :user_id
                 FOR UPDATE',
                ['user_id' => $userId]
            );

            if ($limit === null) {
                throw new RuntimeException('Impossible de lire la limite de requêtes.');
            }

            $windowStartedAt = new DateTimeImmutable($limit['window_started_at']);
            if (($now->getTimestamp() - $windowStartedAt->getTimestamp()) >= ASSISTANT_RATE_LIMIT_WINDOW) {
                $this->db->update(
                    'UPDATE assistant_rate_limits
                     SET window_started_at = :started_at, request_count = 1
                     WHERE utilisateur_id = :user_id',
                    ['user_id' => $userId, 'started_at' => $startedAt]
                );
                $this->db->commit();
                return true;
            }

            if ((int) $limit['request_count'] >= ASSISTANT_RATE_LIMIT_REQUESTS) {
                $this->db->commit();
                return false;
            }

            $this->db->update(
                'UPDATE assistant_rate_limits
                 SET request_count = request_count + 1
                 WHERE utilisateur_id = :user_id',
                ['user_id' => $userId]
            );
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function releaseRateLimit(int $userId): void
    {
        $this->db->query(
            'UPDATE assistant_rate_limits
             SET request_count = GREATEST(request_count - 1, 0)
             WHERE utilisateur_id = :user_id
               AND window_started_at > :window_started_at',
            [
                'user_id' => $userId,
                'window_started_at' => (new DateTimeImmutable())
                    ->modify('-' . ASSISTANT_RATE_LIMIT_WINDOW . ' seconds')
                    ->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * @return array{used: int, limit: int, remaining: int}
     */
    public function rateLimitStatus(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT window_started_at, request_count
             FROM assistant_rate_limits
             WHERE utilisateur_id = :user_id',
            ['user_id' => $userId]
        );
        if ($row === null) {
            return [
                'used' => 0,
                'limit' => ASSISTANT_RATE_LIMIT_REQUESTS,
                'remaining' => ASSISTANT_RATE_LIMIT_REQUESTS,
            ];
        }
        $startedAt = new DateTimeImmutable($row['window_started_at']);
        $now = new DateTimeImmutable();
        $used = ($now->getTimestamp() - $startedAt->getTimestamp()) >= ASSISTANT_RATE_LIMIT_WINDOW
            ? 0
            : (int) $row['request_count'];
        return [
            'used' => $used,
            'limit' => ASSISTANT_RATE_LIMIT_REQUESTS,
            'remaining' => max(0, ASSISTANT_RATE_LIMIT_REQUESTS - $used),
        ];
    }
}
