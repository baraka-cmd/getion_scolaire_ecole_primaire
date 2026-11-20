<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AssistantResponseWriter.php';

$checks = [
    AssistantResponseWriter::write([
        'type' => 'payment_summary',
        'summary' => ['attendu' => 100, 'paye' => 60, 'reste' => 40, 'eleves' => 2],
    ]),
    AssistantResponseWriter::write([
        'type' => 'absence_summary',
        'summary' => ['total' => 3, 'justifiees' => 1, 'non_justifiees' => 2],
    ]),
    AssistantResponseWriter::write([
        'type' => 'result_ranking',
        'summary' => ['items' => [['prenom' => 'A', 'nom' => 'B', 'moyenne_generale' => 17.5]]],
    ]),
];

if (count(array_filter($checks, static fn (string $value): bool => trim($value) !== '')) !== 3) {
    fwrite(STDERR, "response writer tests failed\n");
    exit(1);
}

if (str_contains($checks[0], '999') || !str_contains($checks[2], '17.5')) {
    fwrite(STDERR, "response writer truthfulness check failed\n");
    exit(1);
}

fwrite(STDOUT, "assistant phase 7 tests passed\n");
