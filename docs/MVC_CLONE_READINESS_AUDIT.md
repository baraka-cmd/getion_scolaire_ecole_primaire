# Audit de préparation au clone — responsabilités MVC

Date : 27 novembre 2026  
Branche de référence examinée : `main`  
Commit : `9fb4626f3a4da2c3daabfdb01251491379864295`

## Portée et méthode

Audit de la documentation d'architecture et du contrat MVC, des notes de validation de phases, puis inspection ciblée de fichiers réellement présents sur `main`. Cet audit porte sur la séparation des responsabilités dans le code, pas sur les textes, l'apparence ou l'exécution locale que le propriétaire vérifiera après clonage.

Références obligatoires :
- `docs/ARCHITECTURE.md`
- `docs/MVC_REFACTORING_CONTRACT.md`
- `docs/PHASE12_DASHBOARDS_PROGRESS.md`
- `docs/PHASE13_DEPENDENCY_CLEANUP_PROGRESS.md`
- `docs/PHASE14_FINAL_VALIDATION.md`

## Contrôles ciblés observés

### Correctement orienté MVC dans les lots déjà migrés
- `DashboardController` délègue les données de ses indicateurs administratifs à `DashboardService`; il adapte les réponses AJAX et applique l'autorisation serveur.
- `ClasseController` et `MatiereController` ont des services dédiés et des repositories, et les lots fusionnés ont retiré leurs accès directs à la base pour le périmètre couvert.
- `StudentAccessPolicy` exprime la décision d'accès et délègue les lectures de rôles/liaisons à `StudentAccessRepository`. La policy n'écrit pas de SQL.
- `ClasseRepository` centralise effectivement les requêtes et protège les identifiants de tri par liste blanche.

### Écarts confirmés qui empêchent de certifier « MVC partout »
1. **Contrôleurs encore liés à la persistance** : des appels `$this->db->...` et des chaînes SQL sont encore présents dans `AnneeController`, `EmploiController`, `ParentController`, `EffectifController`, `EnseignantController`, `EleveController`, `SauvegardeController` et `StatistiqueController`. Ce sont des responsabilités Repository à extraire par domaine, sans déplacer les transactions hors de leur frontière atomique.
2. **SQL dans des contrôleurs de résultats via une façade de service** : `BulletinController` et `ResultatController` construisent encore des chaînes SQL puis appellent `ResultsBulletinService::fetchAll/fetchOne/fetchColumn/insert/update/delete`. Le service transmet ces commandes au repository générique. Le SQL est techniquement exécuté dans le repository, mais le contrôleur reste responsable de la formulation des requêtes : cette façade n'est pas une séparation MVC complète. Il faut remplacer ces appels par des méthodes nommées de cas d'usage/repository et préserver les transactions et résultats.
3. **SQL directement dans les vues de dashboard** : `public/views/dashboard/enseignant.php`, `parent.php`, `comptable.php` et `secretaire.php` accèdent directement à `$db`. Les vues combinent présentation et préparation de données, en particulier le dashboard comptable où des agrégations financières doivent rester strictement inchangées. La phase 12 documente explicitement ce reste-à-faire.
4. **Couverture des policies** : une policy vérifiée (`StudentAccessPolicy`) suit le bon principe, mais le dépôt ne dispose pas encore d'une preuve exhaustive que chaque route sensible utilise une policy ou une permission équivalente, et que les repositories n'appellent jamais de contrôleurs.

## Décision de préparation

`main` est un snapshot clonable pour poursuivre les travaux, mais **ne peut pas encore être certifié conforme à l'objectif « tous les fichiers assument leur responsabilité MVC »**. Les phases 12 et 13 restent en migration partielle selon leurs propres documents. Les merges précédents valident des lots, pas l'intégralité des modules.

## Ordre de correction recommandé après cet audit

1. Phase 12 : extraire les quatre dashboards par rôle dans des services/repositories dédiés, puis remplacer les vues par des variables de présentation préparées.
2. Phase 13 : reprendre les contrôleurs restants, un domaine à la fois ; commencer par référentiels et rapports. Conserver contrôles d'accès, paramètres SQL, transactions, contrats AJAX/JSON et messages.
3. Résultats/bulletins : supprimer l'API SQL générique du service pour les usages contrôleur et la remplacer par des méthodes métier nommées.
4. Ajouter un contrôle architectural CI qui interdit les nouvelles requêtes DB dans controllers/views et les appels contrôleur→contrôleur, tout en faisant migrer les exceptions historiques explicitement.
5. Pour chaque lot : tests de contrat, lint et CI ; les tests XAMPP/base de données restent à exécuter par le propriétaire après récupération.

## Limites de cette vérification

Cette session n'a pas exécuté PHP ni XAMPP et n'a pas comparé le comportement avec une base locale. Aucun résultat de runtime, d'interface ou de non-régression métier n'est revendiqué. Ce document est un audit ciblé et traçable, pas une certification de chaque fichier du dépôt.
