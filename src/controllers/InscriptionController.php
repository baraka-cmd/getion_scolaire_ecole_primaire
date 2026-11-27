<?php
/**
 * Fichier : src/controllers/InscriptionController.php
 * Rôle    : Gestion complète des inscriptions (CRUD + réinscription massive)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/InscriptionService.php';

class InscriptionController
{
    private InscriptionService $inscriptionService;

    public function __construct()
    {
        $this->inscriptionService = new InscriptionService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('inscriptions.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            case 'liste':
                $this->getListe();
                break;
            case 'getInscription':
                $this->getInscription();
                break;
            case 'inscrire':
                $this->inscrire();
                break;
            case 'changerClasse':
                $this->changerClasse();
                break;
            case 'desinscrire':
                $this->desinscrire();
                break;
            case 'getElevesPourReinscription':
                $this->getElevesPourReinscription();
                break;
            case 'reinscrireMassivement':
                $this->reinscrireMassivement();
                break;
            case 'getHistorique':
                $this->getHistorique();
                break;
            case 'getClassesDisponibles':
                $this->getClassesDisponibles();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // LISTE DES INSCRIPTIONS
    // ============================================================

    private function getListe(): void
    {
        $page = max(1, (int) post('page', 1));
        $filters = ['recherche' => post('recherche', ''), 'classe_id' => (int) post('classe_id', 0), 'annee_id' => (int) post('annee_id', 0), 'statut' => post('statut', '')];
        $result = $this->inscriptionService->paginate($filters, $page, ITEMS_PER_PAGE);
        jsonResponse(true, 'Liste récupérée.', ['inscriptions' => $result['inscriptions'], 'total' => $result['total'], 'page' => $page, 'pages' => ceil($result['total'] / ITEMS_PER_PAGE)]);
    }

    // ============================================================
    // INSCRIPTION INDIVIDUELLE
    // ============================================================

    private function inscrire(): void
    {
        requirePermission('inscriptions.creer');
        try {
            $result = $this->inscriptionService->createEnrollment(
                (int) post('eleve_id', 0),
                (int) post('classe_id', 0),
                (string) post('statut', 'Actif')
            );
            if (!$result['ok']) jsonResponse(false, $result['message'] ?? 'Veuillez corriger les erreurs.', ['errors' => $result['errors'] ?? []], $result['status'] ?? 422);
            logActivity('Création', 'inscriptions', $result['id'], "Inscription : {$result['student']['nom']} {$result['student']['prenom']}");
            jsonResponse(true, 'Élève inscrit avec succès.', ['id' => $result['id']]);
        } catch (Throwable $e) {
            error_log('Erreur inscription: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de l\'inscription.');
        }
    }

    // ============================================================
    // CHANGEMENT DE CLASSE
    // ============================================================

    private function changerClasse(): void
    {
        requirePermission('inscriptions.modifier');
        try {
            $studentId = (int) post('eleve_id', 0);
            $result = $this->inscriptionService->changeClass($studentId, (int) post('ancienne_classe_id', 0), (int) post('nouvelle_classe_id', 0), (string) post('motif', 'Changement de classe'));
            if (!$result['ok']) jsonResponse(false, $result['message'], [], $result['status']);
            logActivity('Modification', 'inscriptions', null, 'Changement de classe élève ' . $studentId);
            jsonResponse(true, 'Changement de classe effectué avec succès.');
        } catch (Throwable $e) {
            error_log('Erreur changement classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du changement de classe.');
        }
    }

    // ============================================================
    // DÉSINSCRIPTION (ABANDON / EXCLUSION)
    // ============================================================

    private function desinscrire(): void
    {
        requirePermission('inscriptions.modifier');
        try {
            $enrollmentId = (int) post('inscription_id', 0);
            $status = (string) post('statut', 'Abandon');
            $result = $this->inscriptionService->discontinue($enrollmentId, $status, (string) post('motif', ''));
            if (!$result['ok']) jsonResponse(false, $result['message'], [], $result['status']);
            logActivity('Modification', 'inscriptions', $enrollmentId, 'Désinscription : ' . $status);
            jsonResponse(true, 'Statut mis à jour avec succès.');
        } catch (Throwable $e) {
            error_log('Erreur désinscription: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la mise à jour.');
        }
    }

    // ============================================================
    // RÉINSCRIPTION MASSIVE
    // ============================================================

    /**
     * Récupère les élèves d'une classe pour la réinscription
     */
    private function getElevesPourReinscription(): void
    {
        requirePermission('inscriptions.creer');
        $result = $this->inscriptionService->getTransitionCandidates(
            (int) post('classe_source_id', 0),
            (int) post('annee_source_id', 0),
            (int) post('annee_destination_id', 0)
        );
        if (!$result['ok']) jsonResponse(false, $result['message'], [], $result['status']);
        jsonResponse(true, 'Élèves récupérés.', [
            'eleves' => $result['eleves'],
            'classe_source' => $result['classe_source'],
            'classes_disponibles' => $result['classes_disponibles'],
            'total' => $result['total']
        ]);
    }

    /**
     * Exécute la réinscription massive
     */
    private function reinscrireMassivement(): void
    {
        requirePermission('inscriptions.creer');
        $selection = json_decode((string) post('eleves', ''), true);
        $result = $this->inscriptionService->executeMassTransition(
            is_array($selection) ? $selection : [],
            (int) post('classe_source_id', 0),
            (int) post('annee_source_id', 0),
            (int) post('annee_destination_id', 0)
        );
        if (!$result['ok']) jsonResponse(false, $result['message'], [], $result['status']);
        logActivity('Réinscription', 'inscriptions', null,
            "Transition scolaire : {$result['inscrits']} inscrits, {$result['redoublants']} redoublants, {$result['diplomes']} diplômés");
        jsonResponse(true, 'Transition scolaire enregistrée avec succès.', [
            'inscrits' => $result['inscrits'],
            'redoublants' => $result['redoublants'],
            'diplomes' => $result['diplomes']
        ]);
    }

    // ============================================================
    // HISTORIQUE D'UN ÉLÈVE
    // ============================================================

    private function getHistorique(): void
    {
        $eleveId = (int) post('eleve_id', 0);
        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);
        $result = $this->inscriptionService->getStudentHistory($eleveId);
        jsonResponse(true, 'Historique récupéré.', ['eleve' => $result['eleve'], 'historique' => $result['historique']]);
    }

    /**
     * Récupère les classes disponibles (avec capacité)
     */
    private function getClassesDisponibles(): void
    {
        $anneeId = (int) post('annee_id', 0);
        $classes = $this->inscriptionService->getAvailableClasses($anneeId);
        jsonResponse(true, 'Classes récupérées.', ['classes' => $classes]);
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère toutes les années scolaires (pour filtres)
     */
    public function getAnneesScolaires(): array
    {
        return $this->inscriptionService->getSchoolYears();
    }

    /**
     * Récupère les classes de l'année active
     */
    public function getClassesActives(): array
    {
        return $this->inscriptionService->getActiveClasses();
    }

    /**
     * Récupère les élèves non inscrits (disponibles pour inscription)
     */
    public function getElevesNonInscrits(): array
    {
        return $this->inscriptionService->getUnenrolledStudents();
    }

    /**
     * Récupère une inscription par ID
     */
    public function getInscriptionById(int $id): ?array
    {
        return $this->inscriptionService->findEnrollment($id);
    }

    /**
     * Récupère l'historique complet d'un élève
     */
    public function getHistoriqueEleve(int $eleveId): array
    {
        return $this->inscriptionService->getEnrollmentHistory($eleveId);
    }
}