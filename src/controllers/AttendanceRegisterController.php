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
        if ($date !== date('Y-m-d')) {
            jsonResponse(false, 'La date de l’appel est automatiquement fixée à la date du jour.', [], 422);
        }
        $presentIds = $_POST['eleves_presents'] ?? [];
        if (!is_array($presentIds)) {
            jsonResponse(false, 'Liste de présences invalide.', [], 422);
        }
        $normalizedPresentIds = [];
        foreach ($presentIds as $presentId) {
            if (
                (!is_int($presentId) && !is_string($presentId))
                || filter_var($presentId, FILTER_VALIDATE_INT) === false
                || (int) $presentId <= 0
            ) {
                jsonResponse(false, 'La liste contient un identifiant d’élève invalide.', [], 422);
            }
            $normalizedPresentIds[] = (int) $presentId;
        }
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        if ($this->attendanceRegisterService->getClosureForDate(
            (int) $annee['annee_scolaire_id'],
            $date,
            $classeId,
            $annee['nom']
        ) !== null) {
            jsonResponse(false, 'Cette date est indiquée comme congé dans le calendrier scolaire.', [], 409);
        }
        $jourPlanifie = $this->attendanceRegisterService->isScheduledDay(
            $classeId,
            (int) $annee['annee_scolaire_id'],
            $date
        );

        $eleves = $this->getElevesAppel($classeId, (int) $annee['annee_scolaire_id']);
        if (!$eleves) {
            jsonResponse(false, 'Aucun élève actif n’est inscrit dans cette classe pour cette année.', [], 409);
        }
        $idsAutorises = array_map('intval', array_column($eleves, 'id'));
        $presentIds = array_values(array_unique($normalizedPresentIds));
        if (array_diff($presentIds, $idsAutorises)) {
            jsonResponse(false, 'La liste contient un élève qui ne fait pas partie de cette classe.', [], 422);
        }

        try {
            $this->db->beginTransaction();
            $appel = $this->db->fetchOne(
                'SELECT statut FROM appels_presence
                 WHERE classe_id = :classe AND date_appel = :date FOR UPDATE',
                ['classe' => $classeId, 'date' => $date]
            );
            if ($appel && $appel['statut'] === 'conge') {
                $this->db->rollback();
                jsonResponse(false, 'Cette journée est déjà enregistrée comme congé.', [], 409);
            }
            if ($jourPlanifie === false && (!$appel || $appel['statut'] !== 'valide')) {
                $this->db->rollback();
                jsonResponse(false, 'Cette date n’est pas un jour de classe selon l’emploi du temps.', [], 409);
            }

            foreach ($eleves as $eleve) {
                $isPresent = in_array((int) $eleve['id'], $presentIds, true);
                $this->db->query(
                    "INSERT INTO absences
                        (eleve_id, date_absence, type_absence, est_justifiee, saisie_par, created_at)
                     VALUES (:eleve, :date, :type, 0, :utilisateur, NOW())
                     ON DUPLICATE KEY UPDATE
                        type_absence = VALUES(type_absence),
                        est_justifiee = IF(VALUES(type_absence) = 'Présent', 0, est_justifiee),
                        motif = IF(VALUES(type_absence) = 'Présent', NULL, motif),
                        date_justification = IF(VALUES(type_absence) = 'Présent', NULL, date_justification),
                        saisie_par = VALUES(saisie_par)",
                    [
                        'eleve' => $eleve['id'],
                        'date' => $date,
                        'type' => $isPresent ? 'Présent' : 'Absence',
                        'utilisateur' => (int) $_SESSION['utilisateur_id']
                    ]
                );
            }
            $this->db->query(
                "INSERT INTO appels_presence
                    (classe_id, annee_scolaire_id, date_appel, statut, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'valide', :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE
                    statut = 'valide', motif_conge = NULL,
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                [
                    'classe' => $classeId,
                    'annee' => $annee['annee_scolaire_id'],
                    'date' => $date,
                    'utilisateur' => (int) $_SESSION['utilisateur_id']
                ]
            );
            $this->db->commit();
            logActivity('Pointage', 'appels_presence', null, "Appel validé — classe {$classeId} — {$date}");
            jsonResponse(true, 'Appel validé. Les élèves non cochés sont enregistrés absents.');
        } catch (Throwable $e) {
            $this->db->rollback();
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
        if ($motif === '' || mb_strlen($motif) > 255) {
            jsonResponse(false, 'Indiquez un motif de congé de 1 à 255 caractères.', [], 422);
        }
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        $this->db->beginTransaction();
        try {
            $appel = $this->db->fetchOne(
                'SELECT statut FROM appels_presence
                 WHERE classe_id = :classe AND date_appel = :date FOR UPDATE',
                ['classe' => $classeId, 'date' => $date]
            );
            $marquesExistantes = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM absences a
                 INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                   AND a.date_absence = :date",
                ['classe' => $classeId, 'annee' => $annee['annee_scolaire_id'], 'date' => $date]
            );
            if (($appel && $appel['statut'] === 'valide') || $marquesExistantes > 0) {
                $this->db->rollback();
                jsonResponse(false, 'Des pointages existent déjà pour cette date. Corrigez-les avant de déclarer un congé.', [], 409);
            }

            $this->db->query(
                "INSERT INTO appels_presence
                    (classe_id, annee_scolaire_id, date_appel, statut, motif_conge, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'conge', :motif, :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE
                    statut = 'conge', motif_conge = VALUES(motif_conge),
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                [
                    'classe' => $classeId,
                    'annee' => $annee['annee_scolaire_id'],
                    'date' => $date,
                    'motif' => $motif,
                    'utilisateur' => (int) $_SESSION['utilisateur_id']
                ]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
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

        $deleted = $this->db->delete(
            "DELETE FROM appels_presence
             WHERE classe_id = :classe AND annee_scolaire_id = :annee
               AND date_appel = :date AND statut = 'conge'",
            ['classe' => $classeId, 'annee' => $annee['annee_scolaire_id'], 'date' => $date]
        );
        if ($deleted === 0) {
            jsonResponse(false, 'Aucun congé manuel à annuler pour cette date.', [], 409);
        }
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
