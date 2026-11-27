<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/EvaluationNoteRepository.php';

class EvaluationNoteService
{
    private EvaluationNoteRepository $repository;

    public function __construct(?EvaluationNoteRepository $repository = null)
    {
        $this->repository = $repository ?? new EvaluationNoteRepository();
    }

    public function saveCell(
        int $evaluationId,
        int $studentId,
        int $subjectId,
        ?float $value,
        int $absence,
        int $userId
    ): bool {
        $existingNoteId = $this->repository->findNoteId($evaluationId, $studentId, $subjectId);
        if ($existingNoteId) {
            if ($value !== null || $absence) {
                $this->repository->updateNote((int) $existingNoteId, $value, $absence);
                return true;
            }

            $this->repository->deleteNote((int) $existingNoteId);
            return false;
        }

        if ($value === null && !$absence) {
            return false;
        }

        $this->repository->createNote(
            $studentId,
            $evaluationId,
            $subjectId,
            $value,
            $absence,
            $userId
        );
        return true;
    }
}
