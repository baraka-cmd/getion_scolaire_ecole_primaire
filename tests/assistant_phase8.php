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

$all = AssistantCapabilityRegistry::all();
$expectedCapabilities = [
    'students.count',
    'students.list',
    'payments.summary',
    'payments.unpaid_students',
    'attendance.summary',
    'attendance.list',
    'results.summary',
    'results.top_students',
    'results.failed_students',
    'results.proclamation',
];
foreach ($expectedCapabilities as $capability) {
    $assert(isset($all[$capability]), 'capacité absente : ' . $capability);
}

$catalog = [];
foreach (AssistantCapabilityRegistry::forIntent('resultats') as $key => $definition) {
    $catalog['resultats']['capabilities'][$key] = $definition;
}
$catalog['resultats']['parameters'] = [
    'classe' => 'classe',
    'periode' => 'période',
    'statut_resultat' => 'statut',
];
$catalog['inconnu'] = ['parameters' => [], 'capabilities' => []];

$ready = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'capability' => 'results.top_students',
    'parameters' => ['classe' => 'première année', 'periode' => 'premier trimestre'],
], $catalog);
$assert($ready['status'] === AssistantExecutionContract::READY, 'demande valide non prête');
$assert($ready['capability'] === 'results.top_students', 'capacité valide non conservée');

$unsupported = AssistantExecutionContract::normalizeClassification([
    'intent' => 'inconnu',
    'parameters' => [],
], $catalog);
$assert($unsupported['status'] === AssistantExecutionContract::UNSUPPORTED, 'demande inconnue acceptée');

$clarification = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'capability' => 'results.summary',
    'parameters' => [],
    'needs_clarification' => true,
    'clarification' => 'Quelle période souhaitez-vous consulter ?',
], $catalog);
$assert($clarification['status'] === AssistantExecutionContract::NEEDS_CLARIFICATION, 'clarification non détectée');

$invalidCapability = AssistantExecutionContract::normalizeClassification([
    'intent' => 'resultats',
    'capability' => 'results.unknown',
    'parameters' => [],
], $catalog);
$assert($invalidCapability['status'] === AssistantExecutionContract::UNSUPPORTED, 'capacité inconnue acceptée');

$display = AssistantDisplayContract::fromResult([
    'type' => 'result_ranking',
    'summary' => ['items' => [
        ['rang' => 1, 'prenom' => 'A', 'nom' => 'B', 'moyenne_generale' => 18, 'decision' => 'Passe'],
    ]],
]);
$assert($display['type'] === 'table', 'classement non tabulaire');
$assert(in_array('copy', $display['actions'], true), 'action copie absente');
$assert(in_array('download', $display['actions'], true), 'action téléchargement absente');
$assert(str_contains($display['export_text'], '18'), 'valeur absente de l’export');

$writer = AssistantResponseWriter::write([
    'type' => 'result_ranking',
    'summary' => ['items' => [
        ['prenom' => 'A', 'nom' => 'B', 'moyenne_generale' => 18],
    ]],
]);
$assert(str_contains($writer, '18'), 'rédaction sans donnée vérifiée');

$proclamation = AssistantResponseWriter::write([
    'type' => 'proclamation',
    'summary' => ['total' => 6, 'reussis' => 4, 'echoues' => 2],
]);
$assert(str_contains($proclamation, '4 admis'), 'proclamation non rédigée distinctement');

$report = [
    'passed' => $passed,
    'failed' => count($failures),
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

if ($failures !== []) {
    exit(1);
}
