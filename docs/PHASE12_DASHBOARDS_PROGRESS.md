# Phase 12 — Tableaux de bord : statistiques et indicateurs

## Contrat
Lire `docs/MVC_REFACTORING_CONTRACT.md` et `docs/ARCHITECTURE.md`. Conserver les mêmes indicateurs, calculs, unités monétaires, année scolaire, données visibles selon le rôle, routes et réponses AJAX.

## Audit ciblé et lot réalisé
- Le projet possédait déjà `DashboardService` et `DashboardRepository` pour les données graphiques AJAX, mais la page principale Admin/Directeur recalculait en SQL ses 8 KPI directement dans la vue.
- La vue administrative délègue désormais le chargement initial des KPI au `DashboardController`, qui orchestre `DashboardService` et `DashboardRepository`. Les valeurs par défaut et le calcul de l'évolution mensuelle restent identiques.
- Le contrôleur ne conserve plus de dépendance `Database` inutilisée.
- Les actions AJAX des graphiques sont maintenant protégées côté serveur par le rôle Admin/Directeur. Les vérifications de méthode HTTP et AJAX restent en place. Les données financières agrégées ne reposent pas sur le seul masquage de l'interface.

## État — Phase non clôturée
Ce lot est une première migration contrôlée, pas la clôture de toute la phase. Les vues des tableaux de bord Enseignant, Parent, Comptable et Secrétaire contiennent encore des lectures SQL directes et nécessitent un inventaire/migration propre à chaque domaine. Les indicateurs comptables ne doivent pas être recalculés hors des règles et services finance existants. La suite doit préserver le périmètre exact d'accès aux classes de l'enseignant et aux enfants liés du parent (risques IDOR/BOLA).

## Contrôles
- Smoke test sans base : `tests/phase12_dashboard_mvc_smoke.php`.
- CI : syntaxe PHP des fichiers modifiés + smoke test.
- À réaliser localement avec XAMPP : vérifier les chiffres de KPI par comparaison avec l'ancienne page, les permissions Admin/Directeur/autres rôles, graphiques AJAX, classes sans élèves/année active absente et affichage d'erreur base.
- Aucun test de navigateur ou de base XAMPP n'est prétendu exécuté par ce lot.
