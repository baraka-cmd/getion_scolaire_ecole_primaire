<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AssistantDisplayContract.php';
require_once __DIR__ . '/../src/services/AssistantResponseWriter.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$proclamation = [
    'type' => 'proclamation',
    'summary' => [
        'total' => 6,
        'reussis' => 4,
        'echoues' => 2,
        'items' => [
            [
                'prenom' => 'Grâce',
                'nom' => 'KABONGO',
                'moyenne_generale' => 17.5,
                'decision' => 'Passe',
            ],
        ],
    ],
];

$display = AssistantDisplayContract::fromResult($proclamation);
$assert($display['title'] === 'Proclamation officielle', 'titre de proclamation incorrect');
$assert(in_array('copy', $display['actions'], true), 'copie absente de la proclamation');
$assert(str_contains($display['export_text'], 'Grâce KABONGO'), 'élève absent de l’export proclamation');

$message = AssistantResponseWriter::write($proclamation);
$assert(str_contains($message, '4 admis'), 'rédaction des admis absente');
$assert(str_contains($message, '2 en situation d’échec'), 'rédaction des échecs absente');

$report = ['passed' => 5 - count($failures), 'failed' => count($failures), 'failures' => $failures];
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

if ($failures !== []) {
    exit(1);
}
