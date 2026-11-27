<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/DashboardRepository.php';

class DashboardService
{
    private DashboardRepository $repository;

    public function __construct(?DashboardRepository $repository = null)
    {
        $this->repository = $repository ?? new DashboardRepository();
    }

    public function getActiveYearId(): int
    {
        return $this->repository->getActiveYearId();
    }

    public function getStats(int $yearId, int $userId): array
    {
        return $this->repository->getStats($yearId, $userId);
    }

    public function studentsPerClass(int $yearId): array
    {
        return $this->repository->studentsPerClass($yearId);
    }

    public function monthlyPayments(int $yearId): array
    {
        $rows = $this->repository->monthlyPayments($yearId);
        $values = array_fill(0, 12, 0);
        foreach ($rows as $row) {
            $month = (int) $row['mois'];
            if ($month >= 1 && $month <= 12) {
                $values[$month - 1] = (float) $row['total'];
            }
        }
        return $values;
    }

    public function presencePerClass(int $yearId): array
    {
        $labels = [];
        $values = [];
        foreach ($this->repository->presencePerClass($yearId) as $row) {
            $labels[] = $row['nom'];
            $total = (int) $row['total'];
            $present = (int) $row['presents'];
            $values[] = $total > 0 ? round(($present / $total) * 100, 1) : 0;
        }
        return ['labels' => $labels, 'values' => $values];
    }

    public function genderDistribution(int $yearId): array
    {
        return $this->repository->genderDistribution($yearId);
    }

    public function termResults(int $yearId): array
    {
        $rows = $this->repository->termResults($yearId);
        return [
            'labels' => array_column($rows, 'libelle'),
            'reussite' => array_map('intval', array_column($rows, 'reussite')),
            'ajournement' => array_map('intval', array_column($rows, 'ajournement')),
            'echec' => array_map('intval', array_column($rows, 'echec')),
        ];
    }

    public function latestStudents(): array
    {
        return $this->repository->latestStudents();
    }

    public function upcomingBirthdays(): array
    {
        return $this->repository->upcomingBirthdays();
    }

    public function recentActivities(): array
    {
        return $this->repository->recentActivities();
    }
}
