<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/SchoolSettingsRepository.php';

class SchoolSettingsService
{
    private SchoolSettingsRepository $repository;

    public function __construct(?SchoolSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new SchoolSettingsRepository();
    }

    public function getSettings(): ?array
    {
        return $this->repository->findSettings();
    }

    public function getYearLabel(int $yearId): ?array
    {
        return $this->repository->findYearLabel($yearId);
    }

    public function getTermLabel(int $termId): ?array
    {
        return $this->repository->findTermLabel($termId);
    }

    public function hasValidYearAndTerm(int $yearId, int $termId): bool
    {
        return $this->repository->findValidYearAndTerm($yearId, $termId);
    }

    public function saveSettings(array $settings): void
    {
        $this->repository->saveSettings($settings);
    }

    public function getLogo(): ?array
    {
        return $this->repository->findLogo();
    }

    public function updateLogo(string $relativePath): void
    {
        $this->repository->updateLogo($relativePath);
    }

    public function getSchoolYears(): array
    {
        return $this->repository->findSchoolYears();
    }

    public function getTerms(int $yearId): array
    {
        return $this->repository->findTerms($yearId);
    }

    public function getActiveYearId(): int
    {
        return $this->repository->findActiveYearId();
    }
}
