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

    public function getEntryContext(int $evaluationId): ?array
    {
        return $evaluationId > 0 ? $this->repository->findEvaluationEntryContext($evaluationId) : null;
    }

    public function getActiveStudentIds(int $classId, int $yearId): array
    {
        if ($classId <= 0 || $yearId <= 0) {
            return [];
        }
        return $this->repository->findActiveStudentIdsForEvaluation($classId, $yearId);
    }

    public function getSubjectMaxima(int $classId, int $groupId, string $evaluationType): array
    {
        if ($classId <= 0 || $groupId <= 0) {
            return [];
        }
        return $this->repository->findActiveSubjectMaximaForEvaluation($classId, $groupId, $evaluationType);
    }

    public function getStudentNotes(int $studentId, int $trimesterId = 0): array
    {
        if ($studentId <= 0 || $trimesterId < 0) {
            return [];
        }
        return $this->repository->findStudentNotes($studentId, $trimesterId);
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
