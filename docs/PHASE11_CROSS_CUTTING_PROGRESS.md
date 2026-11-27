# Phase 11 — Fonctions transversales

## Contrat de non-régression
Lire `docs/MVC_REFACTORING_CONTRACT.md` et `docs/ARCHITECTURE.md`. Cette phase doit préserver les notifications, traces d'activité, documents, URLs, droits, réponses AJAX, stockage, pièces jointes et parcours existants. Aucun changement de schéma ou de règles fonctionnelles n'est inclus sans preuve et validation distincte.

## Inventaire de départ vérifié sur main
- **Notifications :** `NotificationService`/`NotificationRepository` existants assurent déjà campagnes, destinataires, lecture et suppression avec contrôle d'appartenance. Les queues email/push et l'envoi transactionnel restent en place ; aucune duplication ni modification de leur contrat n'a été introduite dans ce lot. Certaines requêtes SQL de lecture/envoi restent dans le contrôleur et constituent une extraction MVC ultérieure, non un défaut fonctionnel découvert par ce lot.
- **Journaux d'activité :** l'écriture historique est centralisée dans la fonction globale `logActivity()` et la table `logs_activites`. `LogController` portait cependant directement les requêtes de lecture, statistiques et export.
- **Fichiers/documents :** `DocumentService`/`DocumentRepository` préexistants ; stockage privé sous `storage/documents` hors webroot. Upload renforcé (fichier HTTP réel, taille relue côté serveur, limite de 20 fichiers, nom généré opaque), contrôleur débarrassé de sa dépendance DB inutile ; suppression DB avant nettoyage physique pour éviter une référence cassée. Le téléchargement vérifie visibilité/rôle, chemin confiné, cache privé et `nosniff`.
- **Queues :** les workers CLI email et push sont déjà présents. Leur fonctionnement dépend de la configuration cron/serveur et ne peut pas être validé par les tests statiques seuls.

## Lot implémenté — Journaux d'activité
- Ajout de `LogRepository` pour les requêtes SQL : liste filtrée, export plafonné, statistiques, classements, activité par jour et listes de filtres.
- Ajout de `LogService` pour la pagination, les cas de lecture et la composition des données.
- `LogController` conserve les gardes d'accès, validation des filtres/dates, validation CSRF de l'export, CSV UTF-8, neutralisation des cellules CSV et structures JSON ; il délègue maintenant l'accès aux données.
- Aucun changement de table, route, permission, message, contrat de réponse ou interface.
- Test smoke ciblé : `tests/phase11_logs_mvc_smoke.php`.

## Nettoyage du dépôt
- Retrait du fichier image de profil uploadé et versionné par erreur (`public/uploads/utilisateurs/...jpg`) du contenu courant de la branche.
- Ajout d'une règle `.gitignore` pour les fichiers runtime sous `public/uploads/`, avec un `.gitkeep` pour conserver le répertoire.
- Le commit de retrait n'efface pas l'image des commits historiques déjà accessibles. Une purge d'historique Git est une opération séparée, à décider explicitement, car elle réécrit les références.

## Validation
- CI PHP syntax + smoke tests : à contrôler sur le SHA final du PR.
- Tests XAMPP requis après récupération : filtrage/export logs, autorisations de notification et documents, upload de fichiers non conformes, accès privé par un autre compte, suppression de document, workers email/push.
- Les requêtes SQL restantes dans `NotificationController` sont une dette MVC connue ; elles n'ont pas été réécrites dans ce lot car l'envoi campagne/queues est transactionnel et doit être extrait avec tests dédiés. Ce PR est un lot de sécurisation et d'extraction ciblé, pas une affirmation que toutes les fonctions transversales ont été réécrites intégralement.
