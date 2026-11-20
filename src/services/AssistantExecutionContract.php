<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Contrat commun entre la compréhension, l'exécution métier et l'interface.
 *
 * Les données métier restent calculées par PHP. Cette classe ne décide jamais
 * d'une permission et ne construit aucune requête SQL.
 */
final class AssistantExecutionContract
{
    public const READY = 'ready';
    public const NEEDS_CLARIFICATION = 'needs_clarification';
    public const UNSUPPORTED = 'unsupported';
    public const EXECUTION_FAILED = 'execution_failed';
    public const COMPLETED = 'completed';

    /**
     * @param array<string, array<string, mixed>> $allowedIntents
     * @return array{status: string, intent: string, capability: string, parameters: array<string, mixed>, needs_clarification: bool, clarification: string}
     */
    public static function normalizeClassification(array $classification, array $allowedIntents): array
    {
        $intent = trim((string) ($classification['intent'] ?? 'inconnu'));
        if ($intent === '' || !isset($allowedIntents[$intent])) {
            return self::classification(
                self::UNSUPPORTED,
                'inconnu',
                '',
                [],
                'Cette demande ne correspond pas à une capacité disponible.'
            );
        }

        $rawParameters = $classification['parameters'] ?? [];
        if (!is_array($rawParameters)) {
            return self::classification(
                self::NEEDS_CLARIFICATION,
                $intent,
                '',
                [],
                'Je n’ai pas pu comprendre les paramètres de votre demande. Pouvez-vous la préciser ?'
            );
        }

        $parameters = self::normalizeParameters(
            $rawParameters,
            is_array($allowedIntents[$intent]['parameters'] ?? null)
                ? $allowedIntents[$intent]['parameters']
                : []
        );
        $capability = trim((string) ($classification['capability'] ?? ''));
        $capabilities = is_array($allowedIntents[$intent]['capabilities'] ?? null)
            ? $allowedIntents[$intent]['capabilities']
            : [];
        if ($capability !== '' && !isset($capabilities[$capability])) {
            return self::classification(
                self::UNSUPPORTED,
                $intent,
                '',
                $parameters,
                'Cette capacité n’est pas disponible pour votre demande.'
            );
        }
        if ($capability === '') {
            $capability = array_key_first($capabilities) ?? '';
        }
        $clarification = trim((string) ($classification['clarification'] ?? ''));
        $needsClarification = (bool) ($classification['needs_clarification'] ?? false);

        if ($intent === 'inconnu') {
            return self::classification(
                $needsClarification ? self::NEEDS_CLARIFICATION : self::UNSUPPORTED,
                $intent,
                '',
                $parameters,
                $clarification !== '' ? $clarification : 'Cette demande est hors du périmètre autorisé ou nécessite une précision.'
            );
        }

        if ($needsClarification) {
            return self::classification(
                self::NEEDS_CLARIFICATION,
                $intent,
                $capability,
                $parameters,
                $clarification !== '' ? $clarification : 'Pouvez-vous préciser votre demande ?'
            );
        }

        return self::classification(self::READY, $intent, $capability, $parameters, '');
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeParameters(array $parameters, array $schema): array
    {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $schema)) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            } elseif (!is_scalar($value) && $value !== null) {
                continue;
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{status: string, intent: string, capability: string, parameters: array<string, mixed>, needs_clarification: bool, clarification: string}
     */
    private static function classification(
        string $status,
        string $intent,
        string $capability,
        array $parameters,
        string $clarification
    ): array {
        return [
            'status' => $status,
            'intent' => $intent,
            'capability' => $capability,
            'parameters' => $parameters,
            'needs_clarification' => $status === self::NEEDS_CLARIFICATION,
            'clarification' => $clarification,
        ];
    }

    /**
     * Construit l'enveloppe stable renvoyée par les endpoints assistant.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function response(
        string $status,
        string $message,
        string $intent = 'inconnu',
        string $capability = '',
        array $parameters = [],
        array $data = [],
        array $extra = []
    ): array {
        return array_merge([
            'status' => $status,
            'intent' => $intent,
            'capability' => $capability,
            'parameters' => $parameters,
            'data' => $data,
            'display' => class_exists('AssistantDisplayContract') && $data !== []
                ? AssistantDisplayContract::fromResult($data)
                : [
                    'type' => $data['type'] ?? 'text',
                    'title' => 'Réponse',
                    'columns' => [],
                    'rows' => [],
                    'actions' => $data !== [] ? ['copy', 'download'] : [],
                    'export_text' => '',
                ],
            'context' => [],
            'meta' => [
                'source' => 'application',
                'generated_at' => date(DATE_ATOM),
            ],
        ], $extra);
    }
}
