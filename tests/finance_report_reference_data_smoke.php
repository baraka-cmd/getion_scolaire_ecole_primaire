<?php

define('ACCESS', true);
require_once __DIR__ . '/../src/services/FinanceReportService.php';

final class FinanceReportRepositoryStub extends FinanceReportRepository
{
    public function findSchoolYears(): array
    {
        return [
            ['id' => 2, 'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30'],
            ['id' => 1, 'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30'],
        ];
    }

    public function getActiveYearId(): int { return 2; }
    public function findClassesForYear(int $yearId): array
    {
        return $yearId === 2 ? [['id' => 10, 'nom' => '6e A', 'niveau' => '6e']] : [];
    }
    public function findFeesForYear(int $yearId): array
    {
        return $yearId === 2 ? [['id' => 20, 'libelle' => 'Scolarité', 'classe_id' => null,
            'est_actif' => 1, 'est_obligatoire' => 1, 'categorie' => 'Scolarité',
            'devise' => 'USD', 'periodicite' => 'annuel', 'classe_nom' => null]] : [];
    }
}

$service = new FinanceReportService(null, new FinanceReportRepositoryStub());
$result = $service->build([]);
if (($result['year']['id'] ?? null) !== 2 || count($result['classes']) !== 1 || count($result['fees']) !== 1) {
    throw new RuntimeException('Finance report reference-data delegation smoke test failed.');
}
echo "Finance report reference-data delegation: OK\n";
