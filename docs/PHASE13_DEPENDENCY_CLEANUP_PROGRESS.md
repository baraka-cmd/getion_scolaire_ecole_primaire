# Phase 13 — Nettoyage contrôlé des dépendances et responsabilités

## Règle de sécurité
Cette phase ne consiste pas à supprimer en masse les propriétés `Database`, imports, contrôleurs ou fichiers historiques. Une dépendance ne peut être retirée que lorsque :
1. ses références directes et indirectes sont recensées dans tout le dépôt ;
2. ses responsabilités sont reprises par un composant MVC identifié ;
3. les routes, appels AJAX, tâches planifiées, vues et tests restent compatibles ;
4. les tests ciblés et la syntaxe passent ;
5. le diff confirme qu'aucune fonctionnalité ou autorisation n'a été supprimée.

## Inventaire de départ (branche main, commit 5e150f9)
L'inspection du dépôt montre des contrôleurs qui portent encore des accès DB nécessaires à leur logique métier. Il ne faut donc pas les considérer comme « dépendances mortes » par défaut.

Contrôleurs à examiner et à traiter par lots ciblés :
- ClasseController, ParentController, EleveController, EnseignantController ;
- InscriptionController, AnneeController, MatiereController, EffectifController ;
- EmploiController, NoteController, BulletinController, ResultatController ;
- PaiementController, StatistiqueController, MessageController, NotificationController ;
- SauvegardeController et les autres contrôleurs recensés dans `src/controllers`.

## Critères d'acceptation
- Chaque extraction est accompagnée d'une preuve d'usage et d'un test de non-régression pertinent.
- Les requêtes restent paramétrées ; les contrôles d'accès demeurent côté serveur.
- Les transactions restent au niveau du service métier qui garantit leur cohérence.
- Les dépendances encore utilisées sont conservées et explicitement documentées.
- Aucune suppression d'API, route ou ancien fichier sans recherche complète de ses consommateurs.

## Journal de parcours
- [x] Référence et périmètre du dépôt vérifiés : `main` à `5e150f9`.
- [x] Inventaire initial des contrôleurs contenant des responsabilités DB commencé.
- [ ] Tracer chaque dépendance à travers le dépôt (références, appels, routes, vues).
- [x] Lot contrôlé ClasseController : écritures classe/titulaire/planning déléguées au repository via service ; transactions centralisées ; dépendance DB directe retirée.
- [x] Lot MatiereController : création/suppression et validation du groupe parent déléguées au service/repository ; transactions atomiques ; dépendance DB directe supprimée.
- [ ] Continuer le traçage et l'extraction des autres contrôleurs listés, sans déplacement mécanique.
- [x] Ajouter un smoke test de contrat MVC et un workflow CI ciblé pour le lot ClasseController.
- [ ] Ajouter/adapter les tests de comportement des autres domaines.
- [ ] Vérifier le diff, les contrôles CI et les tests XAMPP.
- [ ] Fusionner uniquement après validation de tous les critères.

## État
**Phase 13 non clôturée après ce lot.** Ce document établit la méthode et l'inventaire initial ; il ne prétend pas que les dépendances ont déjà été retirées. Les phases 12 et 13 ne doivent être déclarées terminées qu'après migration réelle des vues restantes de Phase 12 et audit/remplacement prouvé des responsabilités de Phase 13.
