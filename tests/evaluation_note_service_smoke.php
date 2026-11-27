<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/EvaluationNoteService.php';

final class InMemoryEvaluationNoteRepository extends EvaluationNoteRepository
{
    public mixed $existingId = null;
    public array $calls = [];
    public array $entryContext = ['id' => 5, 'classe_id' => 2, 'groupe_id' => 8, 'type_evaluation' => 'Examen', 'annee_scolaire_id' => 3];
    public array $studentIds = [10, 11];
    public array $maxima = [4 => 40.0];
    public array $studentNotes = [['valeur' => 17.0]];
    public function findEvaluationList(array $filters): array
    {
        $this->calls[] = ['evaluation-list', $filters];
        return [['id' => 5]];
    }

    public function findEvaluationDetails(int $evaluationId): ?array
    {
        $this->calls[] = ['evaluation-details', $evaluationId];
        return ['id' => $evaluationId, 'classe_id' => 2];
    }

    public function findEvaluationEntryContext(int $evaluationId): ?array
    {
        $this->calls[] = ['context', $evaluationId];
        return $this->entryContext;
    }

    public function findActiveStudentIdsForEvaluation(int $classId, int $yearId): array
    {
        $this->calls[] = ['students', $classId, $yearId];
        return $this->studentIds;
    }

    public function findActiveSubjectMaximaForEvaluation(int $classId, int $groupId, string $evaluationType): array
    {
        $this->calls[] = ['maxima', $classId, $groupId, $evaluationType];
        return $this->maxima;
    }

    public function findStudentNotes(int $studentId, int $trimesterId = 0): array
    {
        $this->calls[] = ['student-notes', $studentId, $trimesterId];
        return $this->studentNotes;
    }

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

if ($service->getEvaluationList(['classe_id' => 2]) !== [['id' => 5]]
    || $service->getEvaluationDetails(5) !== ['id' => 5, 'classe_id' => 2]
    || $service->getEntryContext(5) !== $repository->entryContext
    || $service->getActiveStudentIds(2, 3) !== [10, 11]
    || $service->getSubjectMaxima(2, 8, 'Examen') !== [4 => 40.0]
    || $service->getStudentNotes(10, 2) !== [['valeur' => 17.0]]) {
    throw new RuntimeException('Evaluation reads were not delegated through the service.');
}

$repository->calls = [];
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
