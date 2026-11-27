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
    public function summaryStats(): array
    {
        return $this->repository->summaryStats();
    }

    public function exportRows(array $filters, string $role, int $userId): array
    {
        return $this->repository->exportRows($filters, $role, $userId);
    }


    public function updateProfile(int $studentId, array $data): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Identifiant élève invalide.');
        }
        $this->repository->updateProfile($studentId, $data);
    }

    public function deactivate(int $studentId, string $reason): void
    {
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Identifiant élève invalide.');
        }
        $this->repository->deactivate($studentId, $reason !== '' ? $reason : 'Désactivation');
    }

    public function transferEnrollment(int $studentId, int $oldClassId, int $newClassId, string $reason): array
    {
        if ($studentId <= 0 || $newClassId <= 0 || $oldClassId <= 0 || $newClassId === $oldClassId) {
            throw new InvalidArgumentException('Paramètres de transfert invalides.');
        }
        return $this->repository->transferEnrollment(
            $studentId,
            $oldClassId,
            $newClassId,
            $reason !== '' ? $reason : 'Changement de classe'
        );
    }

}
