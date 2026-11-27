<?php
/**
 * Fichier : src/controllers/DashboardController.php
 * Rôle    : Gestion des données du tableau de bord
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/DashboardService.php';

class DashboardController
{
    private DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? new DashboardService();
    }

    /**
     * Données de présentation initiale des indicateurs administratifs.
     * La vue ne doit pas interroger directement la base.
     */
    public function getInitialStats(int $userId): array
    {
        $yearId = $this->dashboardService->getActiveYearId();
        // Conserve le fallback historique de la page admin si aucune année n'est configurée.
        if ($yearId <= 0) {
            $yearId = 1;
        }

        return $this->dashboardService->getStats($yearId, $userId);
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!isAjax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        // Ces graphiques agrègent des données scolaires et financières globales.
        // Le contrôle est côté serveur, indépendamment de l'affichage des liens.
        if (!hasRole(['Admin', 'Directeur'])) {
            jsonResponse(false, 'Accès non autorisé.', [], 403);
        }

        $action = post('action', '');

        switch ($action) {
            case 'getElevesParClasse':
                $this->getElevesParClasse();
                break;
            case 'getPaiementsMensuels':
                $this->getPaiementsMensuels();
                break;
            case 'getPresenceParClasse':
                $this->getPresenceParClasse();
                break;
            case 'getRepartitionSexe':
                $this->getRepartitionSexe();
                break;
            case 'getResultatsParTrimestre':
                $this->getResultatsParTrimestre();
                break;
            case 'getDerniersEleves':
                $this->getDerniersEleves();
                break;
            case 'getAnniversaires':
                $this->getAnniversaires();
                break;
            case 'getActivites':
                $this->getActivites();
                break;
            case 'getStats':
                $this->getStats();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Récupère toutes les statistiques
     */
    public function getStats(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            $stats = $this->dashboardService->getStats(
                $anneeActive,
                (int) ($_SESSION['utilisateur_id'] ?? 0)
            );
            jsonResponse(true, 'Statistiques récupérées.', [
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            error_log('Erreur getStats: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des statistiques.');
        }
    }

    /**
     * Élèves par classe
     */
    private function getElevesParClasse(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            $result = $this->dashboardService->studentsPerClass($anneeActive);

            jsonResponse(true, 'Données récupérées.', [
                'labels' => array_column($result, 'nom'),
                'values' => array_map('intval', array_column($result, 'total')),
            ]);

        } catch (Exception $e) {
            error_log('Erreur getElevesParClasse: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Paiements mensuels
     */
    private function getPaiementsMensuels(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            $mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
            $values = $this->dashboardService->monthlyPayments($anneeActive);

            jsonResponse(true, 'Données récupérées.', [
                'labels' => $mois,
                'values' => $values,
            ]);

        } catch (Exception $e) {
            error_log('Erreur getPaiementsMensuels: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Taux de présence par classe (aujourd'hui)
     */
    private function getPresenceParClasse(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            $presence = $this->dashboardService->presencePerClass($anneeActive);
            jsonResponse(true, 'Données récupérées.', [
                'labels' => $presence['labels'],
                'values' => $presence['values'],
            ]);

        } catch (Exception $e) {
            error_log('Erreur getPresenceParClasse: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Répartition filles/garçons
     */
    private function getRepartitionSexe(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            $distribution = $this->dashboardService->genderDistribution($anneeActive);

            jsonResponse(true, 'Données récupérées.', [
                'labels' => ['Garçons', 'Filles'],
                'values' => $distribution,
            ]);

        } catch (Exception $e) {
            error_log('Erreur getRepartitionSexe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Résultats par trimestre (réussite/ajournement/échec)
     */
    private function getResultatsParTrimestre(): void
    {
        try {
            $anneeActive = $this->getAnneeActiveId();
            jsonResponse(true, 'Données récupérées.', $this->dashboardService->termResults($anneeActive));

        } catch (Exception $e) {
            error_log('Erreur getResultatsParTrimestre: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Derniers élèves inscrits
     */
    private function getDerniersEleves(): void
    {
        try {
            $eleves = $this->dashboardService->latestStudents();

            jsonResponse(true, 'Données récupérées.', [
                'eleves' => $eleves
            ]);

        } catch (Exception $e) {
            error_log('Erreur getDerniersEleves: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Prochains anniversaires (7 jours)
     */
    private function getAnniversaires(): void
    {
        try {
            $anniversaires = $this->dashboardService->upcomingBirthdays();

            jsonResponse(true, 'Données récupérées.', [
                'anniversaires' => $anniversaires
            ]);

        } catch (Exception $e) {
            error_log('Erreur getAnniversaires: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Activités récentes
     */
    private function getActivites(): void
    {
        try {
            $activites = $this->dashboardService->recentActivities();

            jsonResponse(true, 'Données récupérées.', [
                'activites' => $activites
            ]);

        } catch (Exception $e) {
            error_log('Erreur getActivites: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du chargement des données.');
        }
    }

    /**
     * Récupère l'ID de l'année scolaire active
     */
    private function getAnneeActiveId(): int
    {
        return $this->dashboardService->getActiveYearId();
    }
}