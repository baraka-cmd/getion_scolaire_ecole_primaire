<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Vérifications non destructives de l'installation assistant.
 */
final class AssistantRuntimeHealth
{
    /**
     * @return array{connected: bool, tables: array<string, bool>, missing: array<int, string>}
     */
    public static function check(Database $database): array
    {
        $requiredTables = [
            'assistant_conversations',
            'assistant_messages',
            'assistant_attachments',
            'assistant_imports',
            'assistant_rate_limits',
            'eleves',
            'inscriptions',
            'classes',
            'annees_scolaires',
            'trimestres',
            'resultats_trimestriels',
            'paiements',
            'absences',
        ];
        $tables = [];
        $missing = [];
        foreach ($requiredTables as $table) {
            $exists = (int) $database->fetchColumn(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table',
                ['table' => $table]
            ) === 1;
            $tables[$table] = $exists;
            if (!$exists) {
                $missing[] = $table;
            }
        }

        return [
            'connected' => true,
            'tables' => $tables,
            'missing' => $missing,
        ];
    }
}
