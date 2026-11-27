<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AbsenceRepository.php';

class AbsenceService
{
    private AbsenceRepository $repository;

    public function __construct(?AbsenceRepository $repository = null)
    {
        $this->repository = $repository ?? new AbsenceRepository();
    }

    public function markStatus(
        int $studentId,
        string $date,
        string $type,
        string $reason,
        int $userId
    ): bool {
        if (!$this->repository->hasActiveEnrollment($studentId)) {
            return false;
        }

        $absenceId = $this->repository->findAbsenceId($studentId, $date);
        if ($absenceId !== null) {
            $this->repository->updateStatus($absenceId, $type, $reason !== '' ? $reason : null, $userId);
        } else {
            $this->repository->createStatus($studentId, $date, $type, $reason !== '' ? $reason : null, $userId);
        }

        return true;
    }

    public function saveReason(int $studentId, string $date, string $reason): bool
    {
        if ($this->repository->findAbsenceId($studentId, $date) === null) {
            return false;
        }

        $this->repository->updateReason($studentId, $date, $reason !== '' ? $reason : null);
        return true;
    }

    public function justifyAbsence(
        int $studentId,
        string $date,
        string $reason,
        int $userId
    ): ?int {
        $absenceId = $this->repository->findAbsenceIdForJustification($studentId, $date);
        $completeReason = $reason;

        if ($absenceId === null) {
            $this->repository->createJustifiedAbsence($studentId, $date, $completeReason, $userId);
            return null;
        }

        $this->repository->updateJustification($absenceId, $completeReason);
        return $absenceId;
    }

    public function findStudentForAbsence(int $absenceId): ?int
    {
        return $this->repository->findAbsenceStudentId($absenceId);
    }

    public function justifyAbsenceById(int $absenceId, string $reason): void
    {
        $this->repository->updateJustification($absenceId, $reason);
    }

    public function getAbsenceList(
        array $filters,
        int $userId,
        string $role,
        int $page,
        int $perPage
    ): array {
        return $this->repository->listAbsences($filters, $userId, $role, $page, $perPage);
    }

    public function getStatistics(array $filters, int $userId, string $role): array
    {
        $statistics = $this->repository->getStatistics($filters, $userId, $role);
        $statistics['absences_non_justifiees'] =
            $statistics['total_absences'] - $statistics['absences_justifiees'];
        return $statistics;
    }

    public function getStudentAbsences(int $studentId): array
    {
        $absences = $this->repository->findStudentAbsences($studentId);
        return array_merge(['absences' => $absences], self::calculateStudentStatistics($absences));
    }

    public static function calculateStudentStatistics(array $absences): array
    {
        $totalDays = count($absences);
        $totalAbsences = count(array_filter(
            $absences,
            static fn(array $absence): bool => $absence['type_absence'] === 'Absence'
        ));
        $totalJustified = count(array_filter(
            $absences,
            static fn(array $absence): bool => $absence['est_justifiee'] == 1
        ));

        return [
            'total_jours' => $totalDays,
            'total_absences' => $totalAbsences,
            'total_justifiees' => $totalJustified,
            'taux_absenteisme' => $totalDays > 0 ? round(($totalAbsences / $totalDays) * 100, 1) : 0,
        ];
    }

    public function getActiveClasses(): array
    {
        return $this->repository->getActiveClasses();
    }

    public function getTeacherClass(int $userId): int
    {
        return $this->repository->findTeacherClass($userId);
    }

    public function getDashboardStatistics(string $date): array
    {
        $counts = $this->repository->getDashboardCounts($date);
        $totalMarked = $counts['absences'] + $counts['presences'];

        return [
            'absences_jour' => $counts['absences'],
            'presences_jour' => $counts['presences'],
            'total_marques' => $totalMarked,
            'taux_presence' => $totalMarked > 0
                ? round(($counts['presences'] / $totalMarked) * 100, 1)
                : 0,
        ];
    }

    public function getMarkingData(int $classId, string $date): array
    {
        return $this->repository->getMarkingData($classId, $date);
    }


    public function getActiveTrimesters(): array { return $this->repository->getActiveTrimesters(); }
    public function getTeacherClassOverview(int $userId): ?array { return $this->repository->getTeacherClassOverview($userId); }
    public function getClassMonthStatistics(int $classId): array { return $this->repository->getClassMonthStatistics($classId); }
    public function getParentProfileId(int $userId): int { return $this->repository->findParentProfileId($userId); }
    public function getParentChildren(int $parentId): array { return $this->repository->findParentChildren($parentId); }
    public function getParentAbsences(int $studentId): array { return $this->repository->findParentAbsences($studentId); }
    public function getClassStudents(int $classId): array { return $this->repository->findClassStudents($classId); }
    public function getStudentAbsencesForJustification(int $studentId): array { return $this->repository->findStudentAbsencesForJustification($studentId); }

}
