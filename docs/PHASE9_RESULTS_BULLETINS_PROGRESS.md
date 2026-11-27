# Phase 9 — Résultats et bulletins (suivi de mise en œuvre)

## Contrat obligatoire
Avant toute reprise, lire :
- `docs/MVC_REFACTORING_CONTRACT.md`
- `docs/ARCHITECTURE.md`

Cette phase est une migration d'architecture exclusivement. Les calculs, formules, arrondis, règles de délibération/proclamation, résultats stockés, structure des bulletins, routes, permissions, messages, réponses AJAX/JSON, vues et impressions doivent rester inchangés. Aucun changement de schéma ou de règle scolaire n'est autorisé.

## Branche et dépendance
- Branche : `refactor/phase-9-results-bulletins`
- Branche parente de travail : `refactor/phase-8-evaluations-notes`
- Phase 8 / PR #6 : ouverte et non fusionnée au démarrage de cette phase.
- Cette phase est donc empilée sur la phase 8 ; elle ne doit pas être fusionnée directement dans `main` avant la résolution de la dépendance.

## Périmètre
- Résultats individuels et par classe : lectures, calculs orchestrés, rangs, mentions, décisions.
- Délibérations et procès-verbaux : accès, enregistrement et proclamation.
- Bulletins : génération, consultation, statistiques, contexte scolaire et préparation à l'impression.
- Contrôler l'accès à chaque élève/classe/action, y compris les identifiants envoyés par AJAX, sans élargir ni réduire involontairement les rôles autorisés.
- Extraire progressivement le SQL vers repositories et l'orchestration métier vers services ; garder les contrôleurs responsables du HTTP et des contrats existants.

## Premier lot réalisé
- Création de `ResultsBulletinRepository` et `ResultsBulletinService`.
- Déplacement sans réécriture des requêtes historiques de contexte élève pour les résultats et bulletins, année scolaire active et métadonnées école/année.
- Les contrôleurs concernés délèguent ces lectures ; les clés/alias SQL et le calcul des résultats restent inchangés.
- Test smoke sans base : `tests/results_bulletin_service_smoke.php`.

## Reste à traiter — phase non clôturée
- Extraire les autres requêtes de `ResultatController` et `BulletinController`, y compris calculs par classe, rangs, PV, délibérations, sauvegardes, listes et statistiques.
- Préserver transaction et ordre des opérations pour les écritures.
- Auditer routes, vues, scripts JS, permissions, CSRF, politiques d'accès aux ressources et téléchargements/impressions.
- Ajouter des tests de non-régression des calculs et des accès autorisés/refusés.
- Lint PHP, exécution des tests automatisés et vérification locale avec base de test avant clôture.

## État de validation
Aucun test PHP n'est déclaré exécuté depuis cette session. Les smoke tests ajoutés doivent être lancés dans l'environnement PHP du projet. La phase ne sera mergeable qu'après revue complète du diff, CI appropriée et validation locale, puis fusion de la phase 8 selon l'ordre des dépendances.
