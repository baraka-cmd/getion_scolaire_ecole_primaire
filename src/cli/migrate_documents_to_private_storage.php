<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ACCESS', true);
require_once __DIR__ . '/../config/app.php';

$applyChanges = in_array('--apply', $argv, true);
$publicDirectory = ASSETS_PATH . 'uploads' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR;
$privateDirectory = DOCUMENTS_STORAGE_PATH;
if (is_link($publicDirectory) || is_link($privateDirectory)) {
    fwrite(STDERR, "A document storage directory is a symbolic link; inspect it before migration.\n");
    exit(1);
}
$legacyDocuments = $db->fetchAll(
    "SELECT id, chemin_fichier
     FROM documents
     WHERE chemin_fichier LIKE 'uploads/documents/%'"
);

$filesToMove = [];
foreach ($legacyDocuments as $document) {
    $relativePath = str_replace('\\', '/', (string) $document['chemin_fichier']);
    if (!preg_match('~^uploads/documents/([A-Za-z0-9._-]+)$~D', $relativePath, $matches)) {
        fwrite(STDERR, "A document row contains an unexpected legacy path; no files were changed.\n");
        exit(1);
    }
    if ($matches[1] === '.' || $matches[1] === '..') {
        fwrite(STDERR, "A document row contains an invalid filename; no files were changed.\n");
        exit(1);
    }
    $filesToMove[$matches[1]] = true;
}

if (is_dir($publicDirectory)) {
    foreach (new DirectoryIterator($publicDirectory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if (!$entry->isFile() || $entry->isLink()) {
            fwrite(STDERR, "The public documents directory contains a non-regular entry; inspect it before migration.\n");
            exit(1);
        }
        $filesToMove[$entry->getFilename()] = true;
    }
}

foreach (array_keys($filesToMove) as $filename) {
    $source = $publicDirectory . $filename;
    $destination = $privateDirectory . $filename;
    if (is_link($source) || is_link($destination)) {
        fwrite(STDERR, "A symbolic link was found in document storage; inspect it before migration.\n");
        exit(1);
    }
    if (!is_file($source) && !is_file($destination)) {
        fwrite(STDERR, "A referenced document file is missing from both storage locations; no database changes were made.\n");
        exit(1);
    }
    if (is_file($source) && is_file($destination) && hash_file('sha256', $source) !== hash_file('sha256', $destination)) {
        fwrite(STDERR, "A private-storage filename collision was detected; no files were changed.\n");
        exit(1);
    }
}

if (!$applyChanges) {
    echo count($legacyDocuments) . " database record(s) and " . count($filesToMove) . " file(s) are ready to move.\n";
    echo "Run with --apply after backing up the files and database.\n";
    exit(0);
}

if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700, true) && !is_dir($privateDirectory)) {
    fwrite(STDERR, "Could not create private document storage; no files were changed.\n");
    exit(1);
}

$copiedFiles = [];
foreach (array_keys($filesToMove) as $filename) {
    $source = $publicDirectory . $filename;
    $destination = $privateDirectory . $filename;
    if (is_file($source) && !is_file($destination)) {
        if (!copy($source, $destination)) {
            fwrite(STDERR, "A document could not be copied; public originals were retained.\n");
            exit(1);
        }
        if (!chmod($destination, 0600)) {
            fwrite(STDERR, "Private document permissions could not be set; public originals were retained.\n");
            exit(1);
        }
        $copiedFiles[] = $destination;
    }
}

try {
    $db->beginTransaction();
    foreach ($legacyDocuments as $document) {
        $filename = basename(str_replace('\\', '/', (string) $document['chemin_fichier']));
        $db->update(
            "UPDATE documents
             SET chemin_fichier = :new_path
             WHERE id = :id AND chemin_fichier = :old_path",
            [
                'new_path' => 'documents/' . $filename,
                'id' => $document['id'],
                'old_path' => $document['chemin_fichier'],
            ]
        );
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('Échec de migration des chemins des documents privés.');
    fwrite(STDERR, "Database migration failed; public originals were retained.\n");
    exit(1);
}

foreach (array_keys($filesToMove) as $filename) {
    $source = $publicDirectory . $filename;
    if (is_file($source) && !unlink($source)) {
        fwrite(STDERR, "Database paths now use private storage, but a public copy could not be removed.\n");
        exit(1);
    }
}

if (is_dir($publicDirectory) && !rmdir($publicDirectory)) {
    fwrite(STDERR, "Documents were moved, but the now-empty public directory could not be removed.\n");
    exit(1);
}

echo count($legacyDocuments) . " database record(s) and " . count($filesToMove) . " file(s) moved to private storage.\n";
