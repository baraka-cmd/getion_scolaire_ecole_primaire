<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/DashboardService.php';
require_once __DIR__ . '/../src/services/StatisticsService.php';

final class InMemoryDashboardRepository extends DashboardRepository
{
    public function __construct() {}

    public function getActiveYearId(): int
    {
        return 4;
    }

    public function monthlyPayments(int $yearId): array
    {
        return [
            ['mois' => 1, 'total' => 100],
            ['mois' => 12, 'total' => 250],
        ];
    }

    public function presencePerClass(int $yearId): array
    {
        return [
            ['nom' => 'CM1', 'presents' => 8, 'total' => 10],
        ];
    }

    public function genderDistribution(int $yearId): array
    {
        return [12, 15];
    }

    public function termResults(int $yearId): array
    {
        return [
            ['libelle' => 'T1', 'reussite' => 2, 'ajournement' => 1, 'echec' => 0],
        ];
    }
}

$dashboardService = new DashboardService(new InMemoryDashboardRepository());
$payments = $dashboardService->monthlyPayments(4);
if ($payments[0] !== 100.0 || $payments[11] !== 250.0) {
    throw new RuntimeException('Monthly dashboard payments were not normalized.');
}
$presence = $dashboardService->presencePerClass(4);
if ($presence['labels'] !== ['CM1'] || $presence['values'] !== [80.0]) {
    throw new RuntimeException('Class presence calculation changed.');
}
if ($dashboardService->genderDistribution(4) !== [12, 15]) {
    throw new RuntimeException('Gender distribution changed.');
}
$termResults = $dashboardService->termResults(4);
if ($termResults['reussite'] !== [2]) {
    throw new RuntimeException('Term results changed.');
}

final class InMemoryStatisticsRepository extends StatisticsRepository
{
    public function __construct() {}

    public function getActiveYearId(): int
    {
        return 4;
    }

    public function getYears(): array
    {
        return [['id' => 4, 'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-07-31', 'est_active' => 1]];
    }

    public function getClasses(int $yearId): array
    {
        return [['id' => 3, 'nom' => 'CM1']];
    }

    public function getTerms(int $yearId): array
    {
        return [['id' => 7, 'libelle' => 'T1', 'numero' => 1]];
    }

    public function getActiveTermId(int $yearId): ?int
    {
        return 7;
    }

    public function getYear(int $yearId): ?array
    {
        return $yearId === 4 ? ['id' => 4, 'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-07-31'] : null;
    }

    public function getClass(int $classId, int $yearId): ?array
    {
        return $classId === 3 && $yearId === 4 ? ['id' => 3] : null;
    }

    public function getTerm(int $termId, int $yearId): ?array
    {
        return $termId === 7 && $yearId === 4 ? ['id' => 7, 'libelle' => 'T1'] : null;
    }
}

$statisticsService = new StatisticsService(new InMemoryStatisticsRepository());
$options = $statisticsService->getFilterOptions();
if ($options['annee_id'] !== 4 || $options['trimestre_id'] !== 7 || !$statisticsService->validateClass(3, 4)) {
    throw new RuntimeException('Statistics filter options changed.');
}

fwrite(STDOUT, "Dashboard and statistics service smoke tests passed.\n");
