<?php
declare(strict_types=1);

/**
 * Architecture audit for Phase 2.
 * Run from repository root: php tests/audit_view_sql_inventory.php
 * It intentionally fails while any view executes or initializes persistence.
 */
$root = realpath(__DIR__ . '/../public/views');
if ($root === false) {
    fwrite(STDERR, "public/views directory not found.\n");
    exit(2);
}
$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        $violations[] = $file->getPathname() . ': unable to read';
        continue;
    }
    $patterns = [
        '/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn|insert|update|delete|query)\s*\(/',
        '/\$pdo\s*->\s*(?:query|prepare|exec)\s*\(/',
        '/\b(?:global\s+\$db|Database::getInstance\s*\()/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source)) {
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $violations[] = 'public/views/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            break;
        }
    }
}
$violations = array_values(array_unique($violations));
if ($violations !== []) {
    fwrite(STDERR, "MVC Phase 2 not yet clean: " . count($violations) . " view(s) contain direct persistence access:\n");
    foreach ($violations as $path) fwrite(STDERR, " - {$path}\n");
    exit(1);
}
echo "MVC Phase 2 view audit: no direct persistence access found.\n";
