<?php
/**
 * Fichier : src/controllers/LogController.php
 * Rôle    : Consultation et gestion des journaux d'activité
 * Système : École primaire congolaise — Administration
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

class LogController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('logs.consulter')) {
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
            case 'getStatistiques':
                $this->getStatistiques();
                break;
            case 'getTopActions':
                $this->getTopActions();
                break;
            case 'getTopUtilisateurs':
                $this->getTopUtilisateurs();
                break;
            case 'getActiviteParJour':
                $this->getActiviteParJour();
                break;
            case 'exporterCsv':
                $this->exporterCsv();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // 1. LISTE PAGINÉE DES LOGS
    // ============================================================

    private function getListe(): void
    {
        $page       = max(1, (int) post('page', 1));
        $parPage    = ITEMS_PER_PAGE;

        [$whereClause, $params] = $this->buildLogFilters();

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM logs_activites l
             LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $parPage;

        $logs = $this->db->fetchAll(
            "SELECT l.*, 
                    DATE_FORMAT(l.date_action, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(l.date_action, '%H:%i:%s') AS heure_format,
                    CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom,
                    u.login AS utilisateur_login,
                    COALESCE(u.prenom, 'Système') AS utilisateur_prenom
             FROM logs_activites l
             LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             {$whereClause}
             ORDER BY l.date_action DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $parPage, 'offset' => $offset])
        );

        jsonResponse(true, 'Liste récupérée.', [
            'logs'  => $logs,
            'total' => $total,
            'page'  => $page,
            'pages' => ceil($total / $parPage)
        ]);
    }

    private function buildLogFilters(): array
    {
        $where = [];
        $params = [];
        $utilisateurId = (int) post('utilisateur_id', 0);
        $action = post('action_filtre', '');
        $table = post('table_concernee', '');
        $dateDebut = post('date_debut', '');
        $dateFin = post('date_fin', '');
        $recherche = post('recherche', '');

        if ($utilisateurId > 0) {
            $where[] = 'l.utilisateur_id = :uid';
            $params['uid'] = $utilisateurId;
        }
        if ($action !== '') {
            $where[] = 'l.action = :action';
            $params['action'] = $action;
        }
        if ($table !== '') {
            $where[] = 'l.table_concernee = :table_name';
            $params['table_name'] = $table;
        }
        if ($dateDebut !== '') {
            if (!isValidDate($dateDebut)) {
                jsonResponse(false, 'La date de début n’est pas valide.', [], 422);
            }
            $where[] = 'l.date_action >= :debut';
            $params['debut'] = $dateDebut . ' 00:00:00';
        }
        if ($dateFin !== '') {
            if (!isValidDate($dateFin)) {
                jsonResponse(false, 'La date de fin n’est pas valide.', [], 422);
            }
            $where[] = 'l.date_action <= :fin';
            $params['fin'] = $dateFin . ' 23:59:59';
        }
        if ($dateDebut !== '' && $dateFin !== '' && $dateDebut > $dateFin) {
            jsonResponse(false, 'La date de début doit être antérieure ou égale à la date de fin.', [], 422);
        }
        if ($recherche !== '') {
            $recherche = substr($recherche, 0, 120);
            $where[] = "(l.description LIKE :r1 OR l.action LIKE :r2 OR CONCAT(u.prenom, ' ', u.nom) LIKE :r3)";
            $params['r1'] = "%{$recherche}%";
            $params['r2'] = "%{$recherche}%";
            $params['r3'] = "%{$recherche}%";
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private function exporterCsv(): void
    {
        requirePermission('logs.consulter');
        global $security;
        $security->validateCsrf();

        [$whereClause, $params] = $this->buildLogFilters();
        $logs = $this->db->fetchAll(
            "SELECT l.date_action, CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur,
                    l.action, l.table_concernee, l.enregistrement_id, l.description, l.adresse_ip
             FROM logs_activites l
             LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             {$whereClause}
             ORDER BY l.date_action DESC, l.id DESC
             LIMIT 10000",
            $params
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="journaux_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Cache-Control: no-store, private');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Date', 'Utilisateur', 'Action', 'Table', 'Identifiant', 'Description', 'Adresse IP'], ';');
        foreach ($logs as $log) {
            $values = [
                $log['date_action'],
                $log['utilisateur'],
                $log['action'],
                $log['table_concernee'],
                $log['enregistrement_id'],
                $log['description'],
                $log['adresse_ip']
            ];
            fputcsv($output, array_map([$this, 'neutraliserFormuleCsv'], $values), ';');
        }
        fclose($output);
        exit;
    }

    private function neutraliserFormuleCsv($value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    // ============================================================
    // 2. STATISTIQUES GLOBALES
    // ============================================================

    private function getStatistiques(): void
    {
        // Total des logs
        $totalLogs = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM logs_activites");

        // Logs aujourd'hui
        $logsAujourdhui = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM logs_activites WHERE DATE(date_action) = CURDATE()"
        );

        // Logs cette semaine
        $logsSemaine = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM logs_activites WHERE YEARWEEK(date_action, 1) = YEARWEEK(CURDATE(), 1)"
        );

        // Logs ce mois
        $logsMois = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM logs_activites WHERE MONTH(date_action) = MONTH(CURDATE()) AND YEAR(date_action) = YEAR(CURDATE())"
        );

        // Dernière activité
        $derniereActivite = $this->db->fetchOne(
            "SELECT l.*, 
                    DATE_FORMAT(l.date_action, '%d/%m/%Y à %H:%i:%s') AS date_format,
                    CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom
             FROM logs_activites l
             LEFT JOIN utilisateurs u ON l.utilisateur_id = u.id
             ORDER BY l.date_action DESC LIMIT 1"
        );

        // Nombre d'utilisateurs distincts ayant effectué des actions
        $nbUtilisateursActifs = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT utilisateur_id) FROM logs_activites WHERE utilisateur_id IS NOT NULL"
        );

        jsonResponse(true, 'Statistiques récupérées.', [
            'stats' => [
                'total_logs'           => $totalLogs,
                'logs_aujourdhui'      => $logsAujourdhui,
                'logs_semaine'         => $logsSemaine,
                'logs_mois'            => $logsMois,
                'nb_utilisateurs'      => $nbUtilisateursActifs,
                'derniere_activite'    => $derniereActivite
            ]
        ]);
    }

    // ============================================================
    // 3. TOP 10 ACTIONS LES PLUS FRÉQUENTES
    // ============================================================

    private function getTopActions(): void
    {
        $topActions = $this->db->fetchAll(
            "SELECT action, COUNT(*) AS nb 
             FROM logs_activites 
             GROUP BY action 
             ORDER BY nb DESC 
             LIMIT 10"
        );

        jsonResponse(true, 'Top actions récupéré.', ['top_actions' => $topActions]);
    }

    // ============================================================
    // 4. TOP 5 UTILISATEURS LES PLUS ACTIFS
    // ============================================================

    private function getTopUtilisateurs(): void
    {
        $topUtilisateurs = $this->db->fetchAll(
            "SELECT CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, '')) AS utilisateur_nom,
                    u.login,
                    COUNT(*) AS nb,
                    MAX(l.date_action) AS derniere_action,
                    DATE_FORMAT(MAX(l.date_action), '%d/%m/%Y à %H:%i') AS derniere_action_format
             FROM logs_activites l
             INNER JOIN utilisateurs u ON l.utilisateur_id = u.id
             GROUP BY l.utilisateur_id, u.prenom, u.nom, u.login
             ORDER BY nb DESC
             LIMIT 5"
        );

        jsonResponse(true, 'Top utilisateurs récupéré.', ['top_utilisateurs' => $topUtilisateurs]);
    }

    // ============================================================
    // 5. ACTIVITÉ PAR JOUR (7 DERNIERS JOURS)
    // ============================================================

    private function getActiviteParJour(): void
    {
        $activite = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $nb = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM logs_activites WHERE DATE(date_action) = :date",
                ['date' => $date]
            );
            $activite[] = [
                'date'  => $date,
                'label' => date('d/m', strtotime($date)),
                'jour'  => jourEnFrancais($date),
                'nb'    => $nb
            ];
        }

        jsonResponse(true, 'Activité récupérée.', ['activite' => $activite]);
    }

    // ============================================================
    // 7. MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les utilisateurs pour le filtre
     */
    public function getUtilisateursActifs(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT u.id, CONCAT(u.prenom, ' ', u.nom) AS nom_complet, u.login
             FROM logs_activites l
             INNER JOIN utilisateurs u ON l.utilisateur_id = u.id
             ORDER BY u.nom"
        );
    }

    /**
     * Récupère les actions distinctes pour le filtre
     */
    public function getActionsDistinctes(): array
    {
        $result = $this->db->fetchAll(
            "SELECT DISTINCT action FROM logs_activites ORDER BY action"
        );
        return array_column($result, 'action');
    }

    /**
     * Récupère les tables distinctes pour le filtre
     */
    public function getTablesDistinctes(): array
    {
        $result = $this->db->fetchAll(
            "SELECT DISTINCT table_concernee FROM logs_activites WHERE table_concernee IS NOT NULL ORDER BY table_concernee"
        );
        return array_column($result, 'table_concernee');
    }
}