<?php

defined('ACCESS') or die('Accès interdit');

/** Contrat versionné : un plan ne confère jamais de permission ni n'exécute du SQL. */
final class AssistantOrchestrationContract
{
    public const VERSION = '1.0';
    public const READY = 'ready';
    public const NEEDS_CLARIFICATION = 'needs_clarification';
    public const UNSUPPORTED = 'unsupported';

    /** @return array<string,mixed> */
    public static function envelope(string $status, string $request, string $goal, array $steps = [], array $missing = [], string $clarification = ''): array
    {
        return [
            'contract_version' => self::VERSION,
            'status' => in_array($status, [self::READY, self::NEEDS_CLARIFICATION, self::UNSUPPORTED], true) ? $status : self::UNSUPPORTED,
            'request' => mb_substr(trim($request), 0, ASSISTANT_MAX_MESSAGE_LENGTH, 'UTF-8'),
            'goal' => mb_substr(trim($goal), 0, 500, 'UTF-8'),
            'steps' => array_values($steps),
            'missing_parameters' => array_values(array_unique($missing)),
            'clarification' => mb_substr(trim($clarification), 0, 1000, 'UTF-8'),
        ];
    }

    /** @param array<string,mixed> $classification @param array<string,mixed> $plan */
    public static function validate(array $plan, array $classification): array
    {
        $intent = (string) ($classification['intent'] ?? 'inconnu');
        $capability = (string) ($classification['capability'] ?? '');
        $parameters = is_array($classification['parameters'] ?? null) ? $classification['parameters'] : [];
        $steps = [];
        foreach (($plan['steps'] ?? []) as $step) {
            if (!is_array($step) || ($step['intent'] ?? null) !== $intent || ($step['capability'] ?? null) !== $capability) {
                continue;
            }
            $steps[] = [
                'id' => 'step_' . (count($steps) + 1),
                'intent' => $intent,
                'capability' => $capability,
                'parameters' => $parameters,
                'requires_permission_check' => true,
                'execution' => 'backend_only',
            ];
        }
        return self::envelope((string) ($plan['status'] ?? self::UNSUPPORTED), (string) ($plan['request'] ?? ''), (string) ($plan['goal'] ?? ''), $steps, (array) ($plan['missing_parameters'] ?? []), (string) ($plan['clarification'] ?? ''));
    }
}
