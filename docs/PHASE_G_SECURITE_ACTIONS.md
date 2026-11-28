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
1. `POST /api/assistant/actions/grade/prepare` prépare une proposition de note normalisée avec token aléatoire remis une seule fois.
2. La proposition expire après 10 minutes ; le payload est protégé par SHA-256 et le token de confirmation est stocké uniquement sous forme de hash.
3. `POST /api/assistant/actions/grade/confirm` exige le token aléatoire de confirmation et vérifie propriétaire, état draft, expiration, permission, intégrité, évaluation, élève, sous-matière et barème à nouveau.
4. La confirmation verrouille la demande avec `SELECT ... FOR UPDATE`, puis exécute la sauvegarde et marque la demande `executed` dans la même transaction, empêchant le rejeu.

**Important :** l'adapter sécurisé dédié `AssistantSecureActionService` est désormais raccordé à `EvaluationNoteService::saveCell()` dans une transaction. Il revalide l'affectation enseignant, l'accès élève, l'inscription active, la sous-matière et le maximum officiel. Il ne fait pas confiance à une permission ou à un barème fournis par Gemini. Cette première action réelle reste à valider par tests automatisés et XAMPP avant usage/merge.

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
- `src/services/AssistantSecureActionService.php`
- `src/controllers/AssistantSecureActionController.php`
- `public/index.php`

## Statut
Première tranche de sécurité et une action métier réelle enregistrées sur la branche Phase G. Le code est écrit et relu sur GitHub ; les tests PHP/SQL/CSRF/IDOR, validation locale et CI restent requis avant de déclarer la phase terminée ou de fusionner.
