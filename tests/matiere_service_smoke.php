<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/MatiereService.php';

final class InMemoryMatiereRepository extends MatiereRepository
{
    public ?array $scoreData = null;
    public bool $associationExists = false;
    public int $evaluationCount = 0;
    public array $operations = [];

    public function __construct()
    {
    }

    public function findScoringData(int $studentId, int $subjectId, int $termId): ?array
    {
        return $this->scoreData;
    }

    public function hasClassAssociation(int $subjectId, int $classId): bool
    {
        return $this->associationExists;
    }

    public function updateClassAssociation(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->operations[] = ['update', $subjectId, $classId, $maxima];
    }

    public function createClassAssociation(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->operations[] = ['create', $subjectId, $classId, $maxima];
    }

    public function countEvaluationsForClass(int $subjectId, int $classId): int
    {
        return $this->evaluationCount;
    }

    public function deactivateClassAssociation(int $subjectId, int $classId): void
    {
        $this->operations[] = ['deactivate', $subjectId, $classId];
    }

    public function deleteClassAssociation(int $subjectId, int $classId): void
    {
        $this->operations[] = ['delete', $subjectId, $classId];
    }
}

$repository = new InMemoryMatiereRepository();
$service = new MatiereService($repository);

$repository->scoreData = [
    'maxima' => 20,
    'periode_1' => '10.50',
    'periode_2' => null,
    'examen' => '15',
];
$score = $service->calculateSubSubjectScore(1, 2, 3);
if ($score !== ['total_obtenu' => 25.5, 'total_maxima' => 60, 'pourcentage' => 42.5]) {
    throw new RuntimeException('Sub-subject score calculation changed.');
}

$repository->scoreData = ['maxima' => 20, 'periode_1' => null, 'periode_2' => null, 'examen' => null];
if ($service->calculateSubSubjectScore(1, 2, 3) !== null) {
    throw new RuntimeException('A subject with no scores should return null.');
}

$repository->scoreData = null;
if ($service->calculateSubSubjectScore(1, 2, 3) !== null) {
    throw new RuntimeException('A missing subject should return null.');
}

$service->associateClass(2, 5, 25);
if ($repository->operations !== [['create', 2, 5, 25]]) {
    throw new RuntimeException('A new class-subject association was not created.');
}

$repository->associationExists = true;
$service->associateClass(2, 5, null);
if (end($repository->operations) !== ['update', 2, 5, null]) {
    throw new RuntimeException('An existing class-subject association was not reactivated.');
}

$repository->evaluationCount = 1;
if (!$service->dissociateClass(2, 5) || end($repository->operations) !== ['deactivate', 2, 5]) {
    throw new RuntimeException('An association with existing evaluations was not retained inactive.');
}

$repository->evaluationCount = 0;
if ($service->dissociateClass(2, 5) || end($repository->operations) !== ['delete', 2, 5]) {
    throw new RuntimeException('An unused class-subject association was not deleted.');
}

fwrite(STDOUT, "Subject service smoke tests passed.\n");
