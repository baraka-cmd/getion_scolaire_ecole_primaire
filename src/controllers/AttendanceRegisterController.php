<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/AttendanceRegisterService.php';

class AttendanceRegisterController
{
    private AttendanceRegisterService $attendanceRegisterService;

    public function __construct(?AttendanceRegisterService $attendanceRegisterService = null)
    {
        $this->attendanceRegisterService = $attendanceRegisterService ?? new AttendanceRegisterService();
    }

    public function handleAjax(): void
    {
        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        switch (post('action', '')) {
            case 'validerAppel':
                $this->validerAppel();
                break;
            case 'marquerConge':
                $this->marquerConge();
                break;
            case 'annulerConge':
                $this->annulerConge();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    public function getAnnees(): array
    {
        return $this->attendanceRegisterService->getSchoolYears();
    }

    public function getAnneeActiveId(): int
    {
        return $this->attendanceRegisterService->getActiveSchoolYearId();
    }

    public function getClasses(int $anneeId): array
    {
        $teacherUserId = hasRole('Enseignant') ? (int) ($_SESSION['utilisateur_id'] ?? 0) : null;
        return $this->attendanceRegisterService->getClasses($anneeId, $teacherUserId);
    }

    public function getPointage(int $classeId, string $date): array
    {
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        return $this->attendanceRegisterService->getPointage($classeId, $annee, $date);
    }

    public function getRegistre(int $classeId, int $anneeId, ?string $dateReference = null): array
    {
        $this->requireClassAccess($classeId, $anneeId);
        return $this->attendanceRegisterService->getRegistre($classeId, $anneeId, $dateReference);
    }

    private function validerAppel(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();
        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        if ($date !== date('Y-m-d')) jsonResponse(false, 'La date de l’appel est automatiquement fixée à la date du jour.', [], 422);
        $submitted = $_POST['eleves_presents'] ?? [];
        if (!is_array($submitted)) jsonResponse(false, 'Liste de présences invalide.', [], 422);
        $normalized = [];
        foreach ($submitted as $id) {
            if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                jsonResponse(false, 'La liste contient un identifiant d’élève invalide.', [], 422);
            }
            $normalized[] = (int) $id;
        }
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        if ($this->attendanceRegisterService->getClosureForDate((int) $annee['annee_scolaire_id'], $date, $classeId, $annee['nom']) !== null) {
            jsonResponse(false, 'Cette date est indiquée comme congé dans le calendrier scolaire.', [], 409);
        }
        $scheduled = $this->attendanceRegisterService->isScheduledDay($classeId, (int) $annee['annee_scolaire_id'], $date);
        $students = $this->getElevesAppel($classeId, (int) $annee['annee_scolaire_id']);
        if (!$students) jsonResponse(false, 'Aucun élève actif n’est inscrit dans cette classe pour cette année.', [], 409);
        $studentIds = array_map('intval', array_column($students, 'id'));
        $presentIds = array_values(array_unique($normalized));
        if (array_diff($presentIds, $studentIds)) jsonResponse(false, 'La liste contient un élève qui ne fait pas partie de cette classe.', [], 422);
        try {
            $result = $this->attendanceRegisterService->saveDailyCall(
                $classeId, (int) $annee['annee_scolaire_id'], $date, $studentIds, $presentIds,
                (int) ($_SESSION['utilisateur_id'] ?? 0), $scheduled !== false
            );
            if ($result === 'holiday') jsonResponse(false, 'Cette journée est déjà enregistrée comme congé.', [], 409);
            if ($result === 'not_scheduled') jsonResponse(false, 'Cette date n’est pas un jour de classe selon l’emploi du temps.', [], 409);
            if ($result === 'enrollment_changed') jsonResponse(false, 'La liste des élèves a changé. Rechargez le pointage avant de valider.', [], 409);
            logActivity('Pointage', 'appels_presence', null, "Appel validé — classe {$classeId} — {$date}");
            jsonResponse(true, 'Appel validé. Les élèves non cochés sont enregistrés absents.');
        } catch (Throwable $e) {
            error_log('Erreur validation appel: ' . $e->getMessage());
            jsonResponse(false, 'Impossible de valider l’appel.', [], 500);
        }
    }

    private function marquerConge(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();
        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        $motif = trim((string) post('motif_conge', ''));
        if ($motif === '' || mb_strlen($motif) > 255) jsonResponse(false, 'Indiquez un motif de congé de 1 à 255 caractères.', [], 422);
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        try {
            $result = $this->attendanceRegisterService->saveManualClosure($classeId, (int) $annee['annee_scolaire_id'], $date, $motif, (int) ($_SESSION['utilisateur_id'] ?? 0));
            if ($result === 'already_marked') jsonResponse(false, 'Des pointages existent déjà pour cette date. Corrigez-les avant de déclarer un congé.', [], 409);
        } catch (Throwable $e) {
            error_log('Erreur congé présence: ' . $e->getMessage());
            jsonResponse(false, 'Impossible d’enregistrer le congé.', [], 500);
        }
        logActivity('Congé scolaire', 'appels_presence', null, "Classe {$classeId} — {$date} — {$motif}");
        jsonResponse(true, 'Le jour de congé est enregistré; il sera barré en rouge dans le registre.');
    }

    private function annulerConge(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();
        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        $deleted = $this->attendanceRegisterService->cancelManualClosure($classeId, (int) $annee['annee_scolaire_id'], $date);
        if ($deleted === 0) jsonResponse(false, 'Aucun congé manuel à annuler pour cette date.', [], 409);
        logActivity('Réouverture', 'appels_presence', null, "Classe {$classeId} — {$date}");
        jsonResponse(true, 'Le congé manuel a été annulé. Vous pouvez maintenant faire l’appel.');
    }

    private function getElevesAppel(int $classeId, int $anneeId): array
    {
        return $this->attendanceRegisterService->findStudentsForCall($classeId, $anneeId);
    }

    private function getClassePourDate(int $classeId, string $date): array
    {
        if ($classeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !isValidDate($date)) {
            jsonResponse(false, 'Classe ou date invalide.', [], 422);
        }
        $classe = $this->attendanceRegisterService->findClassForDate($classeId);
        if (!$classe || $date < $classe['date_debut'] || $date > $classe['date_fin']) {
            jsonResponse(false, 'La date doit appartenir à l’année scolaire de cette classe.', [], 422);
        }
        return $classe;
    }

    private function requireClassAccess(int $classeId, int $anneeId): void
    {
        requirePermission('absences.consulter');
        if (hasRole('Enseignant') && !$this->attendanceRegisterService->classBelongsToTeacher((int) ($_SESSION['utilisateur_id'] ?? 0), $classeId)) {
            jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
        }
    }

}
