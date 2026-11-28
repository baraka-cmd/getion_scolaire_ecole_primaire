# Phase B — Orchestrateur conversationnel : fondation B.1–B.3

## Ce changement

Cette première livraison de Phase B pose un contrat versionné et un planificateur backend borné. Le contrôleur attache un objet `orchestration` à la classification déjà normalisée.

## Parcours actuel préservé

1. Le fournisseur IA existant classe la demande.
2. `AssistantExecutionContract` valide et normalise intention, capacité et paramètres.
3. `AssistantOrchestrator` vérifie la capacité contre le catalogue autorisé et le registre métier.
4. Il construit un plan explicite d'une étape de lecture, dont les paramètres proviennent exclusivement de la classification backend validée.
5. L'exécution existante continue dans le contrôleur et les services métier ; ce plan n'exécute rien lui-même.

## Sécurité

- Aucun SQL, accès DB ou exécution d'action n'est fourni par Gemini ou le plan.
- Une capacité doit être présente dans l'intention autorisée et dans `AssistantCapabilityRegistry`.
- Le plan indique que le contrôle des permissions reste obligatoire.
- Le plan est limité à une étape à ce stade ; aucune boucle d'agent ou exécution autonome n'est introduite.

## Étapes restant à livrer

B.3 multi-étapes réelles, B.4 traitement différencié simple/complexe, B.5 détection sémantique fiable des paramètres, B.6 reprise des clarifications dans l'historique, B.7 appels Gemini successifs avec tool-calling structuré, B.8 reprise et classification des erreurs sont encore à construire.

## Validation

Le changement a été écrit sur la branche `feature/phase-b-conversational-orchestrator`, séparée de `main`. Les tests PHP/HTTP et la validation complète de la régression doivent être exécutés dans l'environnement du projet avant merge. Cette livraison n'est pas déclarée prête à fusionner sur la seule base de l'écriture GitHub.
