<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/NotificationRepository.php';

class NotificationService
{
    private NotificationRepository $repository;

    public function __construct(?NotificationRepository $repository = null)
    {
        $this->repository = $repository ?? new NotificationRepository();
    }

    public function findCampaign(int $creatorId, string $requestKey): ?array
    {
        return $this->repository->findCampaign($creatorId, $requestKey);
    }

    public function getActiveYearId(): int
    {
        return $this->repository->getActiveYearId();
    }

    public function activeClassExists(int $classId, int $yearId): bool
    {
        return $this->repository->activeClassExists($classId, $yearId);
    }

    public function findRecipients(int $yearId, string $targetType, int $classId, int $parentId): array
    {
        return $this->repository->findRecipients($yearId, $targetType, $classId, $parentId);
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        if ($this->repository->markRead($notificationId, $userId) > 0) {
            return true;
        }
        if (!$this->repository->belongsToUser($notificationId, $userId)) {
            throw new DomainException('Notification introuvable.');
        }
        return true;
    }

    public function delete(int $notificationId, int $userId): bool
    {
        if ($this->repository->delete($notificationId, $userId) === 0) {
            throw new DomainException('Notification introuvable ou accès refusé.');
        }
        return true;
    }
}
