<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ACCESS', true);
require_once __DIR__ . '/../config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'NotificationPushQueueService.php';

$limit = isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : 20;
$queue = new NotificationPushQueueService(Database::getInstance());
$stats = $queue->processBatch($limit);

fwrite(
    STDOUT,
    sprintf(
        "Notifications push traitées: %d; livrées: %d; à retenter: %d; invalides: %d; échecs définitifs: %d\n",
        $stats['traites'],
        $stats['envoyes'],
        $stats['en_attente'],
        $stats['invalides'],
        $stats['echecs']
    )
);
