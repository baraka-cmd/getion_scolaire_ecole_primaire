<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/src/services/AssistantSecureActionService.php',
    'audit_service' => $root . '/src/services/AssistantSecurityAuditService.php',
    'capability_audit' => $root . '/src/services/AssistantCapabilitySecurityAudit.php',
    'controller' => $root . '/src/controllers/AssistantSecureActionController.php',
    'policy' => $root . '/src/policies/StudentAccessPolicy.php',
    'access_repository' => $root . '/src/repositories/StudentAccessRepository.php',
    'migration' => $root . '/database/migrations/20261128_002_assistant_secure_actions.sql',
    'router' => $root . '/public/index.php',
];
$source = [];
foreach ($files as $key => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Phase G file: {$key}\n");
        exit(1);
    }
    $source[$key] = (string) file_get_contents($path);
}
$checks = [
    'action is explicitly allowlisted' => str_contains($source['service'], "grades.store"),
    'confirmation token generated with CSPRNG' => str_contains($source['service'], 'random_bytes(32)'),
    'only token hash persisted' => str_contains($source['service'], 'confirmation_token_hash') && str_contains($source['service'], 'hash(\'sha256\', $token)'),
    'payload integrity checked' => str_contains($source['service'], 'payload_sha256'),
    'one-time row lock' => str_contains($source['service'], 'FOR UPDATE'),
    'ownership enforced in query' => str_contains($source['service'], 'utilisateur_id = :user_id'),
    'separate proposal and confirmation permissions' => str_contains($source['service'], "requirePermission('assistant.actions.proposer')")
        && str_contains($source['service'], "requirePermission('assistant.actions.confirmer')")
        && str_contains($source['migration'], 'assistant.actions.proposer'),
    'evaluation/class assignment rechecked' => str_contains($source['service'], 'teacherHasClassAssignment'),
    'student active in evaluation class' => str_contains($source['service'], 'getActiveStudentIds'),
    'subject and official maximum checked' => str_contains($source['service'], 'getSubjectMaxima'),
    'absence and score mutually validated' => str_contains($source['service'], 'Une absence ne peut pas avoir de note numérique'),
    'domain service used for write' => str_contains($source['service'], 'saveCell('),
    'mutation and action status share transaction' => str_contains($source['service'], 'beginTransaction')
        && str_contains($source['service'], 'commit()') && str_contains($source['service'], "statut = 'executed'")
        && str_contains($source['service'], 'new EvaluationNoteRepository($this->db)'),
    'audit records action outcome' => str_contains($source['service'], 'assistant.action.grade.executed'),
    'controller enforces CSRF' => str_contains($source['controller'], 'verifyCsrfToken(csrfTokenFromRequest())'),
    'prepare and confirm routes exist' => str_contains($source['router'], '/api/assistant/actions/grade/prepare')
        && str_contains($source['router'], '/api/assistant/actions/grade/confirm'),
    'parent record scoped by user' => str_contains($source['access_repository'], 'pt.utilisateur_id = :user_id'),
    'teacher access scoped by active enrollment' => str_contains($source['access_repository'], 'i.statut IN'),
    'audit details redact sensitive keys' => str_contains($source['audit_service'], 'password|token|secret|authorization|note_value|raw_payload|email|phone|address'),
    'audit IP is pseudonymized only with configured secret' => str_contains($source['audit_service'], 'APP_AUDIT_HASH_KEY') && str_contains($source['audit_service'], 'hash_hmac'),
    'capability audit class does not collide with event audit class' => str_contains($source['capability_audit'], 'final class AssistantCapabilitySecurityAudit'),
    'business scope is revalidated at confirmation' => str_contains($source['service'], 'validateGrade(') && str_contains($source['service'], 'FOR UPDATE'),
    'migration records action and audit schemas' => str_contains($source['migration'], 'assistant_action_requests')
        && str_contains($source['migration'], 'assistant_security_audit'),
];

$failed = [];
foreach ($checks as $name => $condition) {
    if (!$condition) {
        $failed[] = $name;
    }
}
fwrite(STDOUT, json_encode([
    'test' => 'assistant_phase_g_security_smoke',
    'passed' => count($checks) - count($failed),
    'failed' => count($failed),
    'failures' => $failed,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
exit($failed === [] ? 0 : 1);
