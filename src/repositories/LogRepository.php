<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class LogRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function countFiltered(string $where, array $params): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM logs_activites l LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id {$where}",
            $params
        );
    }

    public function listFiltered(string $where, array $params, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT l.*,
                    DATE_FORMAT(l.date_action, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(l.date_action, '%H:%i:%s') AS heure_format,
                    CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom,
                    u.login AS utilisateur_login,
                    COALESCE(u.prenom, 'Système') AS utilisateur_prenom
             FROM logs_activites l LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             {$where} ORDER BY l.date_action DESC LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );
    }

    public function exportFiltered(string $where, array $params): array
    {
        return $this->db->fetchAll(
            "SELECT l.date_action, CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur,
                    l.action, l.table_concernee, l.enregistrement_id, l.description, l.adresse_ip
             FROM logs_activites l LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             {$where} ORDER BY l.date_action DESC, l.id DESC LIMIT 10000",
            $params
        );
    }

    public function statistics(): array
    {
        return [
            'total_logs' => (int) $this->db->fetchColumn('SELECT COUNT(*) FROM logs_activites'),
            'logs_aujourdhui' => (int) $this->db->fetchColumn('SELECT COUNT(*) FROM logs_activites WHERE DATE(date_action) = CURDATE()'),
            'logs_semaine' => (int) $this->db->fetchColumn('SELECT COUNT(*) FROM logs_activites WHERE YEARWEEK(date_action, 1) = YEARWEEK(CURDATE(), 1)'),
            'logs_mois' => (int) $this->db->fetchColumn('SELECT COUNT(*) FROM logs_activites WHERE MONTH(date_action) = MONTH(CURDATE()) AND YEAR(date_action) = YEAR(CURDATE())'),
            'derniere_activite' => $this->db->fetchOne(
                "SELECT l.*, DATE_FORMAT(l.date_action, '%d/%m/%Y à %H:%i:%s') AS date_format,
                        CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom
                 FROM logs_activites l LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
                 ORDER BY l.date_action DESC LIMIT 1"
            ),
            'nb_utilisateurs' => (int) $this->db->fetchColumn('SELECT COUNT(DISTINCT utilisateur_id) FROM logs_activites WHERE utilisateur_id IS NOT NULL'),
        ];
    }

    public function topActions(): array
    {
        return $this->db->fetchAll('SELECT action, COUNT(*) AS nb FROM logs_activites GROUP BY action ORDER BY nb DESC LIMIT 10');
    }

    public function topUsers(): array
    {
        return $this->db->fetchAll(
            "SELECT CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom,
                    u.login, COUNT(*) AS nb, MAX(l.date_action) AS derniere_action,
                    DATE_FORMAT(MAX(l.date_action), '%d/%m/%Y à %H:%i') AS derniere_action_format
             FROM logs_activites l INNER JOIN utilisateurs u ON l.utilisateur_id = u.id
             GROUP BY l.utilisateur_id, u.prenom, u.nom, u.login ORDER BY nb DESC LIMIT 5"
        );
    }

    public function countOnDate(string $date): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM logs_activites WHERE DATE(date_action) = :date',
            ['date' => $date]
        );
    }

    public function activeUsers(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT u.id, CONCAT(u.prenom, ' ', u.nom) AS nom_complet, u.login
             FROM logs_activites l INNER JOIN utilisateurs u ON l.utilisateur_id = u.id ORDER BY u.nom"
        );
    }

    public function distinctActions(): array
    {
        return $this->db->fetchAll('SELECT DISTINCT action FROM logs_activites ORDER BY action');
    }

    public function distinctTables(): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT table_concernee FROM logs_activites WHERE table_concernee IS NOT NULL ORDER BY table_concernee'
        );
    }
}
