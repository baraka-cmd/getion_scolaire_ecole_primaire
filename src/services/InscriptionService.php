<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/InscriptionRepository.php';
require_once __DIR__ . '/AcademicResultsService.php';

class InscriptionService
{
    private InscriptionRepository $repository;
    private AcademicResultsService $academicResults;

    public function __construct(?InscriptionRepository $repository = null, ?AcademicResultsService $academicResults = null)
    {
        $this->repository = $repository ?? new InscriptionRepository();
        $this->academicResults = $academicResults ?? new AcademicResultsService();
    }

    public function paginate(array $filters, int $page, int $perPage): array
    {
        return $this->repository->paginate($filters, max(1, $page), max(1, $perPage));
    }

    public function getStudentHistory(int $studentId): array
    {
        if ($studentId <= 0) {
            return ['eleve' => null, 'historique' => []];
        }
        return $this->repository->studentHistory($studentId);
    }

    public function getAvailableClasses(int $yearId): array
    {
        return $this->repository->availableClasses(max(0, $yearId));
    }

    public function getSchoolYears(): array
    {
        return $this->repository->schoolYears();
    }

    public function getActiveClasses(): array
    {
        return $this->repository->activeClasses();
    }

    public function getUnenrolledStudents(): array
    {
        return $this->repository->unenrolledStudents();
    }

    public function findEnrollment(int $id): ?array
    {
        return $id > 0 ? $this->repository->findEnrollment($id) : null;
    }

    public function getEnrollmentHistory(int $studentId): array
    {
        return $studentId > 0 ? $this->repository->enrollmentHistory($studentId) : [];
    }
    
    public function createEnrollment(int $studentId, int $classId, string $status = 'Actif'): array
    {
        $errors = [];
        if ($studentId <= 0) $errors['eleve_id'] = 'Veuillez sélectionner un élève.';
        if ($classId <= 0) $errors['classe_id'] = 'Veuillez sélectionner une classe.';
        $yearId = $this->repository->activeYearId();
        if (!$yearId) return ['ok' => false, 'errors' => $errors, 'message' => 'Aucune année scolaire active.'];
        if ($status !== 'Actif') $status = 'Actif';
        if ($errors) return ['ok' => false, 'errors' => $errors];
        return $this->repository->createEnrollmentSafely($studentId, $classId, $yearId, $status);
    }

    public function changeClass(int $studentId, int $oldClassId, int $newClassId, string $reason = 'Changement de classe'): array
    {
        if ($studentId <= 0 || $oldClassId <= 0 || $newClassId <= 0) {
            return ['ok' => false, 'status' => 400, 'message' => 'Paramètres invalides.'];
        }
        $yearId = $this->repository->activeYearId();
        if (!$yearId) return ['ok' => false, 'status' => 409, 'message' => 'Aucune année scolaire active.'];
        return $this->repository->transferEnrollment($studentId, $oldClassId, $newClassId, $yearId, trim($reason) ?: 'Changement de classe');
    }

    public function discontinue(int $enrollmentId, string $status, string $reason): array
    {
        $allowed = ['Abandon', 'Exclu', 'Diplômé'];
        if ($enrollmentId <= 0) return ['ok' => false, 'status' => 400, 'message' => 'ID inscription invalide.'];
        if (!in_array($status, $allowed, true)) return ['ok' => false, 'status' => 400, 'message' => 'Statut invalide.'];
        if (trim($reason) === '') return ['ok' => false, 'status' => 422, 'message' => 'Le motif est obligatoire.'];
        $updated = $this->repository->updateEnrollmentStatus($enrollmentId, $status, trim($reason));
        if ($updated !== 1) return ['ok' => false, 'status' => 409, 'message' => 'Inscription introuvable ou déjà clôturée.'];
        return ['ok' => true];
    }

    public function getEnrollmentStatistics(): array
    {
        return $this->repository->enrollmentStatistics();
    }

    public function getClassesForTransition(): array
    {
        return $this->repository->classesForTransition();
    }

    public function getDestinationSchoolYears(): array
    {
        return $this->repository->destinationSchoolYears();
    }

    
    public function getTransitionCandidates(int $sourceClassId, int $sourceYearId, int $destinationYearId): array
    {
        if ($sourceClassId <= 0 || $sourceYearId <= 0 || $destinationYearId <= 0) {
            return ['ok' => false, 'status' => 400, 'message' => 'Classe, année source et année de destination obligatoires.'];
        }
        $source = $this->repository->transitionSourceClass($sourceClassId, $sourceYearId);
        $destinationYear = $this->repository->schoolYear($destinationYearId);
        if (!$source || !$destinationYear) return ['ok' => false, 'status' => 404, 'message' => 'Classe source ou année de destination introuvable.'];
        if (strtotime($destinationYear['date_debut']) <= strtotime($source['source_date_debut'])) {
            return ['ok' => false, 'status' => 422, 'message' => 'L’année de destination doit être postérieure à l’année source.'];
        }

        $students = $this->repository->transitionStudents($sourceClassId, $sourceYearId);
        $annual = $this->academicResults->getAnnualResultsForClass($sourceClassId, $sourceYearId);
        $byStudent = [];
        foreach (($annual['resultats'] ?? []) as $result) $byStudent[(int) $result['eleve_id']] = $result;
        foreach ($students as &$student) {
            $result = $byStudent[(int) $student['id']] ?? null;
            $student['pourcentage_annuel'] = $result['pourcentage'] ?? null;
            $student['bulletin_complet'] = (bool) ($result['bulletin_complet'] ?? false);
            $student['notes_completees'] = (int) ($result['notes_completees'] ?? 0);
            $student['notes_attendues'] = (int) ($result['notes_attendues'] ?? 0);
            if (!$student['bulletin_complet']) $student['decision_auto'] = 'A_COMPLETER';
            elseif (!empty($result['is_admis'])) $student['decision_auto'] = (int) $source['niveau'] >= 6 ? 'Diplômé' : 'Passe';
            else $student['decision_auto'] = 'Redouble';
        }
        unset($student);
        return ['ok' => true, 'eleves' => $students, 'classe_source' => $source, 'classes_disponibles' => $this->repository->transitionDestinationClasses($destinationYearId), 'total' => count($students)];
    }

    public function executeMassTransition(array $selection, int $sourceClassId, int $sourceYearId, int $destinationYearId): array
    {
        if (!$selection || $sourceClassId <= 0 || $sourceYearId <= 0 || $destinationYearId <= 0) {
            return ['ok' => false, 'status' => 400, 'message' => 'Aucun élève sélectionné.'];
        }
        $source = $this->repository->transitionSourceClass($sourceClassId, $sourceYearId);
        $destinationYear = $this->repository->schoolYear($destinationYearId);
        if (!$source || !$destinationYear) return ['ok' => false, 'status' => 404, 'message' => 'Classe source ou année de destination introuvable.'];
        if (strtotime($destinationYear['date_debut']) <= strtotime($source['source_date_debut'])) {
            return ['ok' => false, 'status' => 422, 'message' => 'L’année de destination doit être postérieure à l’année source.'];
        }
        $annual = $this->academicResults->getAnnualResultsForClass($sourceClassId, $sourceYearId);
        $byStudent = [];
        foreach (($annual['resultats'] ?? []) as $result) $byStudent[(int) $result['eleve_id']] = $result;

        $plans = [];
        $seen = [];
        foreach ($selection as $data) {
            if (!is_array($data)) return ['ok' => false, 'status' => 400, 'message' => 'La liste des élèves contient des données invalides.'];
            $studentId = (int) ($data['eleve_id'] ?? 0);
            $sourceEnrollmentId = (int) ($data['ancienne_inscription_id'] ?? 0);
            $targetClassId = (int) ($data['nouvelle_classe_id'] ?? 0);
            if ($studentId <= 0 || $sourceEnrollmentId <= 0 || isset($seen[$studentId])) {
                return ['ok' => false, 'status' => 422, 'message' => 'Un élève est invalide ou présent plusieurs fois dans la sélection.'];
            }
            $seen[$studentId] = true;
            if (!$this->repository->validSourceEnrollment($sourceEnrollmentId, $studentId, $sourceClassId, $sourceYearId)) {
                return ['ok' => false, 'status' => 422, 'message' => "L’inscription source de l’élève {$studentId} n’est plus valide."];
            }
            $result = $byStudent[$studentId] ?? null;
            if (!$result || empty($result['bulletin_complet'])) {
                return ['ok' => false, 'status' => 422, 'message' => "Réinscription bloquée : le bulletin de l’élève {$studentId} n’est pas complet à 100 %."];
            }
            $passed = !empty($result['is_admis']);
            $graduated = $passed && (int) $source['niveau'] >= 6;
            $expectedLevel = $passed ? (int) $source['niveau'] + 1 : (int) $source['niveau'];
            if (!$graduated) {
                $target = $this->repository->validDestinationClass($targetClassId, $destinationYearId);
                if (!$target || (int) $target['niveau'] !== $expectedLevel) {
                    $decision = $passed ? 'passe en classe supérieure' : 'redouble dans la même classe';
                    return ['ok' => false, 'status' => 422, 'message' => "Destination incorrecte pour l’élève {$studentId} : il doit {$decision}."];
                }
            }
            if ($this->repository->enrollmentExistsInYear($studentId, $destinationYearId)) {
                return ['ok' => false, 'status' => 422, 'message' => "L’élève {$studentId} est déjà inscrit pour l’année de destination."];
            }
            $plans[] = ['eleve_id' => $studentId, 'inscription_source_id' => $sourceEnrollmentId, 'classe_destination_id' => $graduated ? null : $targetClassId, 'est_reussite' => $passed, 'est_diplome' => $graduated];
        }

        try {
            $totals = $this->repository->applyTransitionPlans($plans, $destinationYearId);
            return ['ok' => true] + $totals;
        } catch (DomainException $e) {
            return ['ok' => false, 'status' => 409, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Erreur réinscription massive: ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'message' => 'La réinscription a échoué et aucune modification n’a été conservée.'];
        }
    }

}
