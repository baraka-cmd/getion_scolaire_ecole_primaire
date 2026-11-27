# Architecture MVC du projet

## Responsabilites

- `public/` contient les points d'entree HTTP et les vues.
- `src/controllers/` valide les requetes HTTP, applique les permissions, garde les transactions HTTP/CSRF et adapte les reponses JSON.
- `src/services/` contient les regles metier reutilisables et les calculs.
- `src/repositories/` contient les requetes SQL et les operations de persistance.
- `src/policies/` centralise les decisions d'autorisation transversales.
- `src/views/` ou `public/views/` contient le rendu HTML. Une vue ne doit pas executer de SQL directement.

## Dependances autorisees

Vue -> Controleur -> Service -> Repository -> Database.

Les vues peuvent instancier des services/politiques pour recuperer des donnees de presentation lorsque aucun controleur d'etat HTTP ne gere l'action. Elles ne doivent pas instancier un autre controleur pour reutiliser une regle metier.

Les services peuvent utiliser plusieurs repositories et partager des politiques. Les repositories ne doivent pas appliquer de logique metier ni appeler des controleurs.

Les transactions qui garantissent plusieurs operations atomiques restent dans le controleur ou dans le service qui possede clairement cette transaction. Ne pas eclater une transaction pour contourner une limite MVC.

## Ajout d'une fonctionnalite

1. Identifier la capacite metier et ses permissions.
2. Ajouter ou modifier le repository pour la persistance.
3. Ajouter les regles de validation/calcul dans un service.
4. Ajouter un point d'entree dans le controleur sans dupliquer les regles du service.
5. Ajouter ou modifier la vue sans SQL ni logique persistante.
6. Ajouter un smoke test en memoire ou un test PHP ciblé.
7. Executer les tests, `php -l` sur les fichiers modifies et `git diff --check`.

## Hygienes obligatoires

- SQL parametre uniquement; jamais de concatenation de valeurs utilisateur.
- Aucun appel direct entre controleurs pour reutiliser une regle metier.
- Une policy est reutilisee lorsqu'elle decide d'un droit d'acces transversal.
- Toute suppression d'un ancien chemin exige une recherche d'appels dans `src/`, `public/` et `tests/`.
- Les anciens chemins sont conserves seulement lorsqu'un remplacement progressif ou une compatibilite externe exige leur maintien.

## Phase 6 — Etat du nettoyage

Les anciens cheminements ne sont pas tous supprimables en une seule passe. Les vues historiques contiennent encore de nombreuses requetes SQL directes. Elles sont identifieses comme migration progressive; aucun ancien contrat n'est retire tant qu'il reste reference dans une vue, une route ou un test.

Les appels directs entre controleurs ont ete elimines pour l'acces aux eleves. Le module d'inscription utilise maintenant `AcademicResultsService` pour les resultats annuels.


## Contrat de non-régression obligatoire

Avant chaque phase de refonte, lire `docs/MVC_REFACTORING_CONTRACT.md`. La refonte vise uniquement l'organisation du code. Les fonctionnalités, règles métier et calculs, validations, permissions, routes, contrats HTML/AJAX/JSON, comportements JavaScript et design doivent rester inchangés. La sécurité est prioritaire et les modifications doivent être minimales, vérifiables et effectuées sur une branche dédiée.

## Phases 0 et 1 — reprise de mise en œuvre

- Phase 0 : audit en lecture seule déjà effectué dans le travail précédent ; conserver sa cartographie comme référence et ne pas recommencer inutilement.
- Phase 1 : infrastructure en migration progressive. La page 404 auparavant intégrée au routeur a été extraite dans `public/views/errors/404.php`; le routeur conserve la définition du statut HTTP 404 et délègue le rendu à cette vue. Le HTML/CSS et les liens de présentation ont été conservés tels quels.
- Les autres parties du bootstrap, routage, réponses et helpers doivent être migrées par petits changements après traçage de leurs usages.


## Phase 2 — Authentification et permissions (mise en œuvre en cours)

Sur la branche `refactor/mvc-phase-0-1`, les lectures de liste/détail/historique utilisateurs ont été extraites de `UtilisateurController` vers `UserRepository` et `UserAccountService`. Les lectures de rôles et permissions de `RoleController` sont déléguées à `RolePermissionService` et `RoleRepository`. La mise à jour des permissions d'un rôle est désormais transactionnelle et rafraîchit le cache de permissions de l'utilisateur courant lorsque son propre rôle est modifié.

La phase reste ouverte : SQL et transactions CRUD restants, séparation progressive de `Security`, parcours login/register/logout/OAuth/session/réinitialisation, traces routes-vues-JS, tests de non-régression et vérifications PHP doivent être traités avant clôture. Lire `docs/MVC_REFACTORING_CONTRACT.md` et ce suivi avant de poursuivre.
