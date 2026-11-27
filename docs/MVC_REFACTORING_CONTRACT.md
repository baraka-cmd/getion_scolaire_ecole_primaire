# Contrat permanent de refonte MVC — Gestion scolaire

> **À lire obligatoirement avant toute intervention MVC, dans chaque nouvelle conversation ou phase.**
> Ce document complète `docs/ARCHITECTURE.md` et prévaut sur toute proposition de refonte qui risquerait de changer le comportement observable.

## Objectif non négociable

L'application existe et doit continuer à fonctionner comme elle fonctionne aujourd'hui. Le travail consiste à **réorganiser et améliorer le code existant pour une architecture MVC professionnelle**, pas à refaire l'application ni à concevoir de nouvelles fonctionnalités.

La refonte doit conserver à l'identique, sauf demande expresse et distincte du propriétaire :

- toutes les fonctionnalités et tous les parcours utilisateurs ;
- la logique métier, les règles de calcul et les résultats produits ;
- les validations, contraintes, messages et règles de permissions ;
- les routes, méthodes HTTP, paramètres, redirections et compatibilités d'anciennes URL ;
- les contrats de réponses HTML, AJAX, JSON, leurs codes HTTP et leurs structures ;
- le comportement JavaScript, les formulaires, les interactions et les téléchargements ;
- le design, les textes, les styles, les composants et l'apparence de l'interface ;
- les comportements de session, CSRF, OAuth, fichiers, PWA, cache et erreurs.

**Un changement d'architecture ne constitue pas une autorisation de changer le comportement.** En cas de doute, conserver l'ancien comportement et investiguer avant d'écrire.

## Principes de sécurité avant tout

- Contrôles d'accès et permissions exécutés côté serveur, jamais confiés au navigateur.
- Prévention IDOR/BOLA : vérifier l'autorisation sur chaque ressource et action, pas seulement masquer un lien.
- Validation des entrées et protection CSRF en préservant les règles déjà en place.
- SQL paramétré ; toute partie dynamique SQL doit être strictement contrôlée par une liste blanche.
- Ne pas exposer secrets, erreurs SQL détaillées ou fichiers internes.
- Préserver l'intégrité transactionnelle, l'idempotence et les contrôles existants.
- Ne pas ajouter de mécanisme de sécurité incompatible avec les routes ou appels actuels sans analyser son impact.
- Les problèmes de sécurité découverts sont documentés et corrigés avec une portée minimale ; aucun changement métier opportuniste.

## Architecture cible et dépendances

- **Front controller / infrastructure** : bootstrap HTTP, normalisation de requête, dispatch, réponses et erreurs.
- **Controller** : orchestration HTTP, authentification/autorisation, validation d'entrée, appel aux services, adaptation de réponse.
- **Service** : règles métier, calculs, orchestration de cas d'usage.
- **Repository** : requêtes SQL et persistance, sans règle métier ni appel à un contrôleur.
- **Policy** : décision d'accès réutilisable et transversale.
- **View** : présentation ; pas de SQL, mutation métier ni contrôleur instancié pour réutiliser une règle.

Dépendance attendue : View/HTTP → Controller → Service → Repository → Database. Les exceptions historiques ne sont retirées qu'après traçage exhaustif de leurs usages.

## Méthode de changement

1. Lire ce contrat et `docs/ARCHITECTURE.md` avant la phase.
2. Vérifier l'état actuel de la branche et les fichiers réels ; ne pas se fier uniquement à un résumé de conversation.
3. Cartographier appels, routes, formulaires, JS, vues, permissions et contrats concernés.
4. Définir un changement petit, isolé et réversible.
5. Ne modifier que le périmètre nécessaire ; aucun renommage/déplacement massif ou suppression de compatibilité en bloc.
6. Après changement : examiner le diff, vérifier la syntaxe PHP, les appels et la sécurité ; exécuter les tests disponibles.
7. Indiquer honnêtement les contrôles effectués et ceux impossibles à exécuter. Ne jamais déclarer un test réussi sans l'avoir lancé.

## Phases et état de reprise

| Phase | Objet | État |
|---|---|---|
| 0 | Audit complet en lecture seule : comprendre le dépôt et cartographier les fonctionnalités | Audit préliminaire déjà réalisé dans le chat antérieur ; utiliser ses constats, vérifier uniquement les points nécessaires à l'écriture |
| 1 | Infrastructure et démarrage : bootstrap, routage, réponses, helpers | Analyse préalable réalisée ; mise en œuvre MVC progressive en cours sur branche dédiée |
| 2 | Authentification et permissions : connexion, sessions, rôles, utilisateurs | Analyse préalable commencée ; à reprendre après stabilisation des phases 0–1 |

## Interdictions explicites

- Ne pas refaire l'application.
- Ne pas modifier fonctionnalités, logique métier, calculs, validations, permissions, routes, réponses, design ou comportement UI.
- Ne pas changer le schéma ou les données de base pour une simple réorganisation MVC.
- Ne pas supprimer les chemins historiques sans preuve d'absence d'appels et stratégie de compatibilité.
- Ne pas travailler directement sur `main` ; utiliser une branche de refonte et livrer un diff vérifiable.
- Ne pas annoncer « terminé » sans preuve.

## Instruction à tout nouveau chat

Commencer par lire ce fichier et `docs/ARCHITECTURE.md`, examiner la branche et les changements existants, puis continuer depuis l'état indiqué. Ne redémarrer aucun audit déjà effectué et ne répéter aucune modification déjà présente.
