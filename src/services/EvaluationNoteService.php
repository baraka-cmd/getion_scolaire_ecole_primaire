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


    public function classAndTermAreConsistent(int $classId, int $termId): bool
    {
        return $classId > 0 && $termId > 0
            && $this->repository->isClassAndTermConsistent($classId, $termId);
    }

    public function evaluationAlreadyExists(int $termId, int $classId, int $groupId, string $type): bool
    {
        return $termId > 0 && $classId > 0 && $groupId > 0 && $type !== ''
            && $this->repository->evaluationExists($termId, $classId, $groupId, $type);
    }

    public function createEvaluation(int $termId, int $groupId, int $classId, string $type, string $date): int
    {
        if ($termId <= 0 || $groupId <= 0 || $classId <= 0 || $type === '' || $date === '') {
            throw new InvalidArgumentException('Evaluation data is incomplete.');
        }
        return $this->repository->insertEvaluation($termId, $groupId, $classId, $type, $date);
    }

    public function evaluationExists(int $evaluationId): bool
    {
        return $evaluationId > 0 && $this->repository->evaluationIdExists($evaluationId);
    }

    public function countEvaluationNotes(int $evaluationId): int
    {
        return $evaluationId > 0 ? $this->repository->countNotesForEvaluation($evaluationId) : 0;
    }

    public function updateEvaluationDate(int $evaluationId, string $date): void
    {
        if ($evaluationId <= 0 || $date === '') {
            throw new InvalidArgumentException('Evaluation identifier and date are required.');
        }
        $this->repository->updateEvaluationDate($evaluationId, $date);
    }

    public function deleteEvaluation(int $evaluationId): void
    {
        if ($evaluationId <= 0) {
            throw new InvalidArgumentException('Evaluation identifier is required.');
        }
        $this->repository->deleteEvaluation($evaluationId);
    }

    public function getEvaluationList(array $filters): array
    {
        return $this->repository->findEvaluationList($filters);
    }

    public function getEvaluationDetails(int $evaluationId): ?array
    {
        return $evaluationId > 0 ? $this->repository->findEvaluationDetails($evaluationId) : null;
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

    public function getEvaluationGridContext(int $evaluationId): ?array
    {
        return $evaluationId > 0 ? $this->repository->findEvaluationGridContext($evaluationId) : null;
    }

    public function getActiveSubjectsForClassGroup(int $classId, int $groupId): array
    {
        return $classId > 0 && $groupId > 0 ? $this->repository->findActiveSubjectsForClassGroup($classId, $groupId) : [];
    }

    public function getActiveStudentsForClassYear(int $classId, int $yearId): array
    {
        return $classId > 0 && $yearId > 0 ? $this->repository->findActiveStudentsForClassYear($classId, $yearId) : [];
    }

    public function getNotesForEvaluationGrid(int $evaluationId): array
    {
        return $evaluationId > 0 ? $this->repository->findNotesForEvaluationGrid($evaluationId) : [];
    }

    public function getTermForGeneration(int $classId, int $termId): ?array
    {
        return $classId > 0 && $termId > 0 ? $this->repository->findTermForGeneration($classId, $termId) : null;
    }

    public function getActiveGroupsForEvaluationGeneration(): array
    {
        return $this->repository->findActiveGroupsForEvaluationGeneration();
    }
}
