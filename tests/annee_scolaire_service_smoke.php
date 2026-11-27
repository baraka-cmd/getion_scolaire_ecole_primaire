<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/helpers/functions.php';
require_once __DIR__ . '/../src/services/AnneeScolaireService.php';

final class InMemoryAnneeScolaireRepository extends AnneeScolaireRepository
{
    public array $existingTerms = [];
    public array $updates = [];
    public array $creates = [];

    public function __construct()
    {
    }

    public function findTermStates(int $yearId): array
    {
        return $this->existingTerms;
    }

    public function updateTerm(array $params): void
    {
        $this->updates[] = $params;
    }

    public function createTerm(array $params): void
    {
        $this->creates[] = $params;
    }
}

$repository = new InMemoryAnneeScolaireRepository();
$service = new AnneeScolaireService($repository);

$validTerms = [
    1 => ['libelle' => '1er Trimestre', 'date_debut' => '2026-09-01', 'date_fin' => '2026-12-15'],
    2 => ['libelle' => '2ème Trimestre', 'date_debut' => '2026-12-16', 'date_fin' => '2027-03-15'],
    3 => ['libelle' => '3ème Trimestre', 'date_debut' => '2027-03-16', 'date_fin' => '2027-06-30'],
];
$validation = $service->validateTermDates($validTerms, '2026-09-01', '2027-06-30');
if ($validation['errors'] !== [] || count($validation['dates']) !== 3) {
    throw new RuntimeException('Valid school-term dates were rejected.');
}

$overlappingTerms = $validTerms;
$overlappingTerms[2]['date_debut'] = '2026-12-15';
$validation = $service->validateTermDates($overlappingTerms, '2026-09-01', '2027-06-30');
if (!isset($validation['errors']['trimestre_2'])) {
    throw new RuntimeException('Overlapping school terms were accepted.');
}

$distributedTerms = $service->distributeTerms('2026-09-01', '2027-06-30');
if (
    count($distributedTerms) !== 3
    || $distributedTerms[1]['date_debut'] !== '2026-09-01'
    || $distributedTerms[3]['date_fin'] !== '2027-06-30'
) {
    throw new RuntimeException('Automatic school-term distribution changed.');
}

$repository->existingTerms = [
    ['id' => 12, 'numero' => 2, 'est_actif' => 1],
];
$service->saveTerms(4, $validTerms);
if (
    count($repository->updates) !== 1
    || count($repository->creates) !== 2
    || $repository->creates[0]['actif'] !== 0
    || $repository->updates[0]['actif'] !== 1
) {
    throw new RuntimeException('Saving terms did not preserve the active-term selection.');
}

$repository->existingTerms = [];
$repository->updates = [];
$repository->creates = [];
$service->saveTerms(5, $validTerms);
if ($repository->creates[0]['actif'] !== 1) {
    throw new RuntimeException('The first term was not activated when no term was active.');
}

fwrite(STDOUT, "School-year service smoke tests passed.\n");
