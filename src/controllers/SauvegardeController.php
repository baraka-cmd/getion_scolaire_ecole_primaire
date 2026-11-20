<?php
/**
 * Fichier : src/controllers/SauvegardeController.php
 * Rôle    : Gestion des sauvegardes de la base de données
 * Système : École primaire congolaise — Administration
 * Version : 1.1 — Adaptée à la structure réelle de la table
 */

defined('ACCESS') or die('Accès interdit');

class SauvegardeController
{
    private Database $db;
    private string $backupDir;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->backupDir = STORAGE_PATH . 'backups' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Impossible de créer le dossier de sauvegardes.');
        }

        $htaccess = $this->backupDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('sauvegardes.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            case 'getListe':
                $this->getListe();
                break;
            case 'sauvegarder':
                $this->sauvegarder();
                break;
            case 'supprimer':
                $this->supprimer();
                break;
            case 'restaurer':
                $this->restaurer();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // 1. LISTE DES SAUVEGARDES
    // ============================================================

    private function getListe(): void
    {
        $page    = max(1, (int) post('page', 1));
        $parPage = ITEMS_PER_PAGE;

        // Récupérer depuis la base de données
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM sauvegardes");

        $offset = ($page - 1) * $parPage;

        $sauvegardes = $this->db->fetchAll(
            "SELECT s.*, 
                    DATE_FORMAT(s.date_creation, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(s.date_creation, '%H:%i') AS heure_format,
                    CONCAT(u.prenom, ' ', u.nom) AS auteur_nom,
                    CASE 
                        WHEN s.taille_fichier > 1048576 THEN CONCAT(ROUND(s.taille_fichier / 1048576, 1), ' Mo')
                        WHEN s.taille_fichier > 1024 THEN CONCAT(ROUND(s.taille_fichier / 1024, 1), ' Ko')
                        ELSE CONCAT(s.taille_fichier, ' o')
                    END AS taille_formatee
             FROM sauvegardes s
             LEFT JOIN utilisateurs u ON s.cree_par = u.id
             ORDER BY s.date_creation DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => $parPage, 'offset' => $offset]
        );

        // Vérifier que les fichiers existent encore sur le disque
        foreach ($sauvegardes as &$s) {
            $path = $this->backupPath((string) $s['nom_fichier']);
            $s['fichier_existe'] = $path !== null && is_file($path);
        }

        jsonResponse(true, 'Liste récupérée.', [
            'sauvegardes' => $sauvegardes,
            'total'       => $total,
            'page'        => $page,
            'pages'       => ceil($total / $parPage)
        ]);
    }

    // ============================================================
    // 2. SAUVEGARDER LA BASE DE DONNÉES
    // ============================================================

    private function sauvegarder(): void
    {
        requirePermission('sauvegardes.creer');
        global $security;
        $security->validateCsrf();

        $userId = $_SESSION['utilisateur_id'];
        $debutExecution = microtime(true);
        $cheminFichier = null;

        try {
            $nomFichier = 'backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.sql';
            $cheminFichier = $this->backupDir . $nomFichier;
            $tailleFichier = $this->createBackupFile($cheminFichier);

            $dureeExecution = round(microtime(true) - $debutExecution, 2);

            // Enregistrer dans la base de données
            $sauvegardeId = $this->db->insert(
                "INSERT INTO sauvegardes (nom_fichier, chemin_fichier, taille_fichier, type_sauvegarde, statut, cree_par, date_creation, duree_execution)
                 VALUES (:nom, :chemin, :taille, 'Manuelle', 'Succès', :uid, NOW(), :duree)",
                [
                    'nom'    => $nomFichier,
                    'chemin' => 'storage/backups/' . $nomFichier,
                    'taille' => $tailleFichier,
                    'uid'    => $userId,
                    'duree'  => $dureeExecution
                ]
            );

            // Journaliser l'action
            logActivity('Sauvegarde', 'sauvegardes', $sauvegardeId, "Sauvegarde créée : {$nomFichier} ({$tailleFichier} octets)");

            // Réponse
            $tailleFormatee = $tailleFichier > 1048576 
                ? round($tailleFichier / 1048576, 1) . ' Mo' 
                : round($tailleFichier / 1024, 1) . ' Ko';

            jsonResponse(true, 'Sauvegarde effectuée avec succès.', [
                'id'       => $sauvegardeId,
                'fichier'  => $nomFichier,
                'taille'   => $tailleFormatee,
                'duree'    => $dureeExecution . ' secondes'
            ]);

        } catch (Throwable $e) {
            if ($cheminFichier && is_file($cheminFichier)) {
                unlink($cheminFichier);
            }
            try {
                $this->db->insert(
                    "INSERT INTO sauvegardes (nom_fichier, chemin_fichier, taille_fichier, type_sauvegarde, statut, message_erreur, cree_par, date_creation)
                     VALUES (:nom, :chemin, 0, 'Manuelle', 'Échec', :erreur, :uid, NOW())",
                    [
                        'nom'    => $nomFichier ?? 'backup_erreur.sql',
                        'chemin' => '',
                        'erreur' => $e->getMessage(),
                        'uid'    => $userId
                    ]
                );
            } catch (Throwable $loggingError) {
                error_log('Impossible d’enregistrer l’échec de sauvegarde: ' . $loggingError->getMessage());
            }

            error_log('Erreur sauvegarde BDD: ' . $e->getMessage());
            jsonResponse(false, 'La sauvegarde a échoué. Aucune sauvegarde incomplète n’a été conservée.', [], 500);
        }
    }

    // ============================================================
    // 3. SUPPRIMER UNE SAUVEGARDE
    // ============================================================

    private function supprimer(): void
    {
        requirePermission('sauvegardes.supprimer');
        global $security;
        $security->validateCsrf();

        $sauvegardeId = (int) post('sauvegarde_id', 0);
        if ($sauvegardeId <= 0) {
            jsonResponse(false, 'ID sauvegarde invalide.', [], 400);
        }

        // Récupérer les infos avant suppression
        $sauvegarde = $this->db->fetchOne(
            "SELECT id, nom_fichier, chemin_fichier FROM sauvegardes WHERE id = :id",
            ['id' => $sauvegardeId]
        );

        if (!$sauvegarde) {
            jsonResponse(false, 'Sauvegarde introuvable.', [], 404);
        }

        // Supprimer le fichier physique du disque
        $cheminFichier = $this->backupPath($sauvegarde['nom_fichier']);
        if ($cheminFichier === null) {
            jsonResponse(false, 'Nom de fichier de sauvegarde invalide.', [], 400);
        }
        if (file_exists($cheminFichier)) {
            if (!unlink($cheminFichier)) {
                jsonResponse(false, 'Impossible de supprimer le fichier physique.', [], 500);
            }
        }

        // Supprimer l'enregistrement en base
        $this->db->delete("DELETE FROM sauvegardes WHERE id = :id", ['id' => $sauvegardeId]);

        // Journaliser
        logActivity('Suppression', 'sauvegardes', $sauvegardeId, "Sauvegarde supprimée : {$sauvegarde['nom_fichier']}");

        jsonResponse(true, 'Sauvegarde supprimée avec succès.');
    }

    // ============================================================
    // 4. RESTAURER UNE SAUVEGARDE
    // ============================================================

    private function restaurer(): void
    {
        requirePermission('sauvegardes.restaurer');
        global $security;
        $security->validateCsrf();

        $sauvegardeId = (int) post('sauvegarde_id', 0);
        if ($sauvegardeId <= 0) {
            jsonResponse(false, 'ID sauvegarde invalide.', [], 400);
        }

        // Récupérer les infos de la sauvegarde à restaurer
        $sauvegarde = $this->db->fetchOne(
            "SELECT id, nom_fichier, chemin_fichier, taille_fichier FROM sauvegardes WHERE id = :id",
            ['id' => $sauvegardeId]
        );

        if (!$sauvegarde) {
            jsonResponse(false, 'Sauvegarde introuvable.', [], 404);
        }

        $cheminFichier = $this->backupPath($sauvegarde['nom_fichier']);
        if ($cheminFichier === null || !is_file($cheminFichier)) {
            jsonResponse(false, 'Le fichier de sauvegarde n\'existe plus sur le serveur.', [], 404);
        }

        $nomBackupSecurite = null;
        $cheminBackupSecurite = null;
        try {
            $tablesSauvegarde = $this->validateDump($cheminFichier);
            $this->validateRestoreCompatibility($tablesSauvegarde);

            $nomBackupSecurite = 'backup_avant_restauration_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.sql';
            $cheminBackupSecurite = $this->backupDir . $nomBackupSecurite;
            $tailleBackupSecurite = $this->createBackupFile($cheminBackupSecurite);

            $this->restoreDump($cheminFichier, $tablesSauvegarde, [
                'nom' => $nomBackupSecurite,
                'chemin' => 'storage/backups/' . $nomBackupSecurite,
                'taille' => $tailleBackupSecurite,
                'uid' => (int) $_SESSION['utilisateur_id']
            ]);

            logActivity(
                'Restauration',
                'sauvegardes',
                $sauvegardeId,
                "Base restaurée depuis {$sauvegarde['nom_fichier']}. Sauvegarde de sécurité : {$nomBackupSecurite}"
            );

            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_unset();
                session_destroy();
            }

            jsonResponse(true, 'Base restaurée. Une sauvegarde de sécurité a été créée; reconnectez-vous pour continuer.', [
                'backup_securite' => $nomBackupSecurite
            ]);

        } catch (Throwable $e) {
            error_log('Erreur restauration BDD: ' . $e->getMessage());
            if ($cheminBackupSecurite && is_file($cheminBackupSecurite)) {
                try {
                    $this->enregistrerSauvegardeAutomatique(
                        $nomBackupSecurite,
                        'storage/backups/' . $nomBackupSecurite,
                        (int) filesize($cheminBackupSecurite),
                        (int) ($_SESSION['utilisateur_id'] ?? 0)
                    );
                } catch (Throwable $loggingError) {
                    error_log('Impossible d’enregistrer la sauvegarde de sécurité: ' . $loggingError->getMessage());
                }
            }
            jsonResponse(false, 'La restauration a échoué. Vérifiez la compatibilité du fichier et réessayez.', [], 500);
        }
    }

    private function createBackupFile(string $destination): int
    {
        $handle = fopen($destination, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }

        try {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->beginTransaction();
            $this->writeDump($handle);
            $this->db->commit();
            fflush($handle);
        } catch (Throwable $e) {
            $this->db->rollback();
            fclose($handle);
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $e;
        }

        fclose($handle);
        $size = filesize($destination);
        if ($size === false || $size === 0) {
            unlink($destination);
            throw new RuntimeException('Le fichier de sauvegarde généré est vide.');
        }

        return (int) $size;
    }

    private function writeDump($handle): void
    {
        $this->writeLine($handle, "-- Sauvegarde Gestion Scolaire\n-- Date : " . date('Y-m-d H:i:s') . "\n");
        $this->writeLine($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");

        foreach ($this->getBaseTableNames() as $tableName) {
            $quotedTable = $this->quoteIdentifier($tableName);
            $createTable = $this->db->fetchOne("SHOW CREATE TABLE {$quotedTable}");
            if (!$createTable || empty($createTable['Create Table'])) {
                throw new RuntimeException("Structure manquante pour la table {$tableName}.");
            }

            $this->writeLine($handle, "\n-- Table : {$tableName}\n");
            $this->writeLine($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
            $this->writeLine($handle, $createTable['Create Table'] . ";\n");

            $rows = $this->db->query("SELECT * FROM {$quotedTable}");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map([$this, 'quoteIdentifier'], array_keys($row));
                $values = array_map(function ($value) {
                    return $value === null ? 'NULL' : $this->db->getPdo()->quote((string) $value);
                }, array_values($row));
                $this->writeLine(
                    $handle,
                    'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
                );
            }
        }

        $this->writeLine($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    }

    private function writeLine($handle, string $text): void
    {
        $length = strlen($text);
        if (fwrite($handle, $text) !== $length) {
            throw new RuntimeException('Écriture incomplète du fichier de sauvegarde.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function getBaseTableNames(): array
    {
        $rows = $this->db->fetchAll('SHOW FULL TABLES');
        $tables = [];
        foreach ($rows as $row) {
            $values = array_values($row);
            if (isset($values[0], $values[1]) && strtoupper((string) $values[1]) === 'BASE TABLE') {
                $tables[] = (string) $values[0];
            }
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    private function validateDump(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 128 * 1024 * 1024) {
            throw new RuntimeException('Taille de sauvegarde invalide ou supérieure à la limite.');
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Le fichier de sauvegarde est vide ou illisible.');
        }

        $tables = [];
        $hasCreateStatement = false;
        foreach ($this->splitSqlStatements($sql) as $statement) {
            $content = $this->removeLeadingComments($statement);
            if ($content === '') {
                continue;
            }

            if (preg_match('/^\/\*!/s', ltrim($content))) {
                continue;
            }
            if (preg_match('/^SET\s+/i', $content) || preg_match('/^(START\s+TRANSACTION|COMMIT|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $content)) {
                continue;
            }
            if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`((?:``|[^`])+)`/i', $content, $matches)) {
                $tables[] = str_replace('``', '`', $matches[1]);
                continue;
            }
            if (preg_match('/^CREATE\s+TABLE\s+`((?:``|[^`])+)`/i', $content, $matches)) {
                $tables[] = str_replace('``', '`', $matches[1]);
                $hasCreateStatement = true;
                continue;
            }
            if (preg_match('/^INSERT\s+INTO\s+`((?:``|[^`])+)`/i', $content, $matches)) {
                $tables[] = str_replace('``', '`', $matches[1]);
                continue;
            }

            throw new RuntimeException('Le fichier contient une instruction non prise en charge.');
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        if (!$hasCreateStatement || !$tables) {
            throw new RuntimeException('Le fichier ne contient pas une sauvegarde complète reconnue.');
        }
        return $tables;
    }

    private function validateRestoreCompatibility(array $dumpTables): void
    {
        $currentTables = $this->getBaseTableNames();
        if ($dumpTables !== $currentTables) {
            throw new RuntimeException('Le schéma de la sauvegarde ne correspond pas à celui de la base actuelle.');
        }

        $statusRows = $this->db->fetchAll('SHOW TABLE STATUS');
        foreach ($statusRows as $table) {
            if (isset($table['Name'], $table['Engine']) && in_array($table['Name'], $currentTables, true)
                && strcasecmp((string) $table['Engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('La restauration transactionnelle nécessite des tables InnoDB.');
            }
        }
    }

    private function restoreDump(string $path, array $tables, array $securityBackup): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Impossible de lire la sauvegarde.');
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->beginTransaction();
            foreach (array_reverse($tables) as $tableName) {
                $this->db->query('DELETE FROM ' . $this->quoteIdentifier($tableName));
            }

            foreach ($this->splitSqlStatements($sql) as $statement) {
                $content = $this->removeLeadingComments($statement);
                if (preg_match('/^INSERT\s+INTO\s+`/i', $content)) {
                    $this->db->query($content);
                }
            }

            $userExists = $this->db->fetchColumn(
                'SELECT id FROM utilisateurs WHERE id = :id',
                ['id' => $securityBackup['uid']]
            );
            $this->db->insert(
                "INSERT INTO sauvegardes (nom_fichier, chemin_fichier, taille_fichier, type_sauvegarde, statut, cree_par, date_creation)
                 VALUES (:nom, :chemin, :taille, 'Automatique', 'Succès', :uid, NOW())",
                [
                    'nom' => $securityBackup['nom'],
                    'chemin' => $securityBackup['chemin'],
                    'taille' => $securityBackup['taille'],
                    'uid' => $userExists ? $securityBackup['uid'] : null
                ]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function splitSqlStatements(string $sql): Generator
    {
        $statement = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $statement .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $statement .= $sql[++$i];
                } elseif ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $statement .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $statement .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($statement) !== '') {
                    yield trim($statement);
                }
                $statement = '';
                continue;
            }
            $statement .= $char;
        }

        if (trim($statement) !== '') {
            yield trim($statement);
        }
    }

    private function removeLeadingComments(string $statement): string
    {
        do {
            $before = $statement;
            $statement = preg_replace('/^\s*(?:(?:--|#)[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)/s', '', $statement);
        } while ($statement !== $before);
        return trim($statement);
    }

    private function backupPath(string $filename): ?string
    {
        if (!preg_match('/^backup_[A-Za-z0-9._-]+\.sql$/', $filename)) {
            return null;
        }
        return $this->backupDir . $filename;
    }

    private function enregistrerSauvegardeAutomatique(string $nom, string $chemin, int $taille, int $userId): void
    {
        $userExists = $userId > 0
            ? $this->db->fetchColumn('SELECT id FROM utilisateurs WHERE id = :id', ['id' => $userId])
            : null;
        $this->db->insert(
            "INSERT INTO sauvegardes (nom_fichier, chemin_fichier, taille_fichier, type_sauvegarde, statut, cree_par, date_creation)
             VALUES (:nom, :chemin, :taille, 'Automatique', 'Succès', :uid, NOW())",
            [
                'nom' => $nom,
                'chemin' => $chemin,
                'taille' => $taille,
                'uid' => $userExists ? $userId : null
            ]
        );
    }

    // ============================================================
    // 7. TÉLÉCHARGER UNE SAUVEGARDE
    // ============================================================

    public function telecharger(int $sauvegardeId): void
    {
        requirePermission('sauvegardes.consulter');

        // Récupérer les infos
        $sauvegarde = $this->db->fetchOne(
            "SELECT id, nom_fichier, chemin_fichier FROM sauvegardes WHERE id = :id",
            ['id' => $sauvegardeId]
        );

        if (!$sauvegarde) {
            setFlash('error', 'Sauvegarde introuvable.');
            redirect(BASE_URL . 'sauvegardes');
        }

        // Vérifier l'existence du fichier
        $cheminFichier = $this->backupPath((string) $sauvegarde['nom_fichier']);

        if ($cheminFichier === null || !is_file($cheminFichier)) {
            setFlash('error', 'Le fichier de sauvegarde n\'existe plus sur le serveur.');
            redirect(BASE_URL . 'sauvegardes');
        }

        // Envoyer le fichier au navigateur
        $taille = filesize($cheminFichier);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $sauvegarde['nom_fichier'] . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . $taille);
        header('Cache-Control: private, must-revalidate, max-age=0');
        header('Pragma: public');
        header('Expires: 0');

        // Envoyer par morceaux pour les gros fichiers
        $handle = fopen($cheminFichier, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit('Impossible de lire la sauvegarde.');
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                fclose($handle);
                exit;
            }
            echo $chunk;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        fclose($handle);
        exit;
    }
}