# Phase 12 — Tableaux de bord : statistiques et indicateurs

## Contrat
Lire `docs/MVC_REFACTORING_CONTRACT.md` et `docs/ARCHITECTURE.md`. Conserver les indicateurs, calculs, unités monétaires, année scolaire, périmètre des données par rôle, routes, HTML/JS et réponses AJAX.

## Lot déjà fusionné
- La page Admin/Directeur délègue ses KPI initiaux au `DashboardController` → `DashboardService` → `DashboardRepository`.
- Les actions AJAX graphiques sont contrôlées côté serveur (Admin/Directeur), méthode HTTP et AJAX.
- CI/smoke du lot initial : voir `tests/phase12_dashboard_mvc_smoke.php` et workflow `.github/workflows/phase-12-dashboards.yml`.

## Parcours restant, précis
Les quatre vues suivantes ont encore des appels directs `$db->fetchOne/fetchAll/fetchColumn`. Elles ne sont donc pas encore conformes au contrat MVC.

| Vue | Responsabilités à déplacer | Protection métier à préserver |
|---|---|---|
| `enseignant.php` | classe affectée, effectif, présence quotidienne et mensuelle, notes du trimestre, genre, absences, examens, élèves en difficulté, anniversaires | classe déterminée depuis l'utilisateur connecté ; aucun identifiant de classe fourni par le navigateur |
| `parent.php` | parent lié à l'utilisateur, enfants liés, moyenne, absences/retards par trimestre, solde, événements, messages et notifications | filtrage strict via `eleve_parent` ; ne jamais afficher un enfant non lié |
| `comptable.php` | année active, soldes/impayés, reçus du mois, échéances, suivis, séries mensuelles, soldes par classe, états de dette, derniers reçus | garder les calculs USD/CDF, annulations et affectations dans les règles financières existantes ; pas de montant calculé par le client |
| `secretaire.php` | effectifs, enseignants/classes, inscriptions récentes/en attente, distribution par sexe, événements, absences du jour, messages | année scolaire et données de l'établissement cohérentes ; autorisations de rôle côté serveur |

## Étapes et validation
- [x] Première délégation KPI Admin/Directeur fusionnée.
- [x] Autorisation serveur sur endpoints graphiques globaux.
- [x] Vues et SQL résiduels identifiés par rôle.
- [ ] Extraire chaque rôle vers repository/service spécialisé, sans mélanger les domaines.
- [ ] Faire passer les vues à une consommation de données préparées par contrôleur/service.
- [ ] Tests automatisés de contrat : absence de SQL dans ces vues, scoping parent/enseignant, calculs comptables, année active et cas sans données.
- [ ] Vérification de diff et CI verte.
- [ ] Tests fonctionnels XAMPP : comparaison ancienne/nouvelle page, rôles, graphiques, cas limites.

## État
**Phase 12 non clôturée.** Les tests smoke/CI du lot précédent ne prouvent pas la conformité des quatre vues restantes. Aucun test XAMPP/navigateur n'est déclaré exécuté à distance.


## Journal de migration MVC — lot en cours (27 novembre 2026)

Sur la branche `refactor/mvc-phase12-13-completion`, les données de présentation des dashboards **Enseignant** et **Parent** ont été déplacées des vues vers leurs chaînes dédiées Controller → Service → Repository :

- `TeacherDashboardController/Service/Repository` : classe assignée, effectifs, présences, notes, répartition, absences, examens, élèves en difficulté et anniversaires.
- `ParentDashboardController/Service/Repository` : parent authentifié, enfants liés, moyenne, absences/retards, solde, événements, messages et notifications.
- Les requêtes existantes ont été reprises avec leurs paramètres ; le périmètre parent-enfant reste filtré par `eleve_parent.parent_id` côté repository.
- Les deux vues consomment maintenant les données préparées et ne contiennent plus de requêtes directes dans leur bloc de préparation.
- Des smoke tests statiques et le workflow de phase 12 ont été étendus pour les deux parcours.

**Lot non clôturé :** Comptable et Secrétaire restent à migrer. Les tests PHP/CI n'ont pas encore été observés comme réussis sur le commit courant; la comparaison avec une base et une session XAMPP demeure obligatoire. Cette progression ne clôt pas la Phase 12.
