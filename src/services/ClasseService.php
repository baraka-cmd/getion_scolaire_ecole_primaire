<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/ClasseRepository.php';

class ClasseService
{
    private ClasseRepository $repository;

    public function __construct(?ClasseRepository $repository = null)
    {
        $this->repository = $repository ?? new ClasseRepository();
    }

    public function getPaginatedList(
        string $search,
        int $schoolYearId,
        string $orderBy,
        string $direction,
        int $limit,
        int $offset
    ): array {
        return $this->repository->findPaginatedList(
            $search,
            $schoolYearId,
            $orderBy,
            $direction,
            $limit,
            $offset
        );
    }

    public function getForAjax(int $id): ?array
    {
        $class = $this->repository->findForAjax($id);
        if ($class === null) {
            return null;
        }

        return [
            'classe' => $class,
            'enseignants' => $this->repository->findAvailableTeachersForClass($id),
        ];
    }

    public function getStudentsForAjax(int $classId): array
    {
        return $this->repository->findStudentsForAjax($classId);
    }

    public function getSchoolYears(): array
    {
        return $this->repository->findSchoolYears();
    }

    public function getAvailableTeachers(?int $classId = null): array
    {
        return $this->repository->findAvailableTeachers($classId);
    }

    public function getDetail(int $id): ?array
    {
        return $this->repository->findDetail($id);
    }

    public function getStudents(int $classId): array
    {
        return $this->repository->findStudents($classId);
    }

    public function changeHolder(int $classId, int $teacherId): void
    {
        if ($teacherId > 0) {
            $this->repository->releaseClassHolder($classId);
            $this->repository->releaseTeacherFromClass($teacherId);
            $this->repository->assignTeacherToClass($teacherId, $classId);
            return;
        }

        $this->repository->releaseClassHolder($classId);
    }
}
