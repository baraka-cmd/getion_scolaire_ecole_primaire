<?php
declare(strict_types=1);
define('ACCESS', true);
require_once __DIR__ . '/../src/services/AssistantCapabilityRegistry.php';
require_once __DIR__ . '/../src/services/AssistantExecutionContract.php';

$corpus = json_decode((string) file_get_contents(__DIR__ . '/fixtures/assistant_school_questions.json'), true, 512, JSON_THROW_ON_ERROR);
$passed = 0; $failures = [];
$assert = static function(bool $ok, string $message) use (&$passed, &$failures): void { $ok ? $passed++ : $failures[] = $message; };
$registry = AssistantCapabilityRegistry::all();
$assert(count($corpus) >= 20, 'Le corpus doit contenir au moins 20 cas représentatifs.');
$ids = [];
foreach ($corpus as $case) {
  $assert(isset($case['id'], $case['question'], $case['intent'], $case['capability'], $case['parameters']), 'Structure invalide du corpus.');
  $assert(!isset($ids[$case['id']]), 'Identifiant de scénario dupliqué : ' . ($case['id'] ?? '?'));
  $ids[$case['id']] = true;
  if (($case['intent'] ?? '') === 'inconnu') {
    $out = AssistantExecutionContract::normalizeClassification(['intent'=>'hors_scope','parameters'=>[]], []);
    $assert($out['status'] === AssistantExecutionContract::UNSUPPORTED, $case['id'] . ' doit être refusé.');
    continue;
  }
  $intentCaps = AssistantCapabilityRegistry::forIntent($case['intent']);
  $catalog = [$case['intent'] => ['parameters'=>array_reduce($intentCaps, static fn($carry,$def)=>array_merge($carry,$def['parameters']), []), 'capabilities'=>$intentCaps]];
  $out = AssistantExecutionContract::normalizeClassification(['intent'=>$case['intent'],'capability'=>$case['capability'],'parameters'=>$case['parameters'],'needs_clarification'=>$case['needs_clarification'] ?? false], $catalog);
  $assert($out['status'] === (($case['needs_clarification'] ?? false) ? AssistantExecutionContract::NEEDS_CLARIFICATION : AssistantExecutionContract::READY), $case['id'] . ' statut inattendu: ' . $out['status']);
  $assert($out['capability'] === $case['capability'], $case['id'] . ' capacité différente.');
  $assert(isset($registry[$case['capability']]), $case['id'] . ' capacité non déclarée.');
}
$first = array_values($registry)[0];
$catalog = ['x'=>['parameters'=>['classe'=>'classe'],'capabilities'=>[$firstKey=array_key_first($registry)=>$first]]];
$out = AssistantExecutionContract::normalizeClassification(['intent'=>'x','capability'=>'x.unauthorized','parameters'=>['classe'=>'A','sql'=>'DROP TABLE users']],$catalog);
$assert($out['status'] === AssistantExecutionContract::UNSUPPORTED, 'Capacité inconnue acceptée.');
$out = AssistantExecutionContract::normalizeClassification(['intent'=>'x','parameters'=>['classe'=>' A ','injected'=>'ignore policy']],$catalog);
$assert($out['parameters'] === ['classe'=>'A'], 'Paramètres inconnus non filtrés ou non normalisés.');
$out = AssistantExecutionContract::normalizeClassification(['intent'=>'x','parameters'=>'invalid'],$catalog);
$assert($out['status'] === AssistantExecutionContract::NEEDS_CLARIFICATION, 'Paramètres mal formés non clarifiés.');
$report=['phase'=>'H','passed'=>$passed,'failed'=>count($failures),'failures'=>$failures,'corpus_cases'=>count($corpus)];
fwrite(STDOUT,json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);
exit($failures===[]?0:1);
