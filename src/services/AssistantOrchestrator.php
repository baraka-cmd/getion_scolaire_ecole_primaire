<?php

defined('ACCESS') or die('Accès interdit');

/** Construit un plan borné à partir d'une classification déjà validée. */
final class AssistantOrchestrator
{
    /** @param array<string,mixed> $classification @param array<string,array<string,mixed>> $allowedIntents @return array<string,mixed> */
    public function buildPlan(string $message, array $classification, array $allowedIntents): array
    {
        $intent = (string) ($classification['intent'] ?? 'inconnu');
        $capability = (string) ($classification['capability'] ?? '');
        $status = (string) ($classification['status'] ?? AssistantExecutionContract::UNSUPPORTED);
        if ($status === AssistantExecutionContract::NEEDS_CLARIFICATION) {
            return AssistantOrchestrationContract::envelope(
                AssistantOrchestrationContract::NEEDS_CLARIFICATION,
                $message,
                'Clarifier les paramètres avant toute exécution.',
                [],
                $this->missingParameters($classification),
                (string) ($classification['clarification'] ?? 'Pouvez-vous préciser votre demande ?')
            );
        }
        if ($status !== AssistantExecutionContract::READY || !isset($allowedIntents[$intent]) || $capability === '' || !isset($allowedIntents[$intent]['capabilities'][$capability])) {
            return AssistantOrchestrationContract::envelope(AssistantOrchestrationContract::UNSUPPORTED, $message, 'Aucune capacité autorisée ne correspond au plan.');
        }
        $definition = AssistantCapabilityRegistry::all()[$capability] ?? null;
        if (!is_array($definition) || ($definition['intent'] ?? null) !== $intent) {
            return AssistantOrchestrationContract::envelope(AssistantOrchestrationContract::UNSUPPORTED, $message, 'La capacité n’est pas raccordée à un outil métier valide.');
        }
        $plan = AssistantOrchestrationContract::envelope(AssistantOrchestrationContract::READY, $message, 'Obtenir un résultat fiable par une capacité métier contrôlée.', [[
            'intent' => $intent,
            'capability' => $capability,
            'required_parameters' => $definition['required_parameters'] ?? [],
            'expected_output' => $definition['output'] ?? 'structured_result',
        ]]);
        return AssistantOrchestrationContract::validate($plan, $classification);
    }

    /** @param array<string,mixed> $classification @return array<int,string> */
    private function missingParameters(array $classification): array
    {
        $params = is_array($classification['parameters'] ?? null) ? $classification['parameters'] : [];
        $missing = [];
        foreach (['classe', 'annee_scolaire', 'periode'] as $name) {
            if (!isset($params[$name]) || $params[$name] === '') {
                $missing[] = $name;
            }
        }
        return $missing;
    }
}
