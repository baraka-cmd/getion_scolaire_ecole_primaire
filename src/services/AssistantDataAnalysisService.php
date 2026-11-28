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
    private EvaluationNoteService $evaluationAccess;

    public function __construct(Security $security, AssistantBusinessExecutor $executor)
    {
        $this->security = $security;
        $this->executor = $executor;
        require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'EvaluationNoteService.php';
        $this->evaluationAccess = new EvaluationNoteService();
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
        $this->enforceObjectScope($capability, $safe);
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

    /**
     * Restriction fail-closed tant que toutes les requêtes des repositories
     * n'ont pas un scope parent/enseignant démontré par policy.
     */
    private function enforceObjectScope(string $capability, array $parameters): void
    {
        if (hasRole('Admin') || hasRole('Directeur')) {
            return;
        }
        if (hasRole('Enseignant')) {
            $classId = (int) ($parameters['classe_id'] ?? 0);
            $yearId = (int) ($parameters['annee_scolaire_id'] ?? 0);
            if ($classId <= 0 || $yearId <= 0
                || !$this->evaluationAccess->teacherHasClassAssignment(
                    (int) ($_SESSION['utilisateur_id'] ?? 0),
                    $classId,
                    $yearId
                )) {
                throw new DomainException('Pour protéger les dossiers scolaires, cette requête doit viser une classe et une année où vous êtes officiellement affecté.');
            }
            return;
        }
        if (hasRole('Parent')) {
            throw new DomainException('L’assistant ne peut pas encore appliquer le périmètre individuel parent-enfant à cette capacité. Consultez le dossier de votre enfant depuis son espace sécurisé.');
        }
        // Les autres rôles restent soumis à la permission de capacité. Les accès
        // nominatifs devront être couverts par des policies propres à chaque outil.
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
