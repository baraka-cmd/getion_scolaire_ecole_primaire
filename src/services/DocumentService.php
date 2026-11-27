<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/DocumentRepository.php';

class DocumentService
{
    private DocumentRepository $repository;

    public function __construct(?DocumentRepository $repository = null)
    {
        $this->repository = $repository ?? new DocumentRepository();
    }

    public function paginateVisible(array $filters, int $page, int $perPage, bool $admin): array
    {
        return $this->repository->paginateVisible($filters, max(1, $page), max(1, $perPage), $admin);
    }

    public function findMetadata(int $documentId): ?array
    {
        return $this->repository->findMetadata($documentId);
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function delete(int $documentId): void
    {
        $this->repository->delete($documentId);
    }

    public function toggleVisibility(int $documentId): array
    {
        $document = $this->repository->findMetadata($documentId);
        if (!$document) {
            throw new DomainException('Document introuvable.');
        }
        $visibility = (int) $document['est_public'] === 1 ? 0 : 1;
        $this->repository->setVisibility($documentId, $visibility);
        return ['visibility' => $visibility, 'document' => $document];
    }

    public function findDetails(int $documentId): ?array
    {
        return $this->repository->findDetails($documentId);
    }

    public function findRecent(bool $admin, int $limit): array
    {
        return $this->repository->findRecent($admin, max(1, $limit));
    }
}
