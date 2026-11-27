<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/StatisticsRepository.php';

class StatisticsService
{
    private StatisticsRepository $repository;

    public function __construct(?StatisticsRepository $repository = null)
    {
        $this->repository = $repository ?? new StatisticsRepository();
    }

    public function getActiveYearId(): int
    {
        return $this->repository->getActiveYearId();
    }

    public function getFilterOptions(): array
    {
        $years = $this->repository->getYears();
        $yearId = $this->getActiveYearId();
        if ($years && !$this->findYear($years, $yearId)) {
            $yearId = (int) $years[0]['id'];
        }
        return [
            'annees' => $years,
            'annee_id' => $yearId,
            'classes' => $yearId > 0 ? $this->repository->getClasses($yearId) : [],
            'trimestres' => $yearId > 0 ? $this->repository->getTerms($yearId) : [],
            'trimestre_id' => $this->repository->getActiveTermId($yearId),
        ];
    }

    public function getClasses(int $yearId): array
    {
        return $this->repository->getClasses($yearId);
    }

    public function getTerms(int $yearId): array
    {
        return $this->repository->getTerms($yearId);
    }

    public function getActiveTermId(int $yearId): ?int
    {
        return $this->repository->getActiveTermId($yearId);
    }

    public function getYear(int $yearId): ?array
    {
        return $this->repository->getYear($yearId);
    }

    public function validateClass(int $classId, int $yearId): bool
    {
        return $this->repository->getClass($classId, $yearId) !== null;
    }

    public function validateTerm(int $termId, int $yearId): bool
    {
        return $this->repository->getTerm($termId, $yearId) !== null;
    }

    public function getTerm(int $termId, int $yearId): ?array
    {
        return $this->repository->getTerm($termId, $yearId);
    }

    private function findYear(array $years, int $yearId): bool
    {
        foreach ($years as $year) {
            if ((int) $year['id'] === $yearId) {
                return true;
            }
        }
        return false;
    }
}
