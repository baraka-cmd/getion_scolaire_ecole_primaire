# Phase 11 — Fonctions transversales

## Contrat de non-régression
Lire `docs/MVC_REFACTORING_CONTRACT.md` et `docs/ARCHITECTURE.md`. Cette phase doit préserver les notifications, traces d'activité, documents, URLs, droits, réponses AJAX, stockage, pièces jointes et parcours existants. Aucun changement de schéma ou de règles fonctionnelles n'est inclus sans preuve et validation distincte.

## Inventaire de départ vérifié sur main
- **Notifications :** `NotificationService`/`NotificationRepository` existent déjà pour campagnes, destinataires, lecture et suppression avec contrôle d'appartenance. `NotificationController` conserve encore des requêtes SQL de listes/conversations/envoi : migration progressive à terminer, sans recréer le système ni casser les files email/push existantes.
- **Journaux d'activité :** l'écriture historique est centralisée dans la fonction globale `logActivity()` et la table `logs_activites`. `LogController` portait cependant directement les requêtes de lecture, statistiques et export.
- **Fichiers/documents :** `DocumentService` et `DocumentRepository` existent déjà ; le contrôleur assure les parcours upload, visibilité, téléchargement et suppression. La vérification locale des accès aux documents privés, chemins de stockage et limites reste requise.
- **Queues :** les workers CLI email et push sont déjà présents. Leur fonctionnement dépend de la configuration cron/serveur et ne peut pas être validé par les tests statiques seuls.

## Lot implémenté — Journaux d'activité
- Ajout de `LogRepository` pour les requêtes SQL : liste filtrée, export plafonné, statistiques, classements, activité par jour et listes de filtres.
- Ajout de `LogService` pour la pagination, les cas de lecture et la composition des données.
- `LogController` conserve les gardes d'accès, validation des filtres/dates, validation CSRF de l'export, CSV UTF-8, neutralisation des cellules CSV et structures JSON ; il délègue maintenant l'accès aux données.
- Aucun changement de table, route, permission, message, contrat de réponse ou interface.
- Test smoke ciblé : `tests/phase11_logs_mvc_smoke.php`.

## Validation / éléments à ne pas déclarer terminés sans preuve
- CI PHP syntax + smoke tests ; revue du diff.
- Tests locaux nécessaires : filtrage/export réel, rôles non autorisés, notifications ciblées, téléchargement de documents privés avec un autre compte, upload de fichiers invalides, queues email/push et audit des actions critiques.
- La phase entière reste en cours jusqu'à la migration sûre des requêtes restantes du contrôleur notifications et jusqu'à la revue/tests des chemins de fichiers et des événements d'audit. Ne pas merger sur la seule réussite des smoke tests.
