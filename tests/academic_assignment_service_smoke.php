<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/EnseignantService.php';
require_once __DIR__ . '/../src/services/ClasseService.php';

final class InMemoryEnseignantRepository extends EnseignantRepository
{
    public array $conflicts = [];
    public array $updates = [];

    public function __construct()
    {
    }

    public function findOtherActiveTeacherIdForClass(int $classId, int $excludeTeacherId): mixed
    {
        return $this->conflicts[$classId] ?? null;
    }

    public function updateClassAssignment(int $teacherId, ?int $classId): void
    {
        $this->updates[] = [$teacherId, $classId];
    }
}

final class InMemoryClasseRepository extends ClasseRepository
{
    public array $operations = [];

    public function __construct()
    {
    }

    public function releaseClassHolder(int $classId): void
    {
        $this->operations[] = ['release-class', $classId];
    }

    public function releaseTeacherFromClass(int $teacherId): void
    {
        $this->operations[] = ['release-teacher', $teacherId];
    }

    public function assignTeacherToClass(int $teacherId, int $classId): void
    {
        $this->operations[] = ['assign', $teacherId, $classId];
    }
}

$teacherRepository = new InMemoryEnseignantRepository();
$teacherService = new EnseignantService($teacherRepository);
$teacherRepository->conflicts[3] = 9;

if ($teacherService->changeClass(5, 3) || $teacherRepository->updates !== []) {
    throw new RuntimeException('A teacher was assigned to a class with another active holder.');
}
if (!$teacherService->changeClass(5, 4) || $teacherRepository->updates !== [[5, 4]]) {
    throw new RuntimeException('A teacher class assignment did not update correctly.');
}
if (!$teacherService->changeClass(5, 0) || $teacherRepository->updates[1] !== [5, null]) {
    throw new RuntimeException('Clearing a teacher class assignment did not update correctly.');
}

$classRepository = new InMemoryClasseRepository();
$classService = new ClasseService($classRepository);
$classService->changeHolder(12, 8);
$classService->changeHolder(12, 0);

$expected = [
    ['release-class', 12],
    ['release-teacher', 8],
    ['assign', 8, 12],
    ['release-class', 12],
];
if ($classRepository->operations !== $expected) {
    throw new RuntimeException('Class holder assignment operations changed.');
}

fwrite(STDOUT, "Academic assignment service smoke tests passed.\n");
