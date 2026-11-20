<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Registre déclaratif des capacités de lecture de l'assistant.
 *
 * Une capacité décrit ce qui est autorisé et attendu. Elle ne contient ni SQL
 * ni logique métier : l'exécution reste exclusivement côté PHP.
 */
final class AssistantCapabilityRegistry
{
    /**
     * @return array<string, array{
     *     intent: string,
     *     permission: string,
     *     description: string,
     *     parameters: array<string, string>,
     *     required_parameters: array<int, string>,
     *     output: string
     * }>
     */
    public static function all(): array
    {
        return [
            'students.count' => self::capability(
                'eleves',
                'eleves.consulter',
                'Compter les élèves inscrits selon les filtres autorisés.',
                ['annee_scolaire' => 'année scolaire ou active', 'classe' => 'classe ou niveau'],
                [],
                'student_count'
            ),
            'students.gender_counts' => self::capability(
                'eleves',
                'eleves.consulter',
                'Donner la répartition des élèves par genre.',
                ['annee_scolaire' => 'année scolaire ou active', 'classe' => 'classe ou niveau'],
                [],
                'student_gender_counts'
            ),
            'students.list' => self::capability(
                'eleves',
                'eleves.consulter',
                'Lister les élèves inscrits selon les filtres autorisés.',
                ['annee_scolaire' => 'année scolaire ou active', 'classe' => 'classe ou niveau', 'statut' => 'statut scolaire'],
                [],
                'student_list'
            ),
            'classes.count' => self::capability(
                'classes',
                'classes.consulter',
                'Compter les classes de l’année scolaire demandée.',
                ['annee_scolaire' => 'année scolaire ou active'],
                [],
                'class_count'
            ),
            'classes.list' => self::capability(
                'classes',
                'classes.consulter',
                'Lister les classes et leurs informations disponibles.',
                ['annee_scolaire' => 'année scolaire ou active'],
                [],
                'class_list'
            ),
            'subjects.list' => self::capability(
                'matieres',
                'matieres.consulter',
                'Lister les matières actives d’une classe ou de l’école.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active'],
                [],
                'subjects'
            ),
            'parents.list' => self::capability(
                'parents',
                'parents.consulter',
                'Lister les parents ou tuteurs liés aux élèves autorisés.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active'],
                [],
                'parents'
            ),
            'payments.summary' => self::capability(
                'paiements',
                'paiements.consulter',
                'Calculer les montants attendus, payés et restants.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'statut_paiement' => 'Payé, Partiel ou Impayé'],
                [],
                'payment_summary'
            ),
            'payments.unpaid_students' => self::capability(
                'paiements',
                'paiements.consulter',
                'Lister les élèves ayant un solde impayé ou partiel.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active'],
                [],
                'payment_students'
            ),
            'attendance.summary' => self::capability(
                'absences',
                'absences.consulter',
                'Calculer les absences justifiées et non justifiées.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'période ou dates'],
                [],
                'absence_summary'
            ),
            'attendance.list' => self::capability(
                'absences',
                'absences.consulter',
                'Lister les absences selon la classe, période et justification.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'période ou dates', 'statut_absence' => 'justifiée ou non justifiée'],
                [],
                'absence_list'
            ),
            'results.summary' => self::capability(
                'resultats',
                'resultats.consulter',
                'Calculer les statistiques de résultats d’une période.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'trimestre ou période', 'statut_resultat' => 'réussi, échoué ou admis'],
                [],
                'result_summary'
            ),
            'results.top_students' => self::capability(
                'resultats',
                'resultats.consulter',
                'Classer les meilleurs élèves selon leurs résultats officiels.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'trimestre ou période', 'limite' => 'nombre de résultats'],
                [],
                'result_ranking'
            ),
            'results.failed_students' => self::capability(
                'resultats',
                'resultats.consulter',
                'Lister les élèves ayant échoué selon la décision officielle.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'trimestre ou période'],
                [],
                'result_students'
            ),
            'results.proclamation' => self::capability(
                'resultats',
                'resultats.consulter',
                'Consulter la proclamation officielle d’une période.',
                ['classe' => 'classe ou niveau', 'annee_scolaire' => 'année scolaire ou active', 'periode' => 'trimestre ou période'],
                [],
                'proclamation'
            ),
            'reports.school_summary' => self::capability(
                'rapports',
                'rapports.consulter',
                'Produire une synthèse statistique globale de l’école.',
                ['annee_scolaire' => 'année scolaire ou active', 'indicateur' => 'effectif, taux, moyenne ou autre indicateur'],
                [],
                'school_summary'
            ),
            'school_years.list' => self::capability(
                'annees_scolaires',
                'parametres.consulter',
                'Lister les années scolaires et leur état.',
                [],
                [],
                'school_year_list'
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forIntent(string $intent): array
    {
        return array_filter(
            self::all(),
            static fn (array $capability): bool => $capability['intent'] === $intent
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function allowedFor(Security $security): array
    {
        return array_filter(
            self::all(),
            static fn (array $capability): bool => $security->can($capability['permission'])
        );
    }

    /**
     * @return array{intent: string, permission: string, description: string, parameters: array<string, string>, required_parameters: array<int, string>, output: string}
     */
    private static function capability(
        string $intent,
        string $permission,
        string $description,
        array $parameters,
        array $requiredParameters,
        string $output
    ): array {
        return [
            'intent' => $intent,
            'permission' => $permission,
            'description' => $description,
            'parameters' => $parameters,
            'required_parameters' => $requiredParameters,
            'output' => $output,
        ];
    }
}
