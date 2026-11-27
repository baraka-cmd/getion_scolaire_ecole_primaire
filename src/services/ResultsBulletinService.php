<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/ResultsBulletinRepository.php';

/**
 * Cas d'usage de lecture pour les résultats et bulletins.
 * Aucun calcul scolaire n'est déplacé ou modifié dans ce lot.
 */
class ResultsBulletinService
{
    private ResultsBulletinRepository $repository;

    public function __construct(?ResultsBulletinRepository $repository = null)
    {
        $this->repository = $repository ?? new ResultsBulletinRepository();
    }

    public function getActiveSchoolYearId(): int
    {
        return $this->repository->findActiveSchoolYearId();
    }

    public function getStudentForResults(int $studentId, int $yearId): ?array
    {
        if ($studentId <= 0 || $yearId <= 0) {
            return null;
        }

        return $this->repository->findStudentForResults($studentId, $yearId);
    }

    public function getStudentForBulletin(int $studentId, int $yearId): ?array
    {
        if ($studentId <= 0 || $yearId <= 0) {
            return null;
        }

        return $this->repository->findStudentForBulletin($studentId, $yearId);
    }

    public function getSchoolYear(int $yearId): ?array
    {
        return $yearId > 0 ? $this->repository->findSchoolYear($yearId) : null;
    }

    public function getSchoolParameters(): ?array
    {
        return $this->repository->findSchoolParameters();
    }
}
