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
}
