<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Transforme les paramètres compris par l'IA en valeurs métier sûres.
 */
final class AssistantParameterNormalizer
{
    /**
     * @param array<string, mixed> $parameters
     * @return array{parameters: array<string, mixed>, clarification: string}
     */
    public static function normalize(array $parameters, string $message, AssistantRepository $repository): array
    {
        $normalized = $parameters;
        $text = self::fold($message);

        $classTerm = self::stringValue($parameters['classe'] ?? null);
        if ($classTerm !== null) {
            $classId = $repository->resolveClassId($classTerm);
            if ($classId === null) {
                return [
                    'parameters' => $normalized,
                    'clarification' => 'Je ne trouve pas la classe « ' . $classTerm . ' ». Pouvez-vous indiquer son nom exact ?',
                ];
            }
            $normalized['classe_id'] = $classId;
        }

        $yearTerm = self::stringValue($parameters['annee_scolaire'] ?? null);
        if ($yearTerm !== null) {
            $yearId = $repository->resolveSchoolYearId($yearTerm);
            if ($yearId === null) {
                return [
                    'parameters' => $normalized,
                    'clarification' => 'Je ne trouve pas l’année scolaire « ' . $yearTerm . ' ». Pouvez-vous vérifier son libellé ?',
                ];
            }
            $normalized['annee_scolaire_id'] = $yearId;
        } else {
            $normalized['annee_scolaire_id'] = $repository->activeSchoolYearId();
        }

        $periodTerm = self::stringValue($parameters['periode'] ?? null);
        if ($periodTerm !== null) {
            $periodId = $repository->resolveTrimesterId($periodTerm, (int) $normalized['annee_scolaire_id']);
            if ($periodId === null) {
                return [
                    'parameters' => $normalized,
                    'clarification' => 'Je ne trouve pas la période « ' . $periodTerm . ' ». Pouvez-vous préciser le trimestre demandé ?',
                ];
            }
            $normalized['trimestre_id'] = $periodId;
        }

        $paymentStatus = self::normalizePaymentStatus($parameters);
        if ($paymentStatus !== null) {
            $normalized['statut_paiement'] = $paymentStatus;
        }
        $resultStatus = self::normalizeResultStatus($parameters);
        if ($resultStatus !== null) {
            $normalized['statut_resultat'] = $resultStatus;
        }
        $absenceStatus = self::normalizeAbsenceStatus($parameters);
        if ($absenceStatus !== null) {
            $normalized['statut_absence'] = $absenceStatus;
        }

        $dates = self::normalizeDates(
            self::stringValue($parameters['periode'] ?? null),
            $text
        );
        if ($dates !== []) {
            $normalized = array_merge($normalized, $dates);
        }

        $limit = filter_var($parameters['limite'] ?? null, FILTER_VALIDATE_INT);
        if ($limit !== false && $limit !== null) {
            $normalized['limite'] = max(1, min(100, $limit));
        }

        $sort = self::normalizeSort(self::stringValue($parameters['tri'] ?? null), $text);
        if ($sort !== null) {
            $normalized['tri'] = $sort;
        }

        return ['parameters' => $normalized, 'clarification' => ''];
    }

    private static function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
    }

    /** @param array<string, mixed> $parameters */
    private static function normalizePaymentStatus(array $parameters): ?string
    {
        $value = self::stringValue($parameters['statut_paiement'] ?? null);
        if ($value === null) {
            return null;
        }
        $value = self::fold($value);
        return match (true) {
            str_contains($value, 'impay') => 'Impayé',
            str_contains($value, 'partiel') => 'Partiel',
            str_contains($value, 'paye') => 'Payé',
            default => null,
        };
    }

    /** @param array<string, mixed> $parameters */
    private static function normalizeResultStatus(array $parameters): ?string
    {
        $value = self::stringValue($parameters['statut_resultat'] ?? null);
        if ($value === null) {
            return null;
        }
        $value = self::fold($value);
        return match (true) {
            str_contains($value, 'echec'), str_contains($value, 'echoue'), str_contains($value, 'non admis') => 'Échoué',
            str_contains($value, 'reussi'), str_contains($value, 'admis') => 'Réussi',
            default => null,
        };
    }

    /** @param array<string, mixed> $parameters */
    private static function normalizeAbsenceStatus(array $parameters): ?string
    {
        $value = self::stringValue($parameters['statut_absence'] ?? null);
        if ($value === null) {
            return null;
        }
        $value = self::fold($value);
        return match (true) {
            str_contains($value, 'non') && str_contains($value, 'just') => 'Non justifiée',
            str_contains($value, 'just') => 'Justifiée',
            default => null,
        };
    }

    /** @return array<string, string> */
    private static function normalizeDates(?string $period, string $text): array
    {
        $value = self::fold((string) $period);
        $today = new DateTimeImmutable('today');
        if (str_contains($value, 'mois') || str_contains($text, 'ce mois')) {
            return [
                'periode_debut' => $today->modify('first day of this month')->format('Y-m-d'),
                'periode_fin' => $today->modify('last day of this month')->format('Y-m-d'),
            ];
        }
        if (str_contains($value, 'semaine') || str_contains($text, 'cette semaine')) {
            return [
                'periode_debut' => $today->modify('monday this week')->format('Y-m-d'),
                'periode_fin' => $today->modify('sunday this week')->format('Y-m-d'),
            ];
        }
        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\s*(?:au|a|[-–])\s*(20\d{2}-\d{2}-\d{2})\b/', $text, $matches) === 1) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[2]);
            if ($start !== false && $end !== false && $start <= $end) {
                return ['periode_debut' => $matches[1], 'periode_fin' => $matches[2]];
            }
        }
        return [];
    }

    private static function normalizeSort(?string $sort, string $text): ?string
    {
        $value = self::fold((string) $sort);
        if ($value === '' && preg_match('/\b(meilleur(?:s)?|plus eleve|decroissant)\b/', $text) === 1) {
            return 'desc';
        }
        if ($value === '' && preg_match('/\b(pire|plus faible|croissant)\b/', $text) === 1) {
            return 'asc';
        }
        return in_array($value, ['asc', 'desc'], true) ? $value : null;
    }
}
