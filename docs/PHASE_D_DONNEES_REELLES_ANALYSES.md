# Phase D — Connexion aux données réelles et analyses

## Objectif officiel

L'assistant doit répondre à partir des données persistées de l'établissement. Les valeurs scolaires et financières sont récupérées et calculées par PHP/SQL, jamais inventées ou calculées comme des faits par Gemini.

Cette phase s'appuie sur les contrats et capacités des phases B et C. Elle ne crée ni une seconde base de données ni une deuxième implémentation des règles métier.

## Architecture de cette tranche

`classification Gemini` → `contrat validé` → `AssistantDataAnalysisService` → `permission recontrôlée` → `AssistantBusinessExecutor` → `AssistantRepository` → `résultat structuré` → `contrat d'affichage existant`

## Travail réalisé dans cette livraison

- Ajout de `AssistantDataAnalysisService`, frontière backend pour les capacités déjà exécutées par `AssistantBusinessExecutor`.
- Vérification de l'existence de la capacité dans `AssistantCapabilityRegistry` et de la correspondance exacte entre intention et capacité.
- Recontrôle de la permission déclarée par la capacité au moment de l'exécution.
- Validation côté serveur des identifiants positifs, dates au format ISO, ordre des dates, statuts autorisés et limites de résultats plafonnées à 100.
- Refus des capacités non déclarées ou non raccordées, sans méthode dynamique ni SQL issu du modèle.
- Raccordement du chemin actuel des paiements, présences et résultats du contrôleur à cette frontière, tout en conservant le format de réponse historique consommé par le frontend.
- Gestion HTTP dédiée des paramètres invalides (422) et des permissions refusées (403).

## Ce que cette livraison ne prétend pas avoir terminé

La Phase D officielle contient huit sous-étapes. Cette livraison constitue le raccordement initial et sécurisé des capacités déjà existantes ; elle ne prétend pas achever les huit sous-étapes.

Restent à réaliser et vérifier :

- inventaire exhaustif du schéma et des relations métier pour chaque domaine ;
- raccordement uniforme des capacités élèves, inscriptions, classes, matières et parents, actuellement encore en partie exécutées directement dans le contrôleur ;
- vérification des règles métier de calcul et des dénominateurs (notamment taux de présence et taux de paiement) ;
- capacités d'analyse comparative multi-périodes/multi-classes, si les données et règles existantes le permettent ;
- tests automatisés d'intégration et de sécurité sur les comptes/permissions ;
- validation PHP/XAMPP et CI avant fusion.

## Conditions de validation avant fusion

1. Vérifier le chargement PHP et les includes dans l'application.
2. Tester les capacités raccordées avec des données de test représentatives.
3. Vérifier l'accès refusé avec un compte dépourvu de permission.
4. Tester identifiants invalides, dates invalides/inversées, statut inconnu, limites extrêmes et résultats vides.
5. Vérifier les payloads frontend et l'historique de conversation.
6. Exécuter la suite de tests disponible et contrôler les workflows CI.
7. Ne fusionner qu'après les validations réelles.

**Statut :** première tranche de Phase D implémentée sur la branche de travail. Phase D complète et fusion non déclarées.
