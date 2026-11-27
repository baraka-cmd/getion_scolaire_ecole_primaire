<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/DocumentService.php';
require_once __DIR__ . '/../src/services/MessageService.php';
require_once __DIR__ . '/../src/services/NotificationService.php';

final class InMemoryDocumentRepository extends DocumentRepository
{
    public ?array $document = null;
    public array $calls = [];
    public function __construct() {}
    public function findMetadata(int $documentId): ?array
    {
        return $this->document;
    }
    public function setVisibility(int $documentId, int $visibility): void
    {
        $this->calls[] = ['visibility', $documentId, $visibility];
    }
}

$documentRepository = new InMemoryDocumentRepository();
$documentRepository->document = ['id' => 5, 'est_public' => 1, 'nom_fichier' => 'test.pdf'];
$documentService = new DocumentService($documentRepository);
$toggle = $documentService->toggleVisibility(5);
if ($toggle['visibility'] !== 0 || $documentRepository->calls !== [['visibility', 5, 0]]) {
    throw new RuntimeException('Document visibility toggle did not preserve the service contract.');
}

final class InMemoryMessageRepository extends MessageRepository
{
    public array $calls = [];
    public function __construct() {}
    public function recipientsForAll(int $userId): array
    {
        return [['id' => 2], ['id' => 3]];
    }
    public function create(int $senderId, int $recipientId, string $subject, string $content, ?int $parentId): int
    {
        $this->calls[] = [$senderId, $recipientId, $subject, $content, $parentId];
        return count($this->calls);
    }
    public function unreadCount(int $userId): int
    {
        return 4;
    }
}

$messageRepository = new InMemoryMessageRepository();
$messageService = new MessageService($messageRepository);
$recipients = $messageService->resolveRecipients('tous', 1, null, 0, 0);
if ($recipients !== [['id' => 2], ['id' => 3]]) {
    throw new RuntimeException('All-user recipient resolution changed.');
}
if ($messageService->create(1, 2, 'Sujet', 'Contenu', null) !== 1) {
    throw new RuntimeException('Message creation was not delegated.');
}
if ($messageService->unreadCount(1) !== 4) {
    throw new RuntimeException('Unread message count changed.');
}

final class InMemoryNotificationRepository extends NotificationRepository
{
    public array $calls = [];
    public function __construct() {}
    public function markRead(int $notificationId, int $userId): int
    {
        $this->calls[] = ['read', $notificationId, $userId];
        return 1;
    }
    public function delete(int $notificationId, int $userId): int
    {
        $this->calls[] = ['delete', $notificationId, $userId];
        return 1;
    }
}

$notificationRepository = new InMemoryNotificationRepository();
$notificationService = new NotificationService($notificationRepository);
if (!$notificationService->markRead(8, 1) || !$notificationService->delete(8, 1)) {
    throw new RuntimeException('Notification ownership operations were not delegated.');
}
if ($notificationRepository->calls !== [['read', 8, 1], ['delete', 8, 1]]) {
    throw new RuntimeException('Notification repository calls changed.');
}

fwrite(STDOUT, "Document, message and notification service smoke tests passed.\n");
