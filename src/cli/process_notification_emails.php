<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ACCESS', true);
require_once __DIR__ . '/../config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'NotificationEmailQueueService.php';

$limit = isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : 20;
$queue = new NotificationEmailQueueService(Database::getInstance());
$stats = $queue->processBatch($limit);

fwrite(
    STDOUT,
    sprintf(
        "Notifications email traitées: %d; envoyées: %d; à retenter: %d; échecs définitifs: %d\n",
        $stats['traites'],
        $stats['envoyes'],
        $stats['en_attente'],
        $stats['echecs']
    )
);
