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


## Phase 2 — Authentification et permissions (implémentation MVC)

La phase 2 a été mise en œuvre sur la branche `refactor/mvc-phase-0-1` par changements incrémentaux, sans modifier `main` :

- `UtilisateurController` délègue les requêtes de liste, détail, historique, rôles actifs, existence email/login et les mutations CRUD à `UserAccountService`/`UserRepository`. La création conserve sa transaction dans le repository.
- `RoleController` délègue la lecture et les mutations à `RolePermissionService`/`RoleRepository`. Les anciennes méthodes publiques de `Security` restent disponibles pour les autres modules.
- `Security` conserve les décisions d'autorisation, la validation CSRF, la gestion du cache et les contrôles de session ; ses accès de persistance des rôles/permissions et la lecture de l'identité active sont délégués aux repositories.
- `AuthController` conserve l'orchestration HTTP, la validation et la gestion de session, mais les requêtes de compte, la création transactionnelle, l'historique de tentative et la persistance OAuth sont délégués à `AuthAccountService`/`AuthRepository`.
- `AuthRateLimitService` porte les règles de limitation et la validation des clés ; `AuthRateLimitRepository` porte les requêtes et la transaction atomique du compteur.
- Les pages de récupération et de réinitialisation du mot de passe gardent leur HTML et leurs messages ; le traitement a été déplacé vers `PasswordResetController`, `PasswordResetService` et `PasswordResetRepository`. Le jeton reste haché, limité dans le temps, consommé transactionnellement et le mot de passe est mis à jour avec remise à zéro des tentatives.
- La vue de connexion ne contient plus la requête SQL de paramètres école : elle appelle `SchoolParametersService` et `SchoolParametersRepository`, tout en conservant le cache de session et les valeurs de repli de présentation.

Les contrats de routes, validation, messages, paramètres du rate-limit, réponses et présentation des vues ont été conservés dans les chemins modifiés. Aucun schéma ni donnée de base n'a été modifié.

**État de clôture :** la migration d'implémentation des parcours ciblés est effectuée, mais la phase ne doit pas être déclarée techniquement validée tant que le lint PHP, les tests de sécurité/authentification et les tests de non-régression ne sont pas exécutés dans l'environnement local avec la base de test. Les outils disponibles dans cette session n'ont pas permis de cloner le dépôt dans l'environnement d'exécution PHP ; aucun résultat de test ne doit donc être inventé.


## Phase 2 — Journal de progression (27 novembre 2026)

Derniers ajouts sur la branche dédiée : repositories et services pour le rate-limit, les paramètres école de la page login et la réinitialisation du mot de passe ; contrôleurs des pages oubli/réinitialisation désormais sans SQL ; vues login/forgot/reset sans SQL direct ; transactions de création utilisateur/Google et remplacement des permissions encapsulées dans les repositories.

À la reprise, lire ce suivi et `docs/MVC_REFACTORING_CONTRACT.md`, récupérer la branche, exécuter `php -l` sur les fichiers modifiés et les tests `tests/auth_security_smoke.php`, `tests/google_oauth_config_smoke.php`, `tests/users_listing_smoke.php` et `tests/finance_auth_users_service_smoke.php` avec une base de test appropriée. Comparer ensuite les réponses et les flux de login, OAuth, reset, utilisateurs et rôles. Ne clôturer la validation qu'après ces résultats réels.


## Phase 3 — Référentiels scolaires (mise en œuvre progressive)

Sur la même branche de refonte, les premiers déplacements ont été réalisés sans changement de schéma ni de contrat HTTP :

- **Années scolaires / périodes :** les requêtes de vérification d'unicité et de chevauchement, lectures verrouillées, recherche de configuration active, comptages des dépendances et suppressions simples sont accessibles via `AnneeScolaireService` et `AnneeScolaireRepository`. La validation des dates, messages, codes HTTP et transactions existantes du contrôleur restent inchangés.
- **Classes :** la requête de liste paginée, le filtre d'année active, le total, le titulaire, les effectifs, le tri et la pagination sont déplacés vers `ClasseRepository` via `ClasseService`. Les colonnes de tri sont validées côté repository avec une liste blanche. Les vérifications d'unicité du nom/salle, les comptages d'élèves/évaluations et la lecture de l'affectation du titulaire sont également délégués.
- **Matières :** la liste hiérarchique groupes → sous-matières → classes associées, avec pagination et recherche, est déplacée vers `MatiereRepository` via `MatiereService`. Les contrôles d'unicité, de groupe parent, de dépendances (sous-matières, évaluations, emploi du temps), l'association conditionnelle à une classe et la mise à jour d'une matière passent également par le service/repository.
- **Paramètres école :** les lectures, la validation de la paire année/trimestre, les listes d'années/périodes, le logo et la sauvegarde transactionnelle des paramètres sont délégués à `SchoolSettingsService`/`SchoolSettingsRepository`. Les valeurs de barème existantes sont conservées lors de la sauvegarde, comme auparavant.
- Les vues de liste initiale des années, classes, matières et la vue des paramètres école ne contiennent plus de SQL direct pour les données présentées ; elles utilisent les services correspondants.
- Un smoke test sans base a été ajouté pour vérifier les contrats de délégation des nouveaux services.

**Phase 3 reste en cours :** les formulaires de création/modification des années et classes contiennent encore des traitements SQL historiques dans les vues ; les mutations transactionnelles de ces référentiels et les formulaires d'ajout/modification/suppression de matières/classes restent à migrer progressivement. Les routes, traitements de formulaire et réponses devront être tracés avant extraction. Les tests doivent être exécutés localement avant clôture ; aucune validation PHP n'est revendiquée dans cette session.


## Phase 4 — Élèves et dossiers (progression)

Branche dédiée : `refactor/phase-4-students-records`.

- Les requêtes SQL de pagination/liste et de lecture du dossier élève (identité, classe, enseignant titulaire, notes récentes, absences et paiements) sont déplacées dans `StudentRecordRepository`; `StudentRecordService` expose les cas de lecture.
- `EleveController` conserve les contrôles HTTP, la permission générale, le contrôle d'accès individuel, les messages et le format JSON, mais délègue ces deux parcours.
- Les requêtes SQL de la policy d'accès au dossier sont déplacées vers `StudentAccessRepository`. La policy reste la décision d'autorisation et garde les rôles Admin/Directeur, Parent lié, Enseignant affecté et le fallback de permission.
- Le test `tests/student_records_mvc_smoke.php` vérifie les contrats de délégation avec des repositories simulés, sans base de données.

**Phase 4 non clôturée / non fusionnée :** restent à auditer et migrer les écritures et formulaires élèves (création transactionnelle avec inscription et parents, modification, désactivation, changement de classe), les liens/gestion des parents et les routes et vues de documents (liste, téléchargement, accès). Vérifier également import/export, vues enseignants/parents et tous les accès IDOR. Aucun lint/test PHP n'a été exécuté dans cette session ; le smoke test ajouté n'est pas déclaré réussi. La phase sera mergeable après ces éléments, comparaison des contrats et vérifications locales.
