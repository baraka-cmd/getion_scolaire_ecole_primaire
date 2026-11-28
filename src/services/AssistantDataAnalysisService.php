<?php

defined('ACCESS') or die('Accès interdit');

/**
 * Frontière backend pour l'accès aux données scolaires depuis l'assistant.
 *
 * Ce service ne reçoit jamais de SQL ni de nom de méthode fourni par Gemini.
 * Il valide le contrat de capacité, recontrôle la permission, borne les
 * paramètres puis délègue à l'exécuteur métier existant.
 */
final class AssistantDataAnalysisService
{
    private Security $security;
    private AssistantBusinessExecutor $executor;

    public function __construct(Security $security, AssistantBusinessExecutor $executor)
    {
        $this->security = $security;
        $this->executor = $executor;
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array{result: array<string,mixed>, message: string, permission: string, capability: string}|null
     */
    public function execute(string $intent, string $capability, array $parameters): ?array
    {
        $definition = AssistantCapabilityRegistry::all()[$capability] ?? null;
        if (!is_array($definition) || ($definition['intent'] ?? null) !== $intent) {
            return null;
        }

        $permission = (string) ($definition['permission'] ?? '');
        if ($permission === '' || !$this->security->can($permission)) {
            throw new DomainException('Permission refusée pour cette capacité métier.');
        }

        $safe = $this->validateParameters($parameters);
        $execution = $this->executor->execute($capability, $safe);
        if ($execution === null) {
            return null;
        }

        // L'exécuteur est la source des résultats : aucune valeur métier n'est
        // fabriquée ou recalculée par ce dispatcher.
        return [
            'result' => $execution['result'],
            'message' => $execution['message'],
            'permission' => $permission,
            'capability' => $capability,
        ];
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    private function validateParameters(array $parameters): array
    {
        $safe = [];
        foreach (['classe_id', 'annee_scolaire_id', 'trimestre_id'] as $idKey) {
            if (!array_key_exists($idKey, $parameters) || $parameters[$idKey] === null || $parameters[$idKey] === '') {
                continue;
            }
            $value = filter_var($parameters[$idKey], FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new InvalidArgumentException('Un identifiant de filtre est invalide.');
            }
            $safe[$idKey] = $value;
        }

        foreach (['periode_debut', 'periode_fin'] as $dateKey) {
            if (!isset($parameters[$dateKey]) || $parameters[$dateKey] === '') {
                continue;
            }
            if (!is_string($parameters[$dateKey])) {
                throw new InvalidArgumentException('Une date de période est invalide.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $parameters[$dateKey]);
            if ($date === false || $date->format('Y-m-d') !== $parameters[$dateKey]) {
                throw new InvalidArgumentException('Une date doit respecter le format AAAA-MM-JJ.');
            }
            $safe[$dateKey] = $parameters[$dateKey];
        }
        if (isset($safe['periode_debut'], $safe['periode_fin']) && $safe['periode_debut'] > $safe['periode_fin']) {
            throw new InvalidArgumentException('La période de début doit précéder la période de fin.');
        }

        foreach (['statut_paiement' => ['Payé', 'Partiel', 'Impayé'], 'statut_absence' => ['Justifiée', 'Non justifiée'], 'statut_resultat' => ['Réussi', 'Échoué']] as $key => $allowed) {
            if (!isset($parameters[$key]) || $parameters[$key] === '') {
                continue;
            }
            if (!is_string($parameters[$key]) || !in_array($parameters[$key], $allowed, true)) {
                throw new InvalidArgumentException('Un filtre de statut est invalide.');
            }
            $safe[$key] = $parameters[$key];
        }

        if (isset($parameters['limite'])) {
            $limit = filter_var($parameters['limite'], FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new InvalidArgumentException('La limite de résultats est invalide.');
            }
            $safe['limite'] = max(1, min(100, $limit));
        }

        return $safe;
    }
}
