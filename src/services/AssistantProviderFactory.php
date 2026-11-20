<?php

defined('ACCESS') or die('Accès interdit');

final class AssistantProviderFactory
{
    public static function create(): AssistantProviderInterface
    {
        return match (ASSISTANT_PROVIDER) {
            'gemini' => new GeminiService(),
            default => throw new RuntimeException('Fournisseur IA non supporté.'),
        };
    }
}
