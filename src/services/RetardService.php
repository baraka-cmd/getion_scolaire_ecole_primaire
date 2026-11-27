<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/RetardRepository.php';

class RetardService
{
    private RetardRepository $repository;

    public function __construct(?RetardRepository $repository = null)
    {
        $this->repository = $repository ?? new RetardRepository();
    }

    public function calculateDuration(string $expectedTime, string $arrivalTime): int
    {
        $expected = strtotime($expectedTime);
        $arrival = strtotime($arrivalTime);

        if ($expected === false || $arrival === false) {
            return 0;
        }

        $difference = $arrival - $expected;
        return $difference > 0 ? (int) round($difference / 60) : 0;
    }

    public function findActiveStudent(int $studentId): ?array
    {
        return $this->repository->findActiveStudent($studentId);
    }

    public function existsForDate(int $studentId, string $date): bool
    {
        return (bool) $this->repository->findRetardId($studentId, $date);
    }

    public function createRetard(
        int $studentId,
        string $date,
        string $arrivalTime,
        string $expectedTime,
        int $duration,
        string $reason,
        int $justified,
        int $userId
    ): int {
        return $this->repository->createRetard(
            $studentId,
            $date,
            $arrivalTime,
            $expectedTime,
            $duration,
            $reason ?: null,
            $justified,
            $userId
        );
    }

    public function countForStudent(int $studentId): int
    {
        return $this->repository->countForStudent($studentId);
    }

    public function justify(int $retardId, string $reason): bool
    {
        if (!$this->repository->exists($retardId)) {
            return false;
        }

        $this->repository->justify($retardId, $reason);
        return true;
    }

    public function delete(int $retardId): void
    {
        $this->repository->delete($retardId);
    }

    public function getList(array $filters, int $page, int $perPage): array
    {
        return $this->repository->listRetards($filters, $page, $perPage);
    }

    public function getStatistics(int $classId, int $trimesterId): array
    {
        return $this->repository->getRetardStatistics($classId, $trimesterId);
    }

    public function getStudentHistory(int $studentId): array
    {
        return $this->repository->findStudentRetards($studentId);
    }

    public function findStudentIdForRetard(int $retardId): ?int
    {
        return $this->repository->findStudentIdForRetard($retardId);
    }

    public function getActiveClasses(): array
    {
        return $this->repository->getActiveClasses();
    }

    public function getTeacherClass(int $userId): int
    {
        return $this->repository->findTeacherClass($userId);
    }

    public function getDashboardStatistics(string $today): array
    {
        return $this->repository->getDashboardCounts($today);
    }

    public function getGlobalStatistics(): array
    {
        return $this->repository->getGlobalStatistics();
    }


    public function studentBelongsToClass(int $studentId, int $classId): bool
    {
        return $this->repository->studentBelongsToClass($studentId, $classId);
    }

    public function getMarkingData(int $classId, string $date): array
    {
        return $this->repository->getMarkingData($classId, $date);
    }

}
