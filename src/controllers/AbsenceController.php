<?php
/**
 * Fichier : src/controllers/AbsenceController.php
 * Rôle    : Coordination HTTP/AJAX des fonctionnalités d'absences
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/EleveController.php';
require_once __DIR__ . '/../services/AbsenceService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class AbsenceController
{
    private AbsenceService $service;
    private StudentAccessPolicy $studentAccessPolicy;

    public function __construct(
        ?AbsenceService $service = null,
        ?StudentAccessPolicy $studentAccessPolicy = null
    )
    {
        $this->service = $service ?? new AbsenceService();
        $this->studentAccessPolicy = $studentAccessPolicy ?? new StudentAccessPolicy();
    }

    public function handleAjax(): void
    {
        if (!isAjax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');
        $permissions = [
            'marquerAbsence' => 'absences.marquer',
            'sauvegarderMotif' => 'absences.marquer',
            'justifierAbsenceDirecte' => 'absences.justifier',
            'getListeAbsences' => 'absences.consulter',
            'getStatsAbsences' => 'absences.consulter',
            'getAbsencesEleve' => 'absences.consulter',
        ];
        if ($action === 'justifierAbsence') {
            Security::getInstance()->requireAnyPermission(['absences.justifier', 'absences.marquer']);
        } elseif (!isset($permissions[$action])) {
            jsonResponse(false, 'Action inconnue.', [], 400);
        } else {
            requirePermission($permissions[$action]);
        }

        if (in_array($action, [
            'marquerAbsence',
            'sauvegarderMotif',
            'justifierAbsence',
            'justifierAbsenceDirecte',
        ], true)) {
            Security::getInstance()->validateCsrf();
        }

        switch ($action) {
            case 'marquerAbsence':
                $this->marquerAbsence();
                break;
            case 'sauvegarderMotif':
                $this->sauvegarderMotif();
                break;
            case 'justifierAbsence':
                $this->justifierAbsence();
                break;
            case 'justifierAbsenceDirecte':
                $this->justifierAbsenceDirecte();
                break;
            case 'getListeAbsences':
                $this->getListeAbsences();
                break;
            case 'getStatsAbsences':
                $this->getStatsAbsences();
                break;
            case 'getAbsencesEleve':
                $this->getAbsencesEleve();
                break;
        }
    }

    public function justifierAbsenceDirecte(): void
    {
        requirePermission('absences.justifier');
        $absenceId = (int) post('absence_id', 0);
        $motif = post('motif', '');
        $commentaire = post('commentaire', '');

        if ($absenceId <= 0) {
            jsonResponse(false, 'ID absence invalide.', [], 400);
        }
        if (empty($motif)) {
            jsonResponse(false, 'Veuillez sélectionner un motif.', [], 422);
        }

        $studentId = $this->service->findStudentForAbsence($absenceId);
        if ($studentId === null || !$this->canCurrentUserAccessStudent($studentId)) {
            jsonResponse(false, 'Absence introuvable.', [], 404);
        }

        $completeReason = $motif . ($commentaire ? ' — ' . $commentaire : '');
        $this->service->justifyAbsenceById($absenceId, $completeReason);

        logActivity('Justification', 'absences', $absenceId, 'Absence justifiée directement');
        jsonResponse(true, 'Absence justifiée avec succès.');
    }

    public function marquerAbsence(): void
    {
        requirePermission('absences.marquer');
        $studentId = (int) post('eleve_id', 0);
        $date = post('date_absence', date('Y-m-d'));
        $type = post('type_absence', 'Absence');
        $reason = post('motif', '');

        if ($studentId <= 0) {
            jsonResponse(false, 'ID élève invalide.', [], 400);
        }
        if (!$this->canCurrentUserAccessStudent($studentId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }
        if (!in_array($type, ['Absence', 'Présent'], true)) {
            jsonResponse(false, 'Type invalide.', [], 400);
        }

        try {
            if (!$this->service->markStatus(
                $studentId,
                $date,
                $type,
                $reason,
                (int) $_SESSION['utilisateur_id']
            )) {
                jsonResponse(false, 'Élève introuvable ou non inscrit.', [], 404);
            }

            jsonResponse(true, 'Statut mis à jour avec succès.');
        } catch (Exception $e) {
            error_log('Erreur marquage absence: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du marquage.');
        }
    }

    public function sauvegarderMotif(): void
    {
        requirePermission('absences.marquer');
        $studentId = (int) post('eleve_id', 0);
        $date = post('date_absence', '');
        $reason = post('motif', '');

        if ($studentId <= 0 || empty($date)) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }
        if (!isValidDate($date)) {
            jsonResponse(false, 'Date invalide.', [], 422);
        }
        if (!$this->canCurrentUserAccessStudent($studentId)) {
            jsonResponse(false, 'Absence introuvable.', [], 404);
        }
        if (!$this->service->saveReason($studentId, $date, $reason)) {
            jsonResponse(false, 'Absence introuvable.', [], 404);
        }

        jsonResponse(true, 'Motif sauvegardé.');
    }

    public function justifierAbsence(): void
    {
        Security::getInstance()->requireAnyPermission(['absences.justifier', 'absences.marquer']);
        $studentId = (int) post('eleve_id', 0);
        $date = post('date_absence', '');
        $motif = post('motif', '');
        $commentaire = post('commentaire', '');

        if ($studentId <= 0 || empty($date)) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }
        if (!isValidDate($date) || $date > date('Y-m-d')) {
            jsonResponse(false, 'Date de justification invalide.', [], 422);
        }
        if (!$this->canCurrentUserAccessStudent($studentId)) {
            jsonResponse(false, 'Absence introuvable.', [], 404);
        }
        if (empty($motif)) {
            jsonResponse(false, 'Veuillez sélectionner un motif de justification.', [], 422);
        }

        $completeReason = $motif . ($commentaire ? ' — ' . $commentaire : '');
        $absenceId = $this->service->justifyAbsence(
            $studentId,
            $date,
            $completeReason,
            (int) $_SESSION['utilisateur_id']
        );

        logActivity('Justification', 'absences', $absenceId, "Absence justifiée pour élève {$studentId}");
        jsonResponse(true, 'Absence justifiée avec succès.');
    }

    public function getListeAbsences(): void
    {
        $filters = [
            'classe_id' => (int) post('classe_id', 0),
            'date_debut' => post('date_debut', ''),
            'date_fin' => post('date_fin', ''),
            'type' => post('type', ''),
            'justifiee' => post('justifiee', ''),
            'recherche' => post('recherche', ''),
        ];
        $page = max(1, (int) post('page', 1));
        $result = $this->service->getAbsenceList(
            $filters,
            (int) ($_SESSION['utilisateur_id'] ?? 0),
            (string) ($_SESSION['utilisateur_role'] ?? ''),
            $page,
            ITEMS_PER_PAGE
        );

        jsonResponse(true, 'Liste récupérée.', [
            'absences' => $result['absences'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => ceil($result['total'] / ITEMS_PER_PAGE),
        ]);
    }

    public function getStatsAbsences(): void
    {
        $statistics = $this->service->getStatistics(
            [
                'classe_id' => (int) post('classe_id', 0),
                'trimestre_id' => (int) post('trimestre_id', 0),
            ],
            (int) ($_SESSION['utilisateur_id'] ?? 0),
            (string) ($_SESSION['utilisateur_role'] ?? '')
        );

        jsonResponse(true, 'Statistiques récupérées.', $statistics);
    }

    public function getAbsencesEleve(): void
    {
        $studentId = (int) post('eleve_id', 0);
        if ($studentId <= 0) {
            jsonResponse(false, 'ID élève invalide.', [], 400);
        }
        if (!$this->canCurrentUserAccessStudent($studentId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        jsonResponse(true, 'Absences récupérées.', $this->service->getStudentAbsences($studentId));
    }

    public function getClassesActives(): array
    {
        return $this->service->getActiveClasses();
    }

    public function getClasseEnseignant(): int
    {
        return $this->service->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
    }

    private function canCurrentUserAccessStudent(int $studentId): bool
    {
        return $this->studentAccessPolicy->canCurrentUserAccessStudent($studentId);
    }

    public function getMarkingData(int $classId, string $date): array
    {
        requirePermission('absences.marquer');
        if (hasRole('Enseignant')) {
            $ownedClass = $this->service->getTeacherClass((int) ($_SESSION['utilisateur_id'] ?? 0));
            if (!$ownedClass || $classId !== $ownedClass) {
                jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
            }
        }
        return $this->service->getMarkingData($classId, $date);
    }

    public function getStatsDashboard(): array
    {
        return $this->service->getDashboardStatistics(date('Y-m-d'));
    }
}
