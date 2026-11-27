<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AcademicResultsService.php';

final class InMemoryAcademicResultsRepository extends AcademicResultsRepository
{
    public array $termRows = [];
    public array $groupRows = [];
    public array $coverageRows = [];
    public ?array $term = null;
    public array $scoreRows = [];
    public int $scoreFetchCount = 0;

    public function __construct()
    {
    }

    public function findTermsByYear(int $yearId): array
    {
        return $this->termRows;
    }

    public function findSubjectGroupRows(int $classId): array
    {
        return $this->groupRows;
    }

    public function findAnnualGradeCoverageRows(int $classId, array $trimesterIds, array $groupIds): array
    {
        return $this->coverageRows;
    }

    public function findTerm(int $termId): ?array
    {
        return $this->term;
    }

    public function findQuarterScoreRows(int $termId, int $classId): array
    {
        $this->scoreFetchCount++;
        return $this->scoreRows;
    }
}

$repository = new InMemoryAcademicResultsRepository();
$service = new AcademicResultsService($repository);

$repository->termRows = [
    ['id' => 21, 'numero' => 1, 'libelle' => 'T1'],
    ['id' => 22, 'numero' => 2, 'libelle' => 'T2'],
];
if (array_keys($service->getTrimestresByYear(4)) !== [1, 2]) {
    throw new RuntimeException('Terms were not keyed by their trimester number.');
}

$repository->groupRows = [
    [
        'groupe_id' => '3',
        'groupe_libelle' => 'Langues',
        'groupe_code' => 'L',
        'sous_matiere_id' => '8',
        'sous_matiere_libelle' => 'Français',
        'sous_matiere_code' => 'FR',
        'maxima_periode' => '20.00',
    ],
];
$groups = $service->getSubjectGroupsForClass(6);
if (
    count($groups) !== 1
    || $groups[0]['id'] !== 3
    || $groups[0]['sous_matieres'][0]['id'] !== 8
    || $groups[0]['sous_matieres'][0]['maxima_periode'] !== 20.0
) {
    throw new RuntimeException('Subject rows were not grouped into the expected result shape.');
}

$repository->coverageRows = [
    ['eleve_id' => '12', 'notes_completees' => '14'],
    ['eleve_id' => '13', 'notes_completees' => '4'],
];
$coverage = $service->getAnnualGradeCoverage(6, [21, 22, 23], $groups);
if ($coverage !== ['expected' => 9, 'students' => [12 => 9, 13 => 4]]) {
    throw new RuntimeException('Annual grade coverage was not capped at the expected total.');
}

$repository->term = ['id' => 21, 'numero' => 1, 'annee_scolaire_id' => 4];
if ($service->resolveAssessment(21, 'Période 2')['composante'] !== 'p2') {
    throw new RuntimeException('Display assessment mapping did not resolve correctly.');
}

$repository->scoreRows = [
    ['eleve_id' => 12, 'sous_matiere_id' => 8, 'p1' => '12', 'p2' => '15', 'examen' => '30'],
];
$firstQuarter = $service->calculateSubjectQuarter(12, 6, 8, 21, 20.0);
$secondQuarter = $service->calculateSubjectQuarter(12, 6, 8, 21, 20.0);
if (
    $firstQuarter['total_obt'] !== 57.0
    || $secondQuarter !== $firstQuarter
    || $repository->scoreFetchCount !== 1
) {
    throw new RuntimeException('Quarter score calculation or cache behavior changed.');
}

fwrite(STDOUT, "Academic results service smoke tests passed.\n");
