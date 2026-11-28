# Phase G — Sécurité avancée et actions métier

## Références lues avant la mise en œuvre
- `docs/REFLEXION_AMELIORATION_ASSISTANT_IA.md`
- `docs/PLAN_DETAILLE_FONCTIONNEMENT_ASSISTANT_IA.md`
- `docs/PHASE_C_CAPACITES_METIER.md`
- `docs/PHASE_D_DONNEES_REELLES_ANALYSES.md`
- `docs/PHASE_F_CONNAISSANCES_DOCUMENTAIRES.md`
- Code existant : `AssistantCapabilityRegistry`, `AssistantBusinessExecutor`, `AssistantDataAnalysisService`, `NoteController::sauvegarderNotes()`, `EvaluationNoteService`, `EvaluationNoteRepository`, `StudentAccessPolicy`, `Security` et système de permissions.

## G.1 — Audit des permissions
Les capacités déclarées en Phase C sont des capacités de lecture. L'écriture n'est pas ajoutée au registre de lecture ni déclenchée par le modèle. La première action expérimentale est explicitement whitelistee `notes.save_cell`, permission `notes.saisir`, mais son exécution reste désactivée.

## G.2–G.3 — Dossiers et IDOR/BOLA
Le garde-fou de proposition :
- vérifie le propriétaire de la conversation et son expiration ;
- appelle la règle d'accès à l'évaluation déjà exposée par `NoteController::canCurrentUserManageEvaluation()` (incluant l'affectation de classe des enseignants) ;
- vérifie l'inscription active de l'élève dans la classe/année de l'évaluation ;
- vérifie que la sous-matière appartient au groupe évalué et récupère son maximum officiel ;
- répète ces contrôles au moment de la confirmation, pas seulement au brouillon ;
- ne révèle pas les ressources refusées comme accessibles.

## G.4–G.6 — Propositions, confirmation, validation
Nouveau flux borné :
1. `POST /api/assistant/actions/prepare` prépare un brouillon de note normalisé.
2. La proposition expire après 5 minutes et son payload est protégé par SHA-256.
3. `POST /api/assistant/actions/confirm` exige une confirmation explicite exacte (`CONFIRMER`), vérifie propriétaire, état draft, expiration, permission, intégrité, évaluation, élève, sous-matière et barème à nouveau.
4. Transition atomique conditionnelle draft → confirmed, pour éviter la double confirmation.
5. Le backend retourne `confirmed_pending_adapter`, `executed=false`.

**Important :** cette livraison ne réalise pas encore INSERT/UPDATE dans `notes`. Le moteur de modification reste fermé jusqu'à l'intégration d'un adapter métier transactionnel qui réutilise intégralement les validations et règles de `NoteController::sauvegarderNotes()`, puis jusqu'aux tests et à une activation explicite. La confirmation seule n'écrit jamais une note.

## G.7 — Audit
Migration additive :
- `assistant_action_requests` conserve état, propriétaire, cible, empreinte, expiration et timestamps ;
- `assistant_security_audit` conserve les événements de préparation, refus, intégrité et confirmation, avec détails filtrés et empreinte IP uniquement si une clé `APP_AUDIT_HASH_KEY` est configurée.
Les détails excluent notamment valeurs de note, secrets, jetons et données personnelles sensibles. La table est conçue pour être alimentée en append-only par le service ; les droits SQL de production doivent également interdire UPDATE/DELETE aux identifiants applicatifs non privilégiés.

## G.8 — Tests de sécurité à exécuter
- utilisateur sans permission ;
- conversation d'un autre utilisateur / expirée ;
- évaluation non attribuée au professeur ;
- élève hors inscription, sous-matière hors groupe ;
- note hors maxima officiel ou absence + valeur numérique ;
- brouillon expiré, modifié, confirmé deux fois ou confirmation concurrente ;
- CSRF, mauvais verbe HTTP, identifiants invalides ;
- données altérées et injection de champs non prévus ;
- absence de modification effective de la table `notes` tant que l'adapter est désactivé.

## Fichiers
- `database/migrations/20261128_002_assistant_secure_actions.sql`
- `src/services/AssistantSecurityAuditService.php`
- `src/services/AssistantActionGuardService.php`
- `src/controllers/AssistantActionController.php`
- `public/index.php`

## Statut
Première tranche de garde-fous enregistrée sur la branche Phase G. Le mécanisme prépare et confirme une action mais n'exécute pas la modification métier. Tests PHP/SQL/CSRF/IDOR, validation locale et CI sont requis avant toute activation ou fusion.
