<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/MatiereRepository.php';

class MatiereService
{
    private MatiereRepository $repository;

    public function __construct(?MatiereRepository $repository = null)
    {
        $this->repository = $repository ?? new MatiereRepository();
    }

    public function getGroups(): array
    {
        return $this->repository->findGroups();
    }

    public function getSubSubjectsByGroup(int $groupId): array
    {
        return $this->repository->findSubSubjectsByGroup($groupId);
    }

    public function getDetail(int $id): ?array
    {
        return $this->repository->findDetail($id);
    }

    public function getForAjax(int $id): ?array
    {
        $subject = $this->repository->findDetail($id);
        if ($subject === null) {
            return null;
        }

        return [
            'matiere' => $subject,
            'sous_matieres' => $subject['est_groupe']
                ? $this->repository->findActiveChildren($id)
                : [],
            'groupes' => $this->repository->findGroupsForSelect(),
        ];
    }

    public function getSubSubjectsForAjax(int $groupId): array
    {
        return $groupId > 0
            ? $this->repository->findSubSubjectsByGroup($groupId)
            : $this->repository->findSubSubjectsWithGroups();
    }

    public function getActiveClasses(): array
    {
        return $this->repository->findActiveClasses();
    }

    public function getTotalMaximaForClass(int $classId): int
    {
        return $this->repository->findTotalMaximaForClass($classId);
    }

    public function calculateSubSubjectScore(int $studentId, int $subjectId, int $termId): ?array
    {
        $data = $this->repository->findScoringData($studentId, $subjectId, $termId);
        if ($data === null) {
            return null;
        }

        $scores = [$data['periode_1'], $data['periode_2'], $data['examen']];
        if ($scores[0] === null && $scores[1] === null && $scores[2] === null) {
            return null;
        }

        $totalObtained = (float) ($scores[0] ?? 0)
            + (float) ($scores[1] ?? 0)
            + (float) ($scores[2] ?? 0);
        $totalMaxima = (int) $data['maxima'] * 3;

        return [
            'total_obtenu' => round($totalObtained, 2),
            'total_maxima' => $totalMaxima,
            'pourcentage' => $totalMaxima > 0
                ? round(($totalObtained / $totalMaxima) * 100, 1)
                : 0,
        ];
    }

    public function getClassesForSubject(int $subjectId): array
    {
        return [
            'classes' => $this->repository->findClassesForSubject($subjectId),
            'maxima_global' => $this->repository->findGlobalMaxima($subjectId),
        ];
    }

    public function associateClass(int $subjectId, int $classId, ?int $maxima): void
    {
        if ($this->repository->hasClassAssociation($subjectId, $classId)) {
            $this->repository->updateClassAssociation($subjectId, $classId, $maxima);
            return;
        }

        $this->repository->createClassAssociation($subjectId, $classId, $maxima);
    }

    public function dissociateClass(int $subjectId, int $classId): bool
    {
        if ($this->repository->countEvaluationsForClass($subjectId, $classId) > 0) {
            $this->repository->deactivateClassAssociation($subjectId, $classId);
            return true;
        }

        $this->repository->deleteClassAssociation($subjectId, $classId);
        return false;
    }

    public function updateClassMaxima(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->repository->updateClassMaxima($subjectId, $classId, $maxima);
    }
}
