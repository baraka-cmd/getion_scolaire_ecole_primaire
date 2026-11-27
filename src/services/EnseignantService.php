<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/EnseignantRepository.php';

class EnseignantService
{
    private EnseignantRepository $repository;

    public function __construct(?EnseignantRepository $repository = null)
    {
        $this->repository = $repository ?? new EnseignantRepository();
    }

    public function getForAjax(int $id): ?array
    {
        $teacher = $this->repository->findForAjax($id);
        if ($teacher === null) {
            return null;
        }

        $classId = $teacher['classe_id'] ?? null;
        return [
            'enseignant' => $teacher,
            'eleves' => $classId ? $this->repository->findStudentsByClass((int) $classId) : [],
            'emploiDuTemps' => $classId ? $this->repository->findTimetableByClass((int) $classId) : [],
        ];
    }

    public function getDetail(int $id): ?array
    {
        return $this->repository->findDetail($id);
    }

    public function getStudents(int $teacherId): array
    {
        $classId = $this->repository->findClassId($teacherId);
        return $classId ? $this->repository->findStudentsByClass((int) $classId) : [];
    }

    public function getActiveClasses(): array
    {
        return $this->repository->findActiveClasses();
    }

    public function getAvailableClasses(int $excludeTeacherId): array
    {
        return $this->repository->findAvailableClasses($excludeTeacherId);
    }

    public function getSpecialties(): array
    {
        return array_column($this->repository->findSpecialties(), 'specialite');
    }

    public function changeClass(int $teacherId, int $classId): bool
    {
        if (
            $classId > 0
            && $this->repository->findOtherActiveTeacherIdForClass($classId, $teacherId)
        ) {
            return false;
        }

        $this->repository->updateClassAssignment($teacherId, $classId > 0 ? $classId : null);
        return true;
    }
}
