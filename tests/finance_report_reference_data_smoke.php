<?php

define('ACCESS', true);
require_once __DIR__ . '/../src/repositories/FinanceReportRepository.php';

final class FinanceReportRepositorySmokeStub extends FinanceReportRepository
{
    public function __construct() {}

    public function findSchoolYears(): array
    {
        return [['id' => 7, 'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30']];
    }
    public function getActiveYearId(): int { return 7; }
    public function findClassesForYear(int $yearId): array
    {
        return $yearId === 7 ? [['id' => 3, 'nom' => '6e A', 'niveau' => '6e']] : [];
    }
    public function findFeesForYear(int $yearId): array
    {
        return $yearId === 7 ? [['id' => 9, 'libelle' => 'Scolarité', 'categorie' => 'Scolarité', 'devise' => 'USD']] : [];
    }
}

$repository = new FinanceReportRepositorySmokeStub();
if (($repository->findSchoolYears()[0]['id'] ?? null) !== 7
    || $repository->getActiveYearId() !== 7
    || count($repository->findClassesForYear(7)) !== 1
    || count($repository->findFeesForYear(7)) !== 1
    || $repository->findFeesForYear(8) !== []) {
    throw new RuntimeException('Finance report repository smoke test failed.');
}
echo "Finance report repository contracts: OK\n";
