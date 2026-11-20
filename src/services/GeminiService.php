<?php
/**
 * Client minimal et strict pour l'API Gemini Interactions.
 */

defined('ACCESS') or die('Accès interdit');

final class GeminiService implements AssistantProviderInterface
{
    /**
     * Analyse une demande et renvoie uniquement un objet d'intention validable.
     *
     * @param array<string, array{permission: string, description: string, parameters: array<string, string>}> $allowedIntents
     * @return array{intent: string, capability: string, parameters: array<string, mixed>, needs_clarification: bool, clarification: string}
     */
    public function classify(string $message, array $allowedIntents, array $conversationContext = []): array
    {
        if (GEMINI_API_KEY === '') {
            throw new RuntimeException('La clé API Gemini n’est pas configurée.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Le module PHP cURL est requis pour contacter Gemini.');
        }

        $catalogue = [];
        foreach ($allowedIntents as $name => $definition) {
            $catalogue[$name] = [
                'description' => $definition['description'],
                'parameters' => $definition['parameters'],
                'capacites' => $definition['capabilities'] ?? [],
            ];
        }

        $systemInstruction = 'Tu es un classificateur de demandes pour une application scolaire. '
            . 'Tu ne dois jamais produire de SQL, de code, de données inventées, de secrets ou de décision de permission. '
            . 'Choisis exclusivement une intention et, si possible, une capacité dans le catalogue fourni. '
            . 'La capacité doit appartenir à l’intention choisie et ne sert qu’à décrire la demande ; PHP vérifiera toujours la permission et exécutera les données. '
            . 'Une demande peut couvrir plusieurs informations appartenant au même domaine. '
            . 'Si elle couvre plusieurs domaines, choisis l’intention principale et indique les paramètres utiles. '
            . 'Si le domaine n’est pas autorisé dans le catalogue ou si la demande est ambiguë, utilise "inconnu". '
            . 'Réponds uniquement en JSON valide avec les clés intent, capability, parameters, needs_clarification, clarification.';

        $payload = [
            'input' => json_encode([
                'catalogue_autorise' => $catalogue,
                'contexte_conversation' => $conversationContext,
                'demande_utilisateur' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'system_instruction' => $systemInstruction,
            'response_format' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string'],
                    'capability' => ['type' => 'string'],
                    'parameters' => ['type' => 'object'],
                    'needs_clarification' => ['type' => 'boolean'],
                    'clarification' => ['type' => 'string'],
                ],
                'required' => ['intent', 'capability', 'parameters', 'needs_clarification', 'clarification'],
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/interactions';
        $models = array_values(array_unique(array_filter(array_merge([GEMINI_MODEL], GEMINI_FALLBACK_MODELS))));
        $response = false;
        $status = 0;
        $curlError = '';
        $lastProviderMessage = '';

        foreach ($models as $model) {
            $payload['model'] = $model;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Impossible d’initialiser la connexion Gemini.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . GEMINI_API_KEY,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => min(10, GEMINI_API_TIMEOUT),
                CURLOPT_TIMEOUT => GEMINI_API_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response !== false && $status >= 200 && $status < 300) {
                break;
            }

            $providerError = is_string($response) ? json_decode($response, true) : [];
            $lastProviderMessage = (string) ($providerError['error']['message'] ?? '');
            error_log('Gemini modèle ' . $model . ' HTTP ' . $status . ' : ' . substr((string) $response, 0, 500));

            if ($status === 401 || $status === 403) {
                throw new RuntimeException('La clé API Gemini est refusée ou n’a pas accès à ce modèle.');
            }
        }

        if ($response === false) {
            throw new RuntimeException('Connexion Gemini impossible : ' . $curlError);
        }
        if ($status < 200 || $status >= 300) {
            if ($status === 404) {
                throw new RuntimeException('Aucun modèle Gemini configuré n’est disponible pour ce projet.');
            }
            if ($status === 503) {
                throw new RuntimeException('Les modèles Gemini sont temporairement surchargés. Réessayez dans quelques instants.');
            }
            if ($status === 429) {
                throw new RuntimeException('La limite de requêtes Gemini est atteinte sur les modèles disponibles. Réessayez plus tard.');
            }
            throw new RuntimeException($lastProviderMessage !== ''
                ? 'Le service Gemini est indisponible : ' . $lastProviderMessage
                : 'Le service Gemini est momentanément indisponible.');
        }

        $decoded = json_decode($response, true);
        $steps = $decoded['steps'] ?? [];
        $modelOutput = null;
        if (is_array($steps)) {
            foreach (array_reverse($steps) as $step) {
                if (is_array($step) && ($step['type'] ?? '') === 'model_output') {
                    $modelOutput = $step['content'] ?? null;
                    break;
                }
            }
        }
        if (!is_array($modelOutput) || $modelOutput === []) {
            throw new RuntimeException('Réponse Gemini vide ou invalide.');
        }

        $classification = null;
        foreach ($modelOutput as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['json']) && is_array($part['json'])) {
                $classification = $part['json'];
                break;
            }
            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                $classification = json_decode($part['text'], true);
                break;
            }
        }
        if (!is_array($classification)) {
            throw new RuntimeException('Réponse Gemini non conforme.');
        }

        $intent = (string) ($classification['intent'] ?? 'inconnu');
        if (!isset($allowedIntents[$intent])) {
            $intent = 'inconnu';
        }

        $parameters = $classification['parameters'] ?? [];
        return [
            'intent' => $intent,
            'capability' => trim((string) ($classification['capability'] ?? '')),
            'parameters' => is_array($parameters) ? $parameters : [],
            'needs_clarification' => (bool) ($classification['needs_clarification'] ?? false),
            'clarification' => trim((string) ($classification['clarification'] ?? '')),
        ];
    }
}
