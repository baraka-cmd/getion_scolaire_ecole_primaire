<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Décrit de manière uniforme comment une réponse métier doit être affichée.
 */
final class AssistantDisplayContract
{
    /**
     * @param array<string, mixed> $result
     * @return array{type: string, title: string, columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>, actions: array<int, string>, export_text: string}
     */
    public static function fromResult(array $result): array
    {
        $type = (string) ($result['type'] ?? 'text');
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        return match ($type) {
            'payment_students' => self::table(
                'Élèves avec solde restant',
                [['key' => 'nom_complet', 'label' => 'Élève'], ['key' => 'classe', 'label' => 'Classe'], ['key' => 'reste', 'label' => 'Reste']],
                array_map(static fn (array $item): array => [
                    'nom_complet' => trim(($item['prenom'] ?? '') . ' ' . ($item['nom'] ?? '')),
                    'classe' => $item['classe'] ?? '',
                    'reste' => $item['reste'] ?? 0,
                ], $items)
            ),
            'absence_list' => self::table(
                'Liste des absences',
                [['key' => 'nom_complet', 'label' => 'Élève'], ['key' => 'classe', 'label' => 'Classe'], ['key' => 'date_absence', 'label' => 'Date'], ['key' => 'justification', 'label' => 'Statut']],
                array_map(static fn (array $item): array => [
                    'nom_complet' => trim(($item['prenom'] ?? '') . ' ' . ($item['nom'] ?? '')),
                    'classe' => $item['classe'] ?? '',
                    'date_absence' => $item['date_absence'] ?? '',
                    'justification' => !empty($item['est_justifiee']) ? 'Justifiée' : 'Non justifiée',
                ], $items)
            ),
            'result_summary', 'result_ranking', 'proclamation' => self::table(
                $type === 'result_ranking'
                    ? 'Classement des élèves'
                    : ($type === 'proclamation' ? 'Proclamation officielle' : 'Synthèse des résultats'),
                [['key' => 'rang', 'label' => 'Rang'], ['key' => 'nom_complet', 'label' => 'Élève'], ['key' => 'moyenne_generale', 'label' => 'Moyenne'], ['key' => 'decision', 'label' => 'Décision']],
                array_map(static fn (array $item): array => [
                    'rang' => $item['rang'] ?? '',
                    'nom_complet' => trim(($item['prenom'] ?? '') . ' ' . ($item['nom'] ?? '')),
                    'moyenne_generale' => $item['moyenne_generale'] ?? '',
                    'decision' => $item['decision'] ?? '',
                ], is_array($summary['items'] ?? null) ? $summary['items'] : [])
            ),
            default => self::defaultDisplay($type, $items),
        };
    }

    /**
     * @param array<int, array{key: string, label: string}> $columns
     * @param array<int, array<string, mixed>> $rows
     * @return array{type: string, title: string, columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>, actions: array<int, string>, export_text: string}
     */
    private static function table(string $title, array $columns, array $rows): array
    {
        $exportRows = [$title, implode("\t", array_column($columns, 'label'))];
        foreach ($rows as $row) {
            $exportRows[] = implode("\t", array_map(
                static fn (array $column): string => (string) ($row[$column['key']] ?? ''),
                $columns
            ));
        }
        return [
            'type' => 'table',
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'actions' => ['copy', 'download'],
            'export_text' => implode("\n", $exportRows),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function defaultDisplay(string $type, array $items): array
    {
        return [
            'type' => $type,
            'title' => 'Résultats',
            'columns' => [],
            'rows' => $items,
            'actions' => $items !== [] ? ['copy', 'download'] : [],
            'export_text' => '',
        ];
    }
}
