<?php

defined('ACCESS') or die('Accès interdit');

interface AssistantProviderInterface
{
    /**
     * @param array<string, array{permission: string, description: string, parameters: array<string, string>}> $allowedIntents
     * @return array{intent: string, parameters: array<string, mixed>, needs_clarification: bool, clarification: string}
     */
    public function classify(string $message, array $allowedIntents, array $conversationContext = []): array;
}
