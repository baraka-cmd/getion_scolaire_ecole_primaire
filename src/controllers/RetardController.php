<?php
/**
 * Fichier : src/controllers/RetardController.php
 * Rôle    : Gestion des retards — Enregistrement, suivi, statistiques
 * Système : École primaire congolaise
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/RetardService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class RetardController
{
    private RetardService $retardService;
    private StudentAccessPolicy $studentAccessPolicy;

    public function __construct(?RetardService $retardService = null, ?StudentAccessPolicy $studentAccessPolicy = null)
    {
        $this->retardService = $retardService ?? new RetardService();
        $this->studentAccessPolicy = $studentAccessPolicy ?? new StudentAccessPolicy();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('retards.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            case 'enregistrerRetard':
                Security::getInstance()->validateCsrf();
                $this->enregistrerRetard();
                break;
            case 'getListeRetards':
                $this->getListeRetards();
                break;
            case 'getStatsRetards':
                $this->getStatsRetards();
                break;
            case 'justifierRetard':
                Security::getInstance()->validateCsrf();
                $this->justifierRetard();
                break;
            case 'getRetardsEleve':
                $this->getRetardsEleve();
                break;
            case 'supprimerRetard':
                Security::getInstance()->validateCsrf();
                $this->supprimerRetard();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // 1. ENREGISTRER UN RETARD
    // ============================================================

    /**
     * Enregistre un retard pour un élève
     */
    public function enregistrerRetard(): void
    {
        requirePermission('retards.enregistrer');
        $eleveId = (int) post('eleve_id', 0);
        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);
        if (!$this->studentAccessPolicy->canCurrentUserAccessStudent($eleveId)) jsonResponse(false, 'Élève introuvable ou non autorisé.', [], 404);
        $dateRetard = post('date_retard', date('Y-m-d'));
        $heureArrivee = post('heure_arrivee', '');
        $heurePrevue = post('heure_prevue', '07:30');
        $motif = post('motif', '');
        $estJustifie = (int) post('est_justifie', 0);
        if (empty($heureArrivee)) jsonResponse(false, 'L\'heure d\'arrivée est obligatoire.', [], 400);
        $dureeRetard = $this->retardService->calculateDuration($heurePrevue, $heureArrivee);
        if ($dureeRetard <= 0) jsonResponse(false, 'L\'heure d\'arrivée doit être après l\'heure prévue.', [], 400);
        $eleve = $this->retardService->findActiveStudent($eleveId);
        if (!$eleve) jsonResponse(false, 'Élève introuvable ou non inscrit.', [], 404);
        if ($this->retardService->existsForDate($eleveId, $dateRetard)) jsonResponse(false, 'Un retard a déjà été enregistré pour cet élève à cette date.', [], 409);
        try {
            $retardId = $this->retardService->createRetard($eleveId, $dateRetard, $heureArrivee, $heurePrevue, $dureeRetard, $motif, $estJustifie, (int) $_SESSION['utilisateur_id']);
            $nbRetards = $this->retardService->countForStudent($eleveId);
            $alerte = $nbRetards >= 10 ? '⚠️ Cet élève totalise ' . $nbRetards . ' retards. Une convocation des parents est recommandée.'
                : ($nbRetards >= 5 ? '⚠️ Cet élève totalise ' . $nbRetards . ' retards. Une alerte aux parents est recommandée.'
                : ($nbRetards >= 3 ? 'Cet élève totalise ' . $nbRetards . ' retards.' : null));
            logActivity('Création', 'retards', $retardId, "Retard de {$eleve['nom']} {$eleve['prenom']} — {$dureeRetard} min");
            jsonResponse(true, 'Retard enregistré avec succès.', ['retard_id'=>$retardId,'duree_retard'=>$dureeRetard,'nb_retards'=>$nbRetards,'alerte'=>$alerte]);
        } catch (Exception $e) {
            error_log('Erreur enregistrement retard: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de l\'enregistrement du retard.');
        }
    }

    // ============================================================
    // 2. LISTE DES RETARDS
    // ============================================================

    /**
     * Récupère la liste paginée des retards avec filtres
     */
    public function getListeRetards(): void
    {
        $page = max(1, (int) post('page', 1));
        $classeId = (int) post('classe_id', 0);
        if (hasRole('Enseignant')) {
            $ownedClass = $this->retardService->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
            if (!$ownedClass) jsonResponse(false, 'Aucune classe active n’est affectée à votre compte.', [], 403);
            if ($classeId > 0 && $classeId !== $ownedClass) jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
            $classeId = $ownedClass;
        }
        $filters = [
            'classe_id' => $classeId,
            'date_debut' => post('date_debut', ''),
            'date_fin' => post('date_fin', ''),
            'justifie' => post('justifie', ''),
            'recherche' => post('recherche', ''),
        ];
        $perPage = ITEMS_PER_PAGE;
        $result = $this->retardService->getList($filters, $page, $perPage);
        $total = (int) $result['total'];
        jsonResponse(true, 'Liste récupérée.', [
            'retards' => $result['retards'],
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage)
        ]);
    }

    // ============================================================
    // 3. STATISTIQUES DES RETARDS
    // ============================================================

    /**
     * Récupère les statistiques des retards
     */
    public function getStatsRetards(): void
    {
        $classeId = (int) post('classe_id', 0);
        if (hasRole('Enseignant')) {
            $ownedClass = $this->retardService->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
            if (!$ownedClass) jsonResponse(false, 'Aucune classe active n’est affectée à votre compte.', [], 403);
            if ($classeId > 0 && $classeId !== $ownedClass) jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
            $classeId = $ownedClass;
        }
        $result = $this->retardService->getStatistics($classeId, (int) post('trimestre_id', 0));
        jsonResponse(true, 'Statistiques récupérées.', $result);
    }

    // ============================================================
    // 4. JUSTIFIER UN RETARD
    // ============================================================

    /**
     * Justifie un retard existant
     */
    public function justifierRetard(): void
    {
        requirePermission('retards.justifier');
        $retardId = (int) post('retard_id', 0);
        $motif = post('motif', '');
        if ($retardId <= 0) jsonResponse(false, 'ID retard invalide.', [], 400);
        if (empty($motif)) jsonResponse(false, 'Le motif est obligatoire.', [], 422);
        $studentId = $this->retardService->findStudentIdForRetard($retardId);
        if ($studentId === null || !$this->studentAccessPolicy->canCurrentUserAccessStudent($studentId)) jsonResponse(false, 'Retard introuvable.', [], 404);
        if (!$this->retardService->justify($retardId, $motif)) jsonResponse(false, 'Retard introuvable.', [], 404);
        logActivity('Justification', 'retards', $retardId, 'Retard justifié');
        jsonResponse(true, 'Retard justifié avec succès.');
    }

    // ============================================================
    // 5. RETARDS D'UN ÉLÈVE
    // ============================================================

    /**
     * Récupère les retards d'un élève spécifique
     */
    public function getRetardsEleve(): void
    {
        $eleveId = (int) post('eleve_id', 0);
        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);
        if (!$this->studentAccessPolicy->canCurrentUserAccessStudent($eleveId)) jsonResponse(false, 'Élève introuvable.', [], 404);
        $retards = $this->retardService->getStudentHistory($eleveId);
        $nbRetards = count($retards);
        $totalDuree = array_sum(array_column($retards, 'duree_retard'));
        $moyenneDuree = $nbRetards > 0 ? round($totalDuree / $nbRetards, 1) : 0;
        jsonResponse(true, 'Retards récupérés.', ['retards'=>$retards,'nb_retards'=>$nbRetards,'total_duree'=>$totalDuree,'moyenne_duree'=>$moyenneDuree]);
    }

    // ============================================================
    // 6. SUPPRIMER UN RETARD
    // ============================================================

    /**
     * Supprime un retard
     */
    public function supprimerRetard(): void
    {
        requirePermission('retards.supprimer');
        $retardId = (int) post('retard_id', 0);
        if ($retardId <= 0) jsonResponse(false, 'ID retard invalide.', [], 400);
        $studentId = $this->retardService->findStudentIdForRetard($retardId);
        if ($studentId === null || !$this->studentAccessPolicy->canCurrentUserAccessStudent($studentId)) jsonResponse(false, 'Retard introuvable.', [], 404);
        $this->retardService->delete($retardId);
        jsonResponse(true, 'Retard supprimé avec succès.');
    }

    public function getMarkingData(int $classId, string $date): array
    {
        if (hasRole('Enseignant')) {
            $ownedClass = $this->retardService->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
            if (!$ownedClass || $classId !== $ownedClass) jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
        }
        return $this->retardService->getMarkingData($classId, $date);
    }

    public function getStatsGlobales(): array
    {
        $stats = $this->retardService->getGlobalStatistics();
        return [
            'total' => $stats['total'],
            'justifies' => $stats['justified'],
            'duree_moyenne' => round($stats['average'], 1),
            'ce_mois' => $stats['month'],
        ];
    }

    // ============================================================
    // 7. MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Calcule la durée du retard en minutes
     */
    // ============================================================
    // 8. MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les classes de l'année active
     */
    public function getClassesActives(): array
    {
        return $this->retardService->getActiveClasses();
    }

    /**
     * Récupère la classe de l'enseignant connecté
     */
    public function getClasseEnseignant(): int
    {
        return $this->retardService->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
    }

    /**
     * Récupère les statistiques des retards pour le dashboard
     */
    public function getStatsDashboard(): array
    {
        $counts = $this->retardService->getDashboardStatistics(date('Y-m-d'));
        return ['retards_jour' => $counts['today'], 'retards_mois' => $counts['month']];
    }
}