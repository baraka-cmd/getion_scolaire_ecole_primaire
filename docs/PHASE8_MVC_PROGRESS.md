# Phase 8 — Évaluations et notes (suivi de reprise)

Date de suivi : 27 novembre 2026

## Contrat
Lire avant toute reprise :
- `docs/MVC_REFACTORING_CONTRACT.md`
- `docs/ARCHITECTURE.md`

La phase est une migration MVC sans changement des règles scolaires, calculs, routes, contrats HTTP/JSON, interfaces ou schéma. Contrôler les accès côté serveur et les permissions par ressource.

## Branche et PR
- Branche : `refactor/phase-8-evaluations-notes`
- PR #6 : https://github.com/baraka-cmd/getion_scolaire_ecole_primaire/pull/6
- Base : `main`
- État : ouverte, non fusionnée, travail incomplet.

## Lots présents
1. `EvaluationNoteRepository` et `EvaluationNoteService` : premières extractions pour le contexte d'évaluation, les élèves actifs, maxima, consultation des notes et sauvegarde d'une cellule.
2. `ResultatController` et `BulletinController` : ajout d'une vérification d'accès à l'élève via `StudentAccessPolicy` dans les parcours ciblés de consultation/génération. Contrôle CI associé réussi (run 46034424324).

## Périmètre restant — ne pas déclarer la phase complète
- `NoteController` : extraire les opérations restantes d'évaluations (liste, détail, création, modification, suppression, génération), grille de saisie, progression et listes de référence.
- Les fonctionnalités de résultats, rangs, délibérations, procès-verbaux et bulletins sont désormais attribuées à la **phase 9** (`Résultats et bulletins`), conformément au découpage fonctionnel retenu. Elles ne doivent pas être dupliquées dans la phase 8.
- `NoteController` : terminer l'extraction des opérations CRUD d'évaluations, grille de saisie, progression et listes de référence.
- Auditer les vues et scripts des modules notes/résultats/bulletins : SQL direct, instanciation de contrôleurs, contrats AJAX, permissions et CSRF.
- Ajouter des tests ciblés de délégation, autorisations, non-régression des calculs et transactions.

## Validation exigée avant fusion
- Revue complète du diff et de chaque chemin utilisateur.
- Lint PHP et tests automatisés disponibles sur la branche.
- Test local avec base de test : saisie/édition/absence, limites maxima, élève non autorisé, progression, résultats/délibération et bulletin imprimé.
- Vérifier que les migrations/schema n'ont pas été modifiés.
- Fusionner dans `main` seulement lorsque tout le périmètre est terminé et les vérifications sont vertes. Ne pas prétendre que les tests locaux ont été exécutés par GitHub.


## 27 novembre 2026 — reprise complémentaire

- Les requêtes de liste filtrée et de détail d'évaluation ont été déplacées de `NoteController` vers `EvaluationNoteRepository`, avec délégation par `EvaluationNoteService`.
- Les filtres restent paramétrés ; le type d'évaluation est limité à la liste historique autorisée. Le JSON de sortie du contrôleur est conservé.
- Le test smoke couvre maintenant la délégation de ces deux lectures.
- Vérification GitHub Actions observée : le run déclenché par le commit de délégation du service est terminé avec succès. Le run déclenché par le dernier commit contrôleur était encore en cours lors du contrôle ; aucune conclusion n'est anticipée.

La phase demeure ouverte : les mutations CRUD, la génération, la grille et la progression doivent encore être migrées et auditées. Le contrôle CI d'un lot ne valide pas à lui seul la phase complète ni la compatibilité avec la base et le navigateur.
