# Phase 10 — Finances (suivi de mise en œuvre)

## Contrat obligatoire
Lire `docs/MVC_REFACTORING_CONTRACT.md` et `docs/ARCHITECTURE.md`.

Migration MVC uniquement : conserver les règles de frais, conversions USD/CDF, génération des créances, affectations, reçu, remboursements/contre-écritures, rapports, permissions, routes, formats JSON et historique comptable. Pas de migration SQL ni de changement de calcul dans cette phase.

## Périmètre
- Frais scolaires et paramètres d'échéance.
- Créances et génération idempotente annuelle.
- Encaissements, allocations, conversions et soldes.
- Annulations/remboursements par contre-écriture.
- Reçus imprimables et historique.
- Impayés, suivi de relance et rapports.

## Lot 1 — Référentiels du rapport financier
- Création de `FinanceReportRepository` pour les lectures années scolaires, année active, classes et frais configurés.
- `FinanceReportService` délègue ces requêtes au repository ; le service conserve validation des filtres, choix de l'année, agrégations et logique de rapport.
- Injection facultative du repository pour permettre des tests isolés.
- Test smoke : `tests/finance_report_reference_data_smoke.php`.

Ce lot ne représente pas la clôture de la Phase 10.

## Lots restant à réaliser
1. Extraire les requêtes d'agrégation et de détail de `FinanceReportService` vers un repository, sans déplacer les règles de filtrage/présentation.
2. Migrer progressivement les opérations frais CRUD de `PaiementController` vers FinanceService/FinanceRepository.
3. Tracer puis migrer listes/registre, recherche, créances, statistiques, impayés et suivi.
4. Auditer et déplacer le chargement des reçus imprimables depuis les vues vers un contrôleur/service/repository, avec contrôle d'accès par élève et compatibilité des URL.
5. Vérifier paiements, transaction, verrouillage des créances, contre-écritures, permissions, CSRF et réponses AJAX.
6. Ajouter des tests sur conversions, plafonnement du versement, prévention double paiement, annulation unique, isolation d'année/classe et accès refusé.

## Validation avant fusion
- Revue complète du diff et appels/routes/JS concernés.
- `php -l` sur chaque fichier modifié, tests smoke et CI.
- Tests locaux avec base de test : créer/modifier frais, générer créances deux fois, paiement partiel/complet, devises, reçu, annulation/remboursement, rapport et permissions.
- Aucune migration ou modification du schéma dans cette refonte.
- Ne pas merger tant que les lots de la phase et les validations nécessaires ne sont pas achevés.
