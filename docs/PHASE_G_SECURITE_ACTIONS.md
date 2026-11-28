# Phase G — Sécurité avancée et actions métier

## Références lues avant l'implémentation
- `docs/REFLEXION_AMELIORATION_ASSISTANT_IA.md`
- `docs/PLAN_DETAILLE_FONCTIONNEMENT_ASSISTANT_IA.md`
- `docs/PHASE_B_ORCHESTRATEUR.md`
- `docs/PHASE_C_CAPACITES_METIER.md`
- `docs/PHASE_D_DONNEES_REELLES_ANALYSES.md`
- `docs/PHASE_E_CONVERSATION_EXPERIENCE.md`
- `docs/PHASE_F_CONNAISSANCES_DOCUMENTAIRES.md`
- Code contrôlé : `Security`, `AssistantCapabilityRegistry`, `AssistantBusinessExecutor`, `NoteController`, `EvaluationNoteService`, `EvaluationNoteRepository`, `StudentAccessPolicy`, journal `logs_activites`.

## Architecture / principes
Gemini ne possède aucun accès DB ni pouvoir d'écriture. La mutation est autorisée par un handler PHP fixe, après permission actuelle, portée utilisateur, validation métier et confirmation explicite. La confirmation est un jeton aléatoire à usage unique, stocké uniquement sous forme SHA-256, attaché au propriétaire et expirant après 5 minutes. Le payload est hashé, verrouillé `FOR UPDATE`, revalidé et exécuté dans la transaction PDO du projet.

## G.1 — Audit des permissions
`AssistantSecurityActionService::auditCapabilities()` compare chaque permission déclarée au registre à la table `permissions`, et indique si la permission est définie et accordée au compte courant. Endpoint de consultation réservé à la permission `assistant.security.audit`. L'audit ne confond pas permission présente en base et permission effectivement accordée.

## G.2–G.3 — Dossiers et IDOR/BOLA
`assertStudentScope()` réutilise `StudentAccessPolicy`. Pour l'action note, la validation contrôle également l'existence de l'évaluation, l'affectation de l'enseignant à la classe/année, l'inscription active de l'élève dans cette classe/année et l'appartenance de la sous-matière au groupe d'évaluation. Les IDs fournis par l'IA sont des références à revalider, jamais des autorisations.

## G.4–G.6 — Action métier limitée
Première action supportée : `note.write` (saisie d'une note numérique).
- Permission `assistant.actions.proposer` ET `notes.saisir`.
- Les suppressions, absences et actions génériques/dynamiques ne sont pas exposées.
- Note, évaluation, élève, sous-matière et maximum sont vérifiés côté serveur à la proposition puis à la confirmation.
- Le service officiel `EvaluationNoteService::saveCell()` réalise la mutation ; aucun SQL d'écriture ne vient du modèle.

## G.5 — Confirmation explicite
`POST /api/assistant/actions/note/propose` retourne une prévisualisation, request ID, jeton opaque et expiration ; l'action n'est pas encore exécutée.
`POST /api/assistant/actions/note/confirm` exige le même utilisateur, CSRF, permission, jeton correct, payload intègre, statut pending et délai valide. La demande est verrouillée et ne peut être confirmée deux fois.

## G.7 — Audit
Le système écrit une activité de proposition sans modification et un événement `logs_activites` dans la transaction d'exécution, sans enregistrer la valeur de note dans le texte de log. La table `assistant_action_requests` conserve le statut, le payload contrôlé, l'empreinte, l'auteur, l'expiration et le résultat.

## G.8 — Tests de sécurité à exécuter avant merge
1. Permission absente : proposition et confirmation refusées.
2. Autre utilisateur : request ID/confirmation token refusés.
3. Confirmation rejouée : refusée (statut non pending).
4. Jeton altéré, payload altéré, demande expirée : refus.
5. Évaluation inconnue, élève d'une autre classe/année, inscription inactive, enseignant non affecté : refus.
6. Sous-matière étrangère à l'évaluation, note négative/supérieure au barème/non numérique/vide : refus.
7. CSRF absent, mauvais verbe HTTP, paramètres array/object : refus.
8. Transaction en erreur : note et statut/audit transactionnel rollbackés.
9. Vérifier l'absence de mutation à la proposition et la mutation unique après confirmation.
10. Vérifier les permissions réelles des rôles et les règles d'accès parent/enseignant sur un jeu de données de test.

## Routes
- `GET /api/assistant/security/audit`
- `POST /api/assistant/actions/note/propose`
- `POST /api/assistant/actions/note/confirm`

## Limites honnêtes
Cette tranche ajoute les mécanismes backend, mais ne connecte pas encore l'action à une boucle Gemini de tool-calling complète (Phase B reste partielle) et ne fournit pas encore le parcours UI conversationnel de confirmation. Le registre de capacités en lecture est audité, pas automatiquement corrigé : l'attribution de droits reste une décision administrative. Les tests PHP, intégration XAMPP et CI doivent être exécutés avant fusion.

**Statut :** code de fondation G.1–G.7 écrit sur la branche dédiée. G.8 documenté mais tests non exécutés ; pas de merge ni de déclaration « prêt production ».
