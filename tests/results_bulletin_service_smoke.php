<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/ResultsBulletinService.php';

final class FakeResultsBulletinRepository extends ResultsBulletinRepository
{
    public array $calls = [];

    public function __construct() {}

    public function findActiveSchoolYearId(): int
    {
        $this->calls[] = ['active-year'];
        return 2030;
    }

    public function findStudentForResults(int $studentId, int $yearId): ?array
    {
        $this->calls[] = ['results-student', $studentId, $yearId];
        return ['id' => $studentId, 'classe_id' => 6, 'date_naissance_format' => '01/01/2015', 'age' => 15];
    }

    public function findStudentForBulletin(int $studentId, int $yearId): ?array
    {
        $this->calls[] = ['bulletin-student', $studentId, $yearId];
        return ['id' => $studentId, 'classe_id' => 6];
    }

    public function findSchoolYear(int $yearId): ?array
    {
        $this->calls[] = ['school-year', $yearId];
        return ['id' => $yearId, 'libelle' => '2030-2031'];
    }

    public function findSchoolParameters(): ?array
    {
        $this->calls[] = ['school-parameters'];
        return ['nom_ecole' => 'GASON'];
    }
}

$repository = new FakeResultsBulletinRepository();
$service = new ResultsBulletinService($repository);

if ($service->getActiveSchoolYearId() !== 2030
    || $service->getStudentForResults(12, 2030)['classe_id'] !== 6
    || $service->getStudentForBulletin(12, 2030)['id'] !== 12
    || $service->getSchoolYear(2030)['libelle'] !== '2030-2031'
    || $service->getSchoolParameters()['nom_ecole'] !== 'GASON') {
    throw new RuntimeException('Results/bulletins context was not delegated or its returned shape changed.');
}

if ($service->getStudentForResults(0, 2030) !== null
    || $service->getStudentForBulletin(12, 0) !== null
    || $service->getSchoolYear(0) !== null) {
    throw new RuntimeException('Invalid identifiers must not reach the repository.');
}

if ($repository->calls !== [
    ['active-year'],
    ['results-student', 12, 2030],
    ['bulletin-student', 12, 2030],
    ['school-year', 2030],
    ['school-parameters'],
]) {
    throw new RuntimeException('Repository call contract changed.');
}

fwrite(STDOUT, "Results/bulletins service smoke tests passed.\n");
