<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/InscriptionRepository.php';

class InscriptionService
{
    private InscriptionRepository $repository;

    public function __construct(?InscriptionRepository $repository = null)
    {
        $this->repository = $repository ?? new InscriptionRepository();
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

}
