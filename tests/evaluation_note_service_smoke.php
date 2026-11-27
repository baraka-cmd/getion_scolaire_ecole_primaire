<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/EvaluationNoteService.php';

final class InMemoryEvaluationNoteRepository extends EvaluationNoteRepository
{
    public mixed $existingId = null;
    public array $calls = [];

    public function __construct()
    {
    }

    public function findNoteId(int $evaluationId, int $studentId, int $subjectId): mixed
    {
        return $this->existingId;
    }

    public function updateNote(int $noteId, ?float $value, int $absence): void
    {
        $this->calls[] = ['update', $noteId, $value, $absence];
    }

    public function deleteNote(int $noteId): void
    {
        $this->calls[] = ['delete', $noteId];
    }

    public function createNote(
        int $studentId,
        int $evaluationId,
        int $subjectId,
        ?float $value,
        int $absence,
        int $userId
    ): void {
        $this->calls[] = ['create', $studentId, $evaluationId, $subjectId, $value, $absence, $userId];
    }
}

$repository = new InMemoryEvaluationNoteRepository();
$service = new EvaluationNoteService($repository);

$repository->existingId = 7;
if (!$service->saveCell(2, 3, 4, 12.5, 0, 9) || $repository->calls[0] !== ['update', 7, 12.5, 0]) {
    throw new RuntimeException('An existing note was not updated.');
}

if ($service->saveCell(2, 3, 4, null, 0, 9) || $repository->calls[1] !== ['delete', 7]) {
    throw new RuntimeException('An empty existing note was not deleted without increasing the count.');
}

$repository->existingId = null;
if ($service->saveCell(2, 3, 4, null, 0, 9) || count($repository->calls) !== 2) {
    throw new RuntimeException('An empty note cell should not create a record.');
}

if (
    !$service->saveCell(2, 3, 4, null, 1, 9)
    || $repository->calls[2] !== ['create', 3, 2, 4, null, 1, 9]
) {
    throw new RuntimeException('An absence-only note cell was not inserted.');
}

fwrite(STDOUT, "Evaluation note service smoke tests passed.\n");
