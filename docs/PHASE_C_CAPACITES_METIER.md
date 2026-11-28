# Phase C — Capacités métier de l’assistant

## Objectif

Permettre à Gemini de sélectionner une capacité métier identifiée, puis laisser exclusivement le backend valider les paramètres, contrôler les permissions et exécuter les opérations. Le modèle ne reçoit aucun accès SQL, aucun identifiant d’autorisation à faire confiance et aucun pouvoir d’exécution arbitraire.

Cette phase s’appuie sur le registre existant `AssistantCapabilityRegistry`, le `AssistantBusinessExecutor`, `AssistantRepository`, les services métier du projet et le plan borné ajouté en Phase B. Elle ne crée pas un deuxième système scolaire.

## État constaté sur la branche de travail

### Capacités déjà déclarées dans le registre

| Domaine | Capacités déclarées |
|---|---|
| Élèves | `students.count`, `students.gender_counts`, `students.list` |
| Organisation | `classes.count`, `classes.list`, `subjects.list` |
| Parents | `parents.list` |
| Paiements | `payments.summary`, `payments.unpaid_students` |
| Présences | `attendance.summary`, `attendance.list` |
| Résultats | `results.summary`, `results.top_students`, `results.failed_students`, `results.proclamation` |
| Rapports | `reports.school_summary` |
| Années scolaires | `school_years.list` |

La déclaration au registre ne suffit pas à prouver qu’une capacité est effectivement exécutable. Les contrôles doivent vérifier la correspondance entre intention, capacité, permission, paramètres normalisés, exécuteur et méthode repository.

### Exécution déjà présente

- `AssistantBusinessExecutor` contient des branches pour les synthèses/listes de paiements, les absences et les résultats.
- Le contrôleur exécute encore directement certaines capacités d’élèves, classes, matières et parents.
- Le repository possède déjà plusieurs méthodes de consultation, notamment les effectifs actifs, la répartition par genre, les listes d’élèves, de matières et de parents.
- La Phase B construit un plan borné, mais celui-ci ne remplace pas encore le flux d’exécution historique.

## Contrat de sécurité obligatoire pour chaque capacité

Une capacité ne sera considérée comme prête que si les éléments suivants sont présents et vérifiés :

1. Identifiant stable et unique, par exemple `students.count`.
2. Intention correspondante, sans possibilité de la substituer après validation.
3. Permission backend explicite, vérifiée à l’exécution.
4. Schéma de paramètres autorisés ; les champs inconnus sont rejetés ou ignorés selon le contrat commun, jamais interprétés comme des instructions.
5. Normalisation des identifiants par les services/repositories ; aucun ID brut fourni par Gemini ne donne accès à un dossier sans contrôle de périmètre.
6. Service ou handler métier dédié et repository existant/réutilisé.
7. Résultat structuré, borné et typé.
8. Limites de volume, pagination ou plafonds serveur pour les listes.
9. Tests de permission refusée, paramètres invalides, absence de données et erreurs de base.
10. Aucune opération d’écriture activée implicitement : cette phase concerne d’abord les capacités de consultation et d’analyse.

## Ordre de réalisation

### C.1 — Catalogue fiable et contrats

- Documenter pour chaque capacité son intention, sa permission, ses paramètres, ses valeurs par défaut et son type de résultat.
- Distinguer les paramètres obligatoires des filtres facultatifs. L’année scolaire active peut être résolue côté backend lorsque le produit le prévoit ; une classe ou une période ne doit pas être demandée systématiquement si elle n’est pas nécessaire.
- Détecter les capacités déclarées mais non raccordées à un exécuteur.
- Garantir que le catalogue transmis à Gemini ne contient que les capacités autorisées pour la session courante.

### C.2 — Première tranche : élèves et inscriptions

Priorité fonctionnelle :

- compter les inscriptions actives dans une année scolaire ;
- obtenir la répartition par genre à partir des données réelles ;
- rechercher/lister les élèves dans un périmètre limité ;
- filtrer par classe et année scolaire lorsque les méthodes métier le permettent ;
- ne jamais confondre fiche élève, inscription annuelle et élève actif.

Le résultat doit préciser le périmètre utilisé (année, classe, statut) afin que Gemini ne transforme pas un effectif filtré en effectif global.

### C.3 — Organisation scolaire

- consulter les classes et leur année scolaire ;
- consulter les matières selon la classe et les règles de l’établissement ;
- préparer le raccordement des enseignants et horaires uniquement après vérification des tables, services, permissions et règles existantes.

### C.4 — Présences

- synthèse des absences et retards selon les données et statuts réellement enregistrés ;
- filtres par classe, période et justification ;
- taux de présence seulement si le dénominateur (séances/jours attendus) est défini par une règle métier vérifiable. Ne pas calculer un taux approximatif à partir du seul nombre d’absences.

### C.5 — Notes et évaluations

- résultats et moyennes calculés par les services scolaires ;
- classement selon le rang ou la règle officielle persistée ;
- comparaison seulement lorsque les périodes et populations comparées sont cohérentes ;
- ne jamais inventer un seuil de réussite ou recalculer une décision officielle différemment du système.

### C.6 — Paiements et frais

- montant attendu, payé, restant et statut à partir des données comptables ;
- élèves en solde dû selon la règle métier ;
- taux de paiement avec dénominateur explicite et traitement des paiements partiels ;
- aucun calcul financier confié à Gemini.

## Architecture cible d’une exécution

`Gemini (choix d’outil)` → `classification normalisée` → `plan backend validé` → `permission recontrôlée` → `handler/service métier` → `repository` → `résultat structuré` → `Gemini (explication facultative)`

L’orchestrateur ne doit pas contourner les permissions ni appeler directement une méthode arbitraire. Le backend doit vérifier à nouveau l’identifiant de capacité avant toute exécution.

## Conditions de validation avant fusion

- Vérifier le chargement PHP et la compatibilité avec la version PHP documentée du projet.
- Vérifier chaque capacité contre le catalogue, le registre, le contrôleur, l’exécuteur et les repositories.
- Exécuter les tests automatisés disponibles et ajouter des tests ciblés aux nouvelles capacités.
- Tester manuellement avec des comptes disposant de permissions différentes.
- Vérifier que les réponses et le contrat frontend existant restent compatibles.
- Examiner les régressions : conversations, historique, CSRF, rate limit, import, pièces jointes et capacités existantes.
- Confirmer les checks CI sur le commit candidat.
- Ne fusionner qu’après validation des tests et revue de sécurité.

## Statut de cette documentation

Ce document formalise le périmètre et les critères de Phase C. Il ne prétend pas que les six domaines sont tous implémentés. La première livraison de code doit raccorder et tester une tranche métier à la fois, puis étendre le registre sans annoncer une capacité comme opérationnelle avant sa validation.
