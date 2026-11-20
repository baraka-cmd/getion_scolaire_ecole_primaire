<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AssistantCapabilityRegistry.php';
require_once __DIR__ . '/../src/services/AssistantExecutionContract.php';
require_once __DIR__ . '/../src/services/AssistantDisplayContract.php';
require_once __DIR__ . '/../src/services/AssistantResponseWriter.php';

$failures = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $message;
};

$capabilities = AssistantCapabilityRegistry::all();
$assert($capabilities !== [], 'registre de capacités vide');
foreach ($capabilities as $name => $definition) {
    $assert((bool) preg_match('/^[a-z_]+\.[a-z_]+$/', $name), 'nom de capacité invalide : ' . $name);
    $assert($definition['intent'] !== '', 'intention absente : ' . $name);
    $assert($definition['permission'] !== '', 'permission absente : ' . $name);
    $assert($definition['output'] !== '', 'format de sortie absent : ' . $name);
}

$catalog = [];
foreach (AssistantCapabilityRegistry::forIntent('resultats') as $key => $definition) {
    $catalog['resultats']['capabilities'][$key] = $definition;
}
$catalog['resultats']['parameters'] = ['classe' => 'classe', 'periode' => 'période'];

$missingCapability = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'parameters' => [],
], $catalog);
$assert($missingCapability['status'] === AssistantExecutionContract::READY, 'capacité absente non résolue');

$invalidCapability = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'capability' => 'results.invalid',
    'parameters' => [],
], $catalog);
$assert($invalidCapability['status'] === AssistantExecutionContract::UNSUPPORTED, 'capacité invalide exécutée');

$displayTypes = [
    ['type' => 'payment_students', 'items' => [['prenom' => 'A', 'nom' => 'B', 'reste' => 10]]],
    ['type' => 'absence_list', 'items' => [['prenom' => 'A', 'nom' => 'B', 'date_absence' => '2026-11-11']]],
    ['type' => 'result_ranking', 'summary' => ['items' => [['prenom' => 'A', 'nom' => 'B', 'moyenne_generale' => 18]]]],
    ['type' => 'proclamation', 'summary' => ['total' => 1, 'reussis' => 1, 'echoues' => 0, 'items' => []]],
];
foreach ($displayTypes as $result) {
    $display = AssistantDisplayContract::fromResult($result);
    $assert(in_array('copy', $display['actions'], true), 'copie absente pour ' . $result['type']);
    $assert(in_array('download', $display['actions'], true), 'téléchargement absent pour ' . $result['type']);
    $assert($display['export_text'] !== '', 'export vide pour ' . $result['type']);
}

$report = ['passed' => $passed, 'failed' => count($failures), 'failures' => $failures];
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

if ($failures !== []) {
    exit(1);
}
