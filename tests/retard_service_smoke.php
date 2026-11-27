<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/RetardService.php';

final class InMemoryRetardRepository extends RetardRepository
{
    public ?array $student = null;
    public mixed $existingId = null;
    public array $created = [];
    public int $count = 0;
    public array $justifications = [];
    public array $deleted = [];

    public function __construct()
    {
    }

    public function findActiveStudent(int $studentId): ?array
    {
        return $this->student;
    }

    public function findRetardId(int $studentId, string $date): mixed
    {
        return $this->existingId;
    }

    public function createRetard(
        int $studentId,
        string $date,
        string $arrivalTime,
        string $expectedTime,
        int $duration,
        ?string $reason,
        int $justified,
        int $userId
    ): int {
        $this->created[] = func_get_args();
        return 15;
    }

    public function countForStudent(int $studentId): int
    {
        return $this->count;
    }

    public function exists(int $retardId): bool
    {
        return $retardId === 15;
    }

    public function justify(int $retardId, string $reason): void
    {
        $this->justifications[] = [$retardId, $reason];
    }

    public function delete(int $retardId): void
    {
        $this->deleted[] = $retardId;
    }
}

$repository = new InMemoryRetardRepository();
$service = new RetardService($repository);

if ($service->calculateDuration('07:30', '08:05') !== 35) {
    throw new RuntimeException('Delay duration was not calculated in minutes.');
}
if ($service->calculateDuration('07:30', '07:30') !== 0) {
    throw new RuntimeException('A non-positive delay should be zero.');
}
if ($service->calculateDuration('invalid', '08:05') !== 0) {
    throw new RuntimeException('Invalid times should not produce a delay.');
}

$repository->student = ['id' => 4, 'nom' => 'Test', 'prenom' => 'Eleve'];
$repository->existingId = 12;
if (!$service->findActiveStudent(4) || !$service->existsForDate(4, '2026-11-25')) {
    throw new RuntimeException('Retard lookup delegation failed.');
}

$repository->existingId = null;
$id = $service->createRetard(4, '2026-11-25', '08:05', '07:30', 35, '', 0, 9);
if ($id !== 15 || $repository->created[0][5] !== null) {
    throw new RuntimeException('Retard creation did not preserve nullable motif behavior.');
}

$repository->count = 3;
if ($service->countForStudent(4) !== 3) {
    throw new RuntimeException('Student retard count was not returned.');
}
if (!$service->justify(15, 'Transport') || $service->justify(16, 'Autre')) {
    throw new RuntimeException('Retard justification lookup behavior changed.');
}
$service->delete(15);
if ($repository->deleted !== [15]) {
    throw new RuntimeException('Retard deletion was not delegated.');
}

fwrite(STDOUT, "Retard service smoke tests passed.\n");
