<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/MessageRepository.php';

class MessageService
{
    private MessageRepository $repository;

    public function __construct(?MessageRepository $repository = null)
    {
        $this->repository = $repository ?? new MessageRepository();
    }

    public function resolveRecipients(string $type, int $senderId, ?string $roleName, int $classId, int $recipientId): array
    {
        switch ($type) {
            case 'tous':
                return $this->repository->recipientsForAll($senderId);
            case 'role':
                if ($roleName === null || trim($roleName) === '') {
                    throw new InvalidArgumentException('Veuillez sélectionner un rôle.');
                }
                return $this->repository->recipientsForRole($roleName, $senderId);
            case 'classe':
                if ($classId <= 0) {
                    throw new InvalidArgumentException('Veuillez sélectionner une classe.');
                }
                return $this->repository->recipientsForClass($classId, $senderId);
            case 'utilisateur':
                if ($recipientId <= 0) {
                    throw new InvalidArgumentException('Veuillez sélectionner un destinataire.');
                }
                return [['id' => $recipientId]];
            default:
                throw new InvalidArgumentException('Type de destinataire invalide.');
        }
    }

    public function create(int $senderId, int $recipientId, string $subject, string $content, ?int $parentId): int
    {
        return $this->repository->create($senderId, $recipientId, $subject, $content, $parentId);
    }

    public function searchUsers(int $userId, string $search): array
    {
        return $this->repository->searchUsers($userId, trim($search));
    }

    public function unreadCount(int $userId): int
    {
        return $this->repository->unreadCount($userId);
    }
}
