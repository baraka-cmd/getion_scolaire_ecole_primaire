<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/StudentRecordRepository.php';

class StudentRecordService
{
    private StudentRecordRepository $repository;

    public function __construct(?StudentRecordRepository $repository = null)
    {
        $this->repository = $repository ?? new StudentRecordRepository();
    }

    public function paginate(array $filters, int $page, int $perPage, string $role, int $userId): array
    {
        return $this->repository->paginate($filters, max(1, $page), max(1, $perPage), $role, $userId);
    }

    public function findRecord(int $studentId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }
        return $this->repository->findRecord($studentId);
    }

    public function findDetailForForm(int $studentId): ?array
    {
        return $studentId > 0 ? $this->repository->findDetailForForm($studentId) : null;
    }

    public function getActiveClassesForForms(): array
    {
        return $this->repository->getActiveClassesForForms();
    }

    public function enrollmentHistory(int $studentId): array
    {
        return $studentId > 0 ? $this->repository->enrollmentHistory($studentId) : [];
    }

    public function getAcademicAndAttendanceHistory(int $studentId): array
    {
        if ($studentId <= 0) {
            return ['notes' => [], 'absences' => [], 'paiements' => []];
        }

        return [
            'notes' => $this->repository->recentGrades($studentId),
            'absences' => $this->repository->recentAbsences($studentId),
            'paiements' => $this->repository->payments($studentId),
        ];
    }
}
