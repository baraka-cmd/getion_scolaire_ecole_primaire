<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AnneeScolaireService.php';
require_once __DIR__ . '/../src/services/ClasseService.php';
require_once __DIR__ . '/../src/services/MatiereService.php';
require_once __DIR__ . '/../src/services/SchoolSettingsService.php';

final class SchoolYearQueryRepositoryStub extends AnneeScolaireRepository
{
    public array $calls = [];
    public function __construct() {}
    public function countLabel(string $label, ?int $excludeId = null): int
    {
        $this->calls[] = ['label', $label, $excludeId];
        return 2;
    }
    public function countOverlappingDates(string $start, string $end, ?int $excludeId = null): int
    {
        $this->calls[] = ['overlap', $start, $end, $excludeId];
        return 1;
    }
}

final class ClassListRepositoryStub extends ClasseRepository
{
    public array $received = [];
    public function __construct() {}
    public function findPaginatedList(string $search, int $schoolYearId, string $orderBy, string $direction, int $limit, int $offset): array
    {
        $this->received = func_get_args();
        return ['total' => 1, 'classes' => [['id' => 8, 'nom' => 'CM1']]];
    }
}

final class SubjectHierarchyRepositoryStub extends MatiereRepository
{
    public array $received = [];
    public function __construct() {}
    public function findPaginatedGroupHierarchy(string $search, int $limit, int $offset): array
    {
        $this->received = func_get_args();
        return ['total' => 1, 'groupes' => [['id' => 3, 'sous_matieres' => []]]];
    }
}

final class SchoolSettingsRepositoryStub extends SchoolSettingsRepository
{
    public array $saved = [];
    public function __construct() {}
    public function findSettings(): ?array { return ['id' => 1, 'nom_ecole' => 'École test']; }
    public function findValidYearAndTerm(int $yearId, int $termId): bool { return $yearId === 2026 && $termId === 7; }
    public function saveSettings(array $settings): void { $this->saved = $settings; }
}

$yearRepo = new SchoolYearQueryRepositoryStub();
$yearService = new AnneeScolaireService($yearRepo);
if ($yearService->countLabel('2026-2027', 9) !== 2 || $yearService->countOverlappingDates('2026-09-01', '2027-06-30', 9) !== 1) {
    throw new RuntimeException('School-year service query delegation failed.');
}
if ($yearRepo->calls !== [
    ['label', '2026-2027', 9],
    ['overlap', '2026-09-01', '2027-06-30', 9],
]) {
    throw new RuntimeException('School-year service arguments changed.');
}

$classRepo = new ClassListRepositoryStub();
$classService = new ClasseService($classRepo);
$classResult = $classService->getPaginatedList('CM', 4, 'niveau', 'ASC', 20, 0);
if ($classResult['total'] !== 1 || $classResult['classes'][0]['id'] !== 8 || $classRepo->received !== ['CM', 4, 'niveau', 'ASC', 20, 0]) {
    throw new RuntimeException('Class listing service contract changed.');
}

$subjectRepo = new SubjectHierarchyRepositoryStub();
$subjectService = new MatiereService($subjectRepo);
$subjectResult = $subjectService->getPaginatedGroupHierarchy('Math', 15, 30);
if ($subjectResult['total'] !== 1 || $subjectRepo->received !== ['Math', 15, 30]) {
    throw new RuntimeException('Subject hierarchy service contract changed.');
}

$settingsRepo = new SchoolSettingsRepositoryStub();
$settingsService = new SchoolSettingsService($settingsRepo);
if ($settingsService->getSettings()['nom_ecole'] !== 'École test' || !$settingsService->hasValidYearAndTerm(2026, 7)) {
    throw new RuntimeException('School settings service read contract changed.');
}
$settingsService->saveSettings(['nom_ecole' => 'École témoin', 'annee_id' => 2026, 'term_id' => 7]);
if ($settingsRepo->saved['nom_ecole'] !== 'École témoin') {
    throw new RuntimeException('School settings service write delegation failed.');
}

fwrite(STDOUT, "School reference MVC smoke tests passed.\n");
