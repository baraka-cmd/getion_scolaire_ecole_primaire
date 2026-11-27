<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/ParentStudentRepository.php';

class ParentStudentService
{
    private ParentStudentRepository $repository;

    public function __construct(?ParentStudentRepository $repository = null)
    {
        $this->repository = $repository ?? new ParentStudentRepository();
    }

    public function createOrFindParent(
        string $nom,
        string $prenom,
        string $lien,
        string $telephone,
        ?string $email = null,
        ?string $profession = null
    ): int {
        $parentId = $this->repository->findParentIdByTelephone($telephone);
        if ($parentId !== null) {
            return $parentId;
        }

        return $this->repository->createParent(
            formatNom($nom),
            formatNom($prenom),
            $lien ?: 'Tuteur',
            $telephone,
            $email ?: null,
            $profession ?: null
        );
    }

    public function linkIfMissing(int $studentId, int $parentId, bool $isPrimary): void
    {
        if (!$this->repository->hasLink($studentId, $parentId)) {
            $this->repository->createLink($studentId, $parentId, (int) $isPrimary);
        }
    }

    public function linkParentToStudent(int $parentId, int $studentId, int $isPrimary): bool
    {
        if ($this->repository->hasLink($studentId, $parentId)) {
            return false;
        }

        if ($isPrimary) {
            $this->repository->unsetPrimaryLinks($parentId);
        }

        $this->repository->createLink($studentId, $parentId, $isPrimary);
        return true;
    }

    public function unlinkParentFromStudent(int $parentId, int $studentId): bool
    {
        if ($this->repository->countLinksForStudent($studentId) <= 1) {
            return false;
        }

        $this->repository->deleteLink($studentId, $parentId);
        return true;
    }

    public function getStudentParentsForDetail(int $studentId): array
    {
        return $this->repository->getStudentParentsForDetail($studentId);
    }

    public function getStudentParents(int $studentId): array
    {
        return $this->repository->getStudentParents($studentId);
    }

    public function getStudentParentsForAjax(int $studentId): array
    {
        return $this->repository->getStudentParentsForAjax($studentId);
    }

    public function getParentChildrenForDetail(int $parentId): array
    {
        return $this->repository->getParentChildrenForDetail($parentId);
    }

    public function getParentChildren(int $parentId): array
    {
        return $this->repository->getParentChildren($parentId);
    }

    public function getActiveChildrenForParent(int $parentId): array
    {
        return $this->repository->getParentChildrenForDetail($parentId);
    }

    public function getAvailableStudents(?int $parentId = null): array
    {
        return $this->repository->getAvailableStudents($parentId);
    }
}
