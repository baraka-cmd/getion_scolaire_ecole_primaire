<?php
/**
 * Catalogue des domaines consultables par l'assistant.
 *
 * L'IA choisit un domaine et des paramètres ; elle ne génère jamais de SQL.
 */

defined('ACCESS') or die('Accès interdit');

final class AssistantIntentCatalog
{
    /**
     * @return array<string, array{permission: string, description: string, parameters: array<string, string>, capabilities: array<string, array<string, mixed>>}>
     */
    public static function all(): array
    {
        $intents = [
            'eleves' => [
                'permission' => 'eleves.consulter',
                'description' => 'Consulter les élèves, leurs inscriptions et leurs informations scolaires autorisées.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée, par exemple 2025-2026 ou 2026',
                    'classe' => 'nom ou niveau de classe demandé',
                    'statut' => 'statut scolaire demandé si présent',
                    'limite' => 'nombre maximal de résultats, de 1 à 100',
                ],
            ],
            'classes' => [
                'permission' => 'classes.consulter',
                'description' => 'Rechercher des classes, leurs effectifs et leurs informations scolaires.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                    'classe' => 'nom ou niveau de classe demandé',
                    'tri' => 'ordre asc ou desc si demandé',
                ],
            ],
            'matieres' => [
                'permission' => 'matieres.consulter',
                'description' => 'Consulter les matières actives et les matières associées à une classe.',
                'parameters' => [
                    'classe' => 'nom ou niveau de classe demandé',
                    'annee_scolaire' => 'libellé ou année demandée',
                    'limite' => 'nombre maximal de matières',
                ],
            ],
            'parents' => [
                'permission' => 'parents.consulter',
                'description' => 'Consulter les parents ou tuteurs liés aux élèves autorisés.',
                'parameters' => [
                    'classe' => 'nom ou niveau de classe demandé',
                    'annee_scolaire' => 'libellé ou année demandée',
                    'limite' => 'nombre maximal de parents',
                ],
            ],
            'paiements' => [
                'permission' => 'paiements.consulter',
                'description' => 'Consulter les paiements, frais, soldes restants et situations d’impayés.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                    'classe' => 'nom ou niveau de classe demandé',
                    'statut_paiement' => 'Payé, Partiel ou Impayé si précisé',
                    'limite' => 'nombre maximal d’élèves',
                ],
            ],
            'absences' => [
                'permission' => 'absences.consulter',
                'description' => 'Consulter les absences et la présence des élèves autorisés.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                    'classe' => 'nom ou niveau de classe demandé',
                    'periode' => 'période ou dates demandées',
                    'statut_absence' => 'justifiée ou non justifiée',
                    'limite' => 'nombre maximal de résultats',
                ],
            ],
            'resultats' => [
                'permission' => 'resultats.consulter',
                'description' => 'Consulter les résultats, réussites, échecs, proclamations et bulletins disponibles.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                    'classe' => 'nom ou niveau de classe demandé',
                    'periode' => 'trimestre, période ou session demandée',
                    'statut_resultat' => 'réussi, échoué ou autre statut demandé',
                    'limite' => 'nombre maximal de résultats',
                    'tri' => 'ordre asc ou desc si demandé',
                ],
            ],
            'rapports' => [
                'permission' => 'rapports.consulter',
                'description' => 'Consulter les statistiques et indicateurs globaux autorisés de l’école.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                    'indicateur' => 'effectif, taux, moyenne ou indicateur demandé',
                ],
            ],
            'annees_scolaires' => [
                'permission' => 'parametres.consulter',
                'description' => 'Rechercher les années scolaires et leur état.',
                'parameters' => [
                    'annee_scolaire' => 'libellé ou année demandée',
                ],
            ],
            'inconnu' => [
                'permission' => '',
                'description' => 'Demande hors périmètre des données scolaires autorisées ou demande trop ambiguë.',
                'parameters' => [],
            ],
        ];

        foreach ($intents as $intent => &$definition) {
            $definition['capabilities'] = class_exists('AssistantCapabilityRegistry')
                ? AssistantCapabilityRegistry::forIntent($intent)
                : [];
        }
        unset($definition);

        return $intents;
    }

    /**
     * @return array<string, array{permission: string, description: string, parameters: array<string, string>, capabilities: array<string, array<string, mixed>>}>
     */
    public static function allowedFor(Security $security): array
    {
        return array_filter(
            self::all(),
            static fn (array $intent): bool => $intent['permission'] === '' || $security->can($intent['permission'])
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function allowedCapabilitiesFor(Security $security): array
    {
        return class_exists('AssistantCapabilityRegistry')
            ? AssistantCapabilityRegistry::allowedFor($security)
            : [];
    }
}
