<?php
/**
 * Fichier : src/controllers/LogController.php
 * Rôle    : Consultation et gestion des journaux d'activité
 * Système : École primaire congolaise — Administration
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/LogService.php';

class LogController
{
    private LogService $logService;

    public function __construct(?LogService $logService = null)
    {
        $this->logService = $logService ?? new LogService();
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
        $page = max(1, (int) post('page', 1));
        $perPage = ITEMS_PER_PAGE;
        [$whereClause, $params] = $this->buildLogFilters();
        $result = $this->logService->listFiltered($whereClause, $params, $page, $perPage);
        jsonResponse(true, 'Liste récupérée.', $result);
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
        $logs = $this->logService->exportFiltered($whereClause, $params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="journaux_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Cache-Control: no-store, private');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Date', 'Utilisateur', 'Action', 'Table', 'Identifiant', 'Description', 'Adresse IP'], ';');
        foreach ($logs as $log) {
            $values = [$log['date_action'], $log['utilisateur'], $log['action'], $log['table_concernee'],
                $log['enregistrement_id'], $log['description'], $log['adresse_ip']];
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
        jsonResponse(true, 'Statistiques récupérées.', ['stats' => $this->logService->statistics()]);
    }

    // ============================================================
    // 3. TOP 10 ACTIONS LES PLUS FRÉQUENTES
    // ============================================================

    private function getTopActions(): void
    {
        jsonResponse(true, 'Top actions récupéré.', ['top_actions' => $this->logService->topActions()]);
    }

    // ============================================================
    // 4. TOP 5 UTILISATEURS LES PLUS ACTIFS
    // ============================================================

    private function getTopUtilisateurs(): void
    {
        jsonResponse(true, 'Top utilisateurs récupéré.', ['top_utilisateurs' => $this->logService->topUsers()]);
    }

    // ============================================================
    // 5. ACTIVITÉ PAR JOUR (7 DERNIERS JOURS)
    // ============================================================

    private function getActiviteParJour(): void
    {
        jsonResponse(true, 'Activité récupérée.', ['activite' => $this->logService->activityLastSevenDays()]);
    }

    // ============================================================
    // 7. MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les utilisateurs pour le filtre
     */
    public function getUtilisateursActifs(): array
    {
        return $this->logService->activeUsers();
    }

    /**
     * Récupère les actions distinctes pour le filtre
     */
    public function getActionsDistinctes(): array
    {
        return $this->logService->distinctActions();
    }

    /**
     * Récupère les tables distinctes pour le filtre
     */
    public function getTablesDistinctes(): array
    {
        return $this->logService->distinctTables();
    }
}