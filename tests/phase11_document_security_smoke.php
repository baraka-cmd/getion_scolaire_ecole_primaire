<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$appConfig = file_get_contents($root . '/src/config/app.php');
$documentController = file_get_contents($root . '/src/controllers/DocumentController.php');
$documentRepository = file_get_contents($root . '/src/repositories/DocumentRepository.php');

if (!is_string($appConfig) || !preg_match("/define\('DOCUMENTS_STORAGE_PATH',\s*STORAGE_PATH\s*\.\s*'documents'\s*\.\s*DIRECTORY_SEPARATOR\s*\)/", $appConfig)) {
    throw new RuntimeException('Document storage must remain outside the public web root.');
}
if (!is_string($documentController)) {
    throw new RuntimeException('Document controller source unavailable.');
}
foreach ([
    'is_uploaded_file($tmpName)',
    'filesize($tmpName)',
    'random_bytes(16)',
    'requirePermission(\'documents.creer\')',
    'requirePermission(\'documents.supprimer\')',
    'requirePermission(\'documents.modifier\')',
    'DOCUMENTS_STORAGE_PATH',
    'Cache-Control: private, no-store',
    'X-Content-Type-Options: nosniff',
] as $guard) {
    if (!str_contains($documentController, $guard)) {
        throw new RuntimeException('Document security invariant missing: ' . $guard);
    }
}
if (preg_match('/\$this->db\s*=\s*Database::getInstance\(\)/', $documentController)) {
    throw new RuntimeException('DocumentController should delegate persistence to DocumentService.');
}
if (!preg_match("~\^documents/\(\[A-Za-z0-9._-\]\+\)\$~D", $documentController)) {
    throw new RuntimeException('Document path resolver must reject traversal and arbitrary paths.');
}
if (!is_string($documentRepository) || !str_contains($documentRepository, 'd.est_public = 1')) {
    throw new RuntimeException('Document listing must filter private files for non-admin users.');
}
echo "Phase 11 private document security smoke: OK\n";
