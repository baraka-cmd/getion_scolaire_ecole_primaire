# Phase 14 — Validation finale et non-régression

## Référence vérifiée

- Dépôt : `baraka-cmd/getion_scolaire_ecole_primaire`
- Branche examinée : `main`
- SHA au début de la validation : `22db6febb94081cc6ccf82b58d9c510d543cd68c`
- Inventaire GitHub : 605 entrées, 325 fichiers PHP, 29 contrôleurs PHP, 106 vues PHP, 38 scripts de test PHP.
- Les PR de phase 0/1 à 13 ont des fusions enregistrées (la PR #7 était une PR empilée fermée sans fusion, remplacée par la PR #8). Cela confirme l'historique des merges, pas la clôture fonctionnelle de toutes les phases.

## Documentation lue et rapprochement phases 0–13

| Phase | Objet | Conclusion de validation documentaire |
|---|---|---|
| 0 | Audit en lecture seule | Contrat et architecture présents. L'audit historique est référencé, mais la cartographie exhaustive doit être comparée au dépôt courant. |
| 1 | Infrastructure/démarrage | Architecture indique migration progressive, pas une preuve de couverture complète bootstrap/router/helpers. |
| 2 | Authentification/permissions | Parcours ciblés extraits ; le contrat indique encore validation locale/authentification et plusieurs responsabilités Security à confirmer. |
| 3 | Référentiels | Explicitement en cours : formulaires legacy années/classes et mutations transactionnelles à migrer. |
| 4 | Élèves/dossiers | Explicitement non clôturée : mutations, parents, documents, import/export et contrôles IDOR restent à valider. |
| 5 | Enseignants | PR #3 fusionnée ; test smoke existe. Le merge ne prouve pas les tests XAMPP ni l'absence de SQL dans toutes les vues. |
| 6 | Inscriptions | PR #4 fusionnée ; smoke et workflow existent. Validation de bout en bout locale encore nécessaire. |
| 7 | Présences | PR #5 fusionnée ; document de validation existe, avec revalidation locale/base type production demandée. |
| 8 | Notes/évaluations | PR #6 fusionnée ; document de progression mentionne des tests de calcul/permissions et validation locale encore requise. |
| 9 | Résultats/bulletins | PR #8 fusionnée ; document indique phase non clôturée et tests de calcul, permissions, lint et DB locale requis. |
| 10 | Finances | PR #9 est un lot de rapports/référentiels. Le document exige encore tests de paiements, double paiement, remboursements, devise, permissions et validation globale. |
| 11 | Transversal | PR #10 fusionnée pour logs et sécurité fichiers. Le document reconnaît SQL restant dans NotificationController et des tests XAMPP workers/documents requis. |
| 12 | Dashboards | PR #11 fusionnée pour KPI/endpoints. Le document déclare explicitement phase non clôturée : vues comptable/enseignant/parent/secrétaire, SQL résiduel et tests fonctionnels encore à migrer. |
| 13 | Nettoyage dépendances | PR #12 et #13 fusionnées pour Classe et Matière. Le document indique explicitement dépendances d'autres contrôleurs, traçage, tests et vérification locale encore incomplets. |

## Résultats observables de cette session

- Les workflows de Phase 13 et Phase 4 ont réussi sur la tête de PR #13 (SHA `2850848959ffb50282ebb1be42fe5ce142953d71`). La PR #13 a été fusionnée ensuite en `22db6febb94081cc6ccf82b58d9c510d543cd68c`.
- La nouvelle suite globale Phase 14 a été exécutée sur la tête de PR SHA `29b6407ff615814aabb89a18529fb84df1f5c1f8` : **succès**. Elle a linté tous les PHP suivis et exécuté les 38 scripts de `tests/` sans échec. [Run global](https://github.com/baraka-cmd/getion_scolaire_ecole_primaire/actions/runs/46046515027). Le workflow Phase 4 déclenché sur la même PR a également réussi.
- Le clonage dans cet environnement a échoué car la résolution réseau GitHub n'est pas disponible. PHP/XAMPP et la base locale ne sont pas accessibles ici ; aucun test local ni test d'intégration DB ne peut être déclaré exécuté.
- Le code et les documents montrent encore une dette MVC connue : accès SQL dans des vues historiques, responsabilités DB dans plusieurs contrôleurs (ex. EffectifController, EmploiController, ParentController, BulletinController, EnseignantController, EleveController, StatistiqueController) et migration dashboard incomplète.

## Validation automatisée ajoutée

Le workflow `.github/workflows/phase-14-global-validation.yml` :
1. lint tous les fichiers PHP suivis par Git ;
2. exécute les scripts PHP de `tests/` sans connexion à la base ;
3. publie les écarts MVC connus comme contexte de validation.

## Décision de clôture

**Phase 14 : lint global + 38 scripts de tests sans base validés en CI. La certification complète de non-régression reste ouverte** tant que les dettes MVC recensées et les tests fonctionnels XAMPP/base de données ne sont pas traités.

La branche `main` est clonable comme snapshot de travail, mais elle ne peut pas être qualifiée de « MVC appliqué partout et prêt à validation finale » sur la seule base des merges. Avant de certifier le clone :
- conserver l'exécution verte du workflow Phase 14 sur le commit de validation ;
- résoudre les dettes documentées des phases 3, 4, 8–13, surtout vues dashboard et responsabilités controller ;
- lancer sous XAMPP avec une base de test les parcours auth/roles, élèves/parents/documents, enseignants/affectations, inscriptions, présences, notes/bulletins, paiements/annulations/reçus, notifications/uploads et dashboards multi-rôles ;
- comparer routes, réponses JSON/AJAX, règles de calcul, permissions et rendu avec le comportement antérieur.

Ne pas marquer les phases 12, 13 ou 14 terminées avant ces preuves.
