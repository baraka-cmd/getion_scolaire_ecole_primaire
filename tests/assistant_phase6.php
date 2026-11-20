<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AssistantExecutionContract.php';
require_once __DIR__ . '/../src/services/AssistantCapabilityRegistry.php';
require_once __DIR__ . '/../src/services/AssistantDisplayContract.php';
require_once __DIR__ . '/../src/services/AssistantParameterNormalizer.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$capabilities = AssistantCapabilityRegistry::forIntent('resultats');
$catalog = [
    'resultats' => [
        'parameters' => ['classe' => 'classe', 'statut_resultat' => 'statut'],
        'capabilities' => $capabilities,
    ],
    'inconnu' => ['parameters' => [], 'capabilities' => []],
];
$classification = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'capability' => 'results.top_students',
    'parameters' => ['classe' => '1ère année', 'sql' => 'DROP TABLE'],
], $catalog);
$assert($classification['status'] === AssistantExecutionContract::READY, 'classification ready');
$assert($classification['capability'] === 'results.top_students', 'capability validated');
$assert($classification['parameters'] === ['classe' => '1ère année'], 'unknown parameters removed');

$display = AssistantDisplayContract::fromResult([
    'type' => 'payment_students',
    'items' => [['prenom' => 'Test', 'nom' => 'Élève', 'classe' => '1ère', 'reste' => 100]],
]);
$assert($display['type'] === 'table', 'display table type');
$assert(count($display['columns']) === 3, 'display columns');
$assert(str_contains($display['export_text'], '100'), 'display export text');

$reflection = new ReflectionClass(AssistantParameterNormalizer::class);
$sort = $reflection->getMethod('normalizeSort');
$assert($sort->invoke(null, null, 'les meilleurs élèves') === 'desc', 'natural sort normalization');

if (in_array('--runtime', $argv, true)) {
    require_once __DIR__ . '/../src/config/app.php';
    require_once __DIR__ . '/../src/config/database.php';
    require_once __DIR__ . '/../src/services/AssistantRuntimeHealth.php';
    try {
        $health = AssistantRuntimeHealth::check(Database::getInstance());
        $assert($health['connected'] === true, 'database connection');
        $assert($health['missing'] === [], 'required runtime tables');
        fwrite(STDOUT, json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    } catch (Throwable $exception) {
        $failures[] = 'runtime: ' . $exception->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "assistant phase 6 tests passed\n");
