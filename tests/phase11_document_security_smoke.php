<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$appConfig = file_get_contents($root . '/src/config/app.php');
$documentController = file_get_contents($root . '/src/controllers/DocumentController.php');
$documentRepository = file_get_contents($root . '/src/repositories/DocumentRepository.php');

if (!is_string($appConfig) || !str_contains($appConfig, "define('DOCUMENTS_STORAGE_PATH', STORAGE_PATH . 'documents' . DIRECTORY_SEPARATOR)")) {
    throw new RuntimeException('Private document storage must be outside the public root.');
}
if (!is_string($documentController)) {
    throw new RuntimeException('Document controller source unavailable.');
}
foreach (['is_uploaded_file($tmpName)', 'filesize($tmpName)', 'random_bytes(16)', "requirePermission('documents.creer')", "requirePermission('documents.supprimer')", "requirePermission('documents.modifier')", 'DOCUMENTS_STORAGE_PATH', 'Cache-Control: private, no-store', 'X-Content-Type-Options: nosniff'] as $guard) {
    if (!str_contains($documentController, $guard)) {
        throw new RuntimeException('Document security invariant missing: ' . $guard);
    }
}
if (str_contains($documentController, '$this->db = Database::getInstance()')) {
    throw new RuntimeException('DocumentController must delegate persistence.');
}
if (!str_contains($documentController, 'function resolveDocumentPath') || !str_contains($documentController, 'DOCUMENTS_STORAGE_PATH . $matches[1]')) {
    throw new RuntimeException('Document path resolver must constrain files to private storage.');
}
if (!is_string($documentRepository) || !str_contains($documentRepository, 'd.est_public = 1')) {
    throw new RuntimeException('Non-admin document listing must exclude private files.');
}
echo "Phase 11 private document security smoke: OK\n";
