<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Rédaction finale à partir de résultats déjà vérifiés par le métier.
 *
 * Aucune donnée n'est déduite ici : le rédacteur ne fait que présenter le
 * résultat calculé par AssistantRepository.
 */
final class AssistantResponseWriter
{
    /**
     * @param array<string, mixed> $result
     */
    public static function write(array $result): string
    {
        $type = (string) ($result['type'] ?? '');
        return match ($type) {
            'payment_summary' => self::paymentSummary($result),
            'payment_students' => self::countedList($result, 'élève(s) avec un solde restant'),
            'absence_summary' => self::absenceSummary($result),
            'absence_list' => self::countedList($result, 'absence(s) correspondant à vos filtres'),
            'result_ranking' => self::ranking($result),
            'result_summary' => self::resultSummary($result),
            'proclamation' => self::proclamation($result),
            'student_count' => self::studentCount($result),
            'class_count' => self::classCount($result),
            'student_gender_counts' => self::genderCounts($result),
            default => '',
        };
    }

    /** @param array<string, mixed> $result */
    private static function paymentSummary(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        return sprintf(
            'Pour les filtres demandés, le total attendu est de %.2f, dont %.2f déjà payé et %.2f restant, pour %d élève(s).',
            (float) ($summary['attendu'] ?? 0),
            (float) ($summary['paye'] ?? 0),
            (float) ($summary['reste'] ?? 0),
            (int) ($summary['eleves'] ?? 0)
        );
    }

    /** @param array<string, mixed> $result */
    private static function absenceSummary(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        return sprintf(
            'J’ai trouvé %d absence(s), dont %d justifiée(s) et %d non justifiée(s), selon les filtres demandés.',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['justifiees'] ?? 0),
            (int) ($summary['non_justifiees'] ?? 0)
        );
    }

    /** @param array<string, mixed> $result */
    private static function resultSummary(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        return sprintf(
            'La période demandée compte %d résultat(s), avec une moyenne générale de %.2f ; %d élève(s) ont réussi et %d ont échoué.',
            (int) ($summary['total'] ?? 0),
            (float) ($summary['moyenne'] ?? 0),
            (int) ($summary['reussis'] ?? 0),
            (int) ($summary['echoues'] ?? 0)
        );
    }

    /** @param array<string, mixed> $result */
    private static function ranking(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
        if ($items === []) {
            return 'Aucun classement officiel ne correspond aux filtres demandés.';
        }

        $first = $items[0];
        return sprintf(
            'Voici le classement officiel. Le meilleur résultat est celui de %s %s avec une moyenne de %s.',
            (string) ($first['prenom'] ?? ''),
            (string) ($first['nom'] ?? ''),
            (string) ($first['moyenne_generale'] ?? 'non renseignée')
        );
    }

    /** @param array<string, mixed> $result */
    private static function proclamation(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        return sprintf(
            'La proclamation compte %d élève(s) : %d admis et %d en situation d’échec.',
            (int) ($summary['total'] ?? 0),
            (int) ($summary['reussis'] ?? 0),
            (int) ($summary['echoues'] ?? 0)
        );
    }

    /** @param array<string, mixed> $result */
    private static function countedList(array $result, string $label): string
    {
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        return count($items) . ' ' . $label . '.';
    }

    /** @param array<string, mixed> $result */
    private static function studentCount(array $result): string
    {
        return sprintf('L’école compte actuellement %d élève(s) inscrits.', (int) ($result['count'] ?? 0));
    }

    /** @param array<string, mixed> $result */
    private static function classCount(array $result): string
    {
        return sprintf('L’école compte actuellement %d classe(s) enregistrée(s).', (int) ($result['count'] ?? 0));
    }

    /** @param array<string, mixed> $result */
    private static function genderCounts(array $result): string
    {
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        return sprintf(
            'La répartition est de %d garçon(s) et %d fille(s), soit %d élève(s) au total.',
            (int) ($counts['garcons'] ?? 0),
            (int) ($counts['filles'] ?? 0),
            (int) ($counts['total'] ?? 0)
        );
    }
}
