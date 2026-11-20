# Migrations

Les scripts de ce dossier sont des migrations SQL à exécuter une seule fois sur la base de données concernée, après sauvegarde.

## Durcissement de l'authentification

Exécuter `20261119_001_auth_hardening.sql` sur chaque base avant de déployer le code correspondant. Cette migration crée les compteurs de limitation d'authentification et la table des jetons de réinitialisation. La limite de connexion combine le verrouillage existant par compte et un compteur par IP; la réinitialisation applique aussi des limites par IP et par adresse email.

La réinitialisation ne transmet jamais un mot de passe par email et ne modifie pas le mot de passe lors de la demande. Elle envoie un lien à usage unique, dont le jeton est conservé sous forme hachée et expire après 30 minutes. Le traitement AJAX de gestion des utilisateurs exige un jeton CSRF pour les mutations.

Configurez `APP_CANONICAL_URL` avec l’URL publique HTTPS de l’application (par exemple `https://ecole.example/`). Cette URL sert à construire le lien de réinitialisation et n’est pas dérivée de l’en-tête HTTP `Host`. En local, `BASE_URL` n’est utilisé qu’avec une requête provenant de la boucle locale.

Les nouveaux comptes parent/enseignant reçoivent désormais un mot de passe aléatoire non communiqué. Pour invalider les anciens mots de passe partagés, sauvegardez la base, lancez d’abord `php src/cli/rotate_shared_initial_passwords.php` pour compter les comptes concernés, puis relancez avec `--apply`. Les titulaires concernés devront choisir un nouveau mot de passe via « Mot de passe oublié ».

## Documents privés

Les nouveaux documents sont stockés sous `storage/documents/`, hors du répertoire public. Avant de déployer le code correspondant, sauvegardez la base et les fichiers, exécutez `php src/cli/migrate_documents_to_private_storage.php` en mode contrôle, puis relancez avec `--apply`. Ce script met aussi à l’abri les fichiers orphelins présents dans l’ancien répertoire public; vérifiez qu’il est vide ou supprimé après migration. La protection Apache dans `storage/.htaccess` est une défense supplémentaire, pas un remplacement du stockage hors webroot.

## Notifications

Exécuter dans l’ordre `20261116_002_notification_campaigns_and_email_queue.sql`, `20261116_003_notification_origin.sql`, puis `20261116_004_push_subscriptions_and_queue.sql`, sur les bases locale et InfinityFree. Ces migrations ajoutent le suivi des campagnes, l’origine des notifications, les files d’emails et de push, et les abonnements appareils chiffrés; les notifications existantes restent intactes et leur ancienne campagne ne sera pas inventée.

Les emails demandés sont mis en file d’attente. Pour les envoyer et reprendre automatiquement les échecs temporaires, planifier une tâche cron qui exécute régulièrement :

```text
php /chemin/vers/le-projet/src/cli/process_notification_emails.php 20
```

Le nombre final est la limite d’emails traités par exécution. Le script est réservé à la ligne de commande. Si l’hébergement ne fournit pas de tâches cron, les notifications dans l’application fonctionneront, mais les emails resteront en attente jusqu’à l’exécution manuelle du script depuis un environnement CLI.

## Préparation des notifications push Web

La page personnelle des parents vérifie le contexte sécurisé et la prise en charge du navigateur, puis propose d’enregistrer le service worker et de demander explicitement l’autorisation. En production, le site doit être servi en HTTPS; `localhost` est accepté pour les essais. Sur iPhone/iPad, le site doit être ajouté à l’écran d’accueil et ouvert depuis cette icône.

Les clés VAPID et `PUSH_SUBSCRIPTION_ENCRYPTION_KEY` sont propres à chaque environnement. Créez une paire VAPID P-256 et une clé de chiffrement aléatoire de 32 octets; placez les valeurs Base64URL sans remplissage dans `.env` ou dans les variables du serveur. `PUSH_VAPID_SUBJECT` doit être une adresse `mailto:` de contact ou une URL HTTPS. Ne partagez jamais les clés privées et ne les ajoutez jamais au dépôt. L’application chiffre les abonnements en AES-256-GCM et n’enregistre pas les URL d’appareil en clair.

Après migration et configuration des clés, un parent admissible peut enregistrer ou désactiver son appareil depuis sa page de notifications. Le serveur vérifie le rôle Parent, le compte actif et l’inscription Actif/Redoublant de l’année active. Les envois manuels et automatiques créent une entrée de file pour chaque appareil enregistré des seuls destinataires admissibles. Le worker décrit ci-dessous livre ces notifications et traite les erreurs temporaires et les abonnements expirés.

Les URL d’abonnement sont limitées aux points de terminaison Web Push de Google/FCM, Mozilla, Apple et Windows afin de ne pas transformer le worker d’envoi en relais vers des hôtes arbitraires.

Le worker utilise `minishlink/web-push` (PHP 8.0+). Après installation des dépendances Composer (`composer install`), planifiez une tâche cron qui exécute régulièrement :

```text
php /chemin/vers/le-projet/src/cli/process_notification_push.php 20
```

Le worker réserve des lots, retente les erreurs temporaires avec un délai progressif et désactive les abonnements signalés expirés (HTTP 404/410). Le payload push ne révèle pas le contenu de la notification sur l’écran verrouillé : il invite le parent à ouvrir l’espace sécurisé. Le nombre final est la limite par exécution. Si l’hébergement ne fournit pas de tâches cron ou l’accès CLI, les notifications restent en attente; il faut exécuter le worker depuis un environnement qui peut accéder à la même base. Sur InfinityFree, téléversez également `vendor/` généré par `composer install` et vérifiez que PHP CLI, cURL, OpenSSL et mbstring sont disponibles.
