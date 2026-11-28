# Phase G — Sécurité avancée et actions métier

## Documentation relue avant l'implémentation
- `docs/REFLEXION_AMELIORATION_ASSISTANT_IA.md` : séparation du modèle, des outils backend et des permissions.
- `docs/PLAN_DETAILLE_FONCTIONNEMENT_ASSISTANT_IA.md` : capacités, exécution serveur, clarification et règle « le modèle ne fait pas les opérations SQL ».
- `docs/PHASE_C_CAPACITES_METIER.md`, `docs/PHASE_D_DONNEES_REELLES_ANALYSES.md`, `docs/PHASE_E_CONVERSATION_EXPERIENCE.md`, `docs/PHASE_F_CONNAISSANCES_DOCUMENTAIRES.md`.
- Code inspecté : `Security`, `AssistantCapabilityRegistry`, `AssistantDataAnalysisService`, `AssistantBusinessExecutor`, `NoteController`, `EvaluationNoteService`, `EvaluationNoteRepository`, `StudentAccessPolicy` et les logs existants.

## G.1 — Permissions
Les capacités déclarent une permission exacte et `AssistantDataAnalysisService` la recontrôle au moment de l'exécution. Nouveau service `AssistantCapabilitySecurityAudit` compare le registre aux permissions en base et détecte les permissions manquantes/définitions incomplètes, sans modifier les rôles.

## G.2–G.3 — Dossiers individuels et IDOR/BOLA
- La saisie de note revalide l'accès à l'élève via `StudentAccessPolicy`, le contexte de l'évaluation, l'affectation enseignant, l'inscription active et l'appartenance de la sous-matière à l'évaluation.
- Pour les capacités de données assistant, le scope enseignant est fail-closed : classe et année doivent être fournies et l'affectation active doit être vérifiée par `EvaluationNoteService::teacherHasClassAssignment`.
- Les parents sont bloqués sur ces capacités tant que les repositories ne prouvent pas systématiquement le lien parent-enfant pour chaque ligne retournée, et sont renvoyés vers leur espace individuel.
- L'audit exhaustif des autres rôles et de chaque repository reste requis avant activation générale.

## G.4–G.6 — Actions, confirmation et validation
La migration `20261128_002_assistant_secure_actions.sql` ajoute les demandes d'action et le journal de sécurité. L'action réelle autorisée dans cette tranche est limitée à `grades.store` :
1. proposition explicite, sans écriture ;
2. payload serveur normalisé, empreinte SHA-256 et token aléatoire 256 bits stocké sous forme hashée ;
3. expiration après 10 minutes ;
4. confirmation séparée par POST avec CSRF ;
5. vérification propriétaire, état draft, expiration, token et intégrité ;
6. revalidation de permission, évaluation, affectation, élève actif, sous-matière et barème à la confirmation ;
7. verrouillage transactionnel, sauvegarde via `EvaluationNoteService::saveCell`, statut executed et audit dans la même transaction ;
8. une action exécutée ne peut pas être rejouée.

Aucune valeur métier n'est prise depuis le navigateur ou Gemini comme autorité : la note doit respecter le maximum réel et la règle absence/note. Les endpoints sont :
- `POST /api/assistant/actions/grade/prepare`
- `POST /api/assistant/actions/grade/confirm`

Le texte « enregistre » dans la conversation ne déclenche pas automatiquement la mutation. Le parcours UI/orchestrateur doit demander une confirmation explicite puis appeler l'endpoint de confirmation.

## G.7 — Traces d'audit
`AssistantSecurityAuditService` enregistre préparation, exécution et refus dans `assistant_security_audit`. Les détails sont filtrés ; la note et les jetons ne sont pas journalisés. L'adresse IP est conservée uniquement sous HMAC si `APP_AUDIT_HASH_KEY` est configurée. L'activité est également tracée dans le journal applicatif existant.
Le durcissement des droits SQL (refus UPDATE/DELETE pour le compte applicatif) doit être confirmé dans l'environnement de déploiement.

## G.8 — Gate de tests obligatoire
Tester avant merge/activation : permissions et CSRF ; autre propriétaire/évaluation ; professeur non affecté ; année inactive ; élève désinscrit ; sous-matière étrangère ; note négative/supérieure au barème/NaN/INF ; absence + note ; token faux/expiré/altéré ; payload hash modifié ; double confirmation et concurrence ; rollback atomique ; absence de secrets/valeurs de notes dans les logs.

## Fichiers
- `database/migrations/20261128_002_assistant_secure_actions.sql`
- `src/services/AssistantSecurityAuditService.php` (journal des actions et événements)
- `src/services/AssistantCapabilitySecurityAudit.php` (audit du registre de capacités)
- `src/services/AssistantCapabilitySecurityAudit.php`
- `src/services/AssistantSecureActionService.php`
- `src/controllers/AssistantSecureActionController.php`
- `src/services/AssistantDataAnalysisService.php`
- `public/index.php`

**Statut :** code de sécurité et parcours de confirmation enregistré sur la branche Phase G. Tests PHP/DB/concurrence, audit exhaustif des repositories et intégration de l'UX conversationnelle restent à effectuer. Aucun merge n'a été réalisé.
