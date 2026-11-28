<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/AssistantSecurityAuditService.php';

/**
 * Garde-fou central des actions IA.
 * Phase G crée et confirme des propositions; aucune action d'écriture n'est
 * exécutable tant qu'un adapter métier explicite n'est pas enregistré.
 */
final class AssistantActionGuardService
{
    private Database $db;
    private AssistantSecurityAuditService $audit;

    private const SAFE_ACTIONS = [
        'notes.save_cell' => [
            'permission' => 'notes.saisir',
            'description' => 'Préparer la saisie ou modification d’une note dans une évaluation existante.',
            'execution_enabled' => false,
        ],
    ];

    public function __construct(?Database $db = null, ?AssistantSecurityAuditService $audit = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->audit = $audit ?? new AssistantSecurityAuditService($this->db);
    }

    /** @return array{request_id:int,action:string,summary:string,confirmation_required:bool,expires_at:string} */
    public function prepare(int $userId, int $conversationId, string $actionCode, array $payload): array
    {
        if ($userId <= 0 || $conversationId <= 0 || !isset(self::SAFE_ACTIONS[$actionCode])) {
            throw new InvalidArgumentException('Action ou contexte utilisateur invalide.');
        }
        $definition = self::SAFE_ACTIONS[$actionCode];
        if (!can($definition['permission'])) {
            $this->audit->record($userId, 'assistant.action.prepare', 'denied', 'action', $actionCode);
            throw new RuntimeException('Permission métier refusée.');
        }
        $this->validatePayload($actionCode, $payload);
        // Reproduit les contrôles d'accès au dossier de l'évaluation et de l'élève.
        require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'NoteController.php';
        $noteController = new NoteController();
        $evaluationId = (int) $payload['evaluation_id'];
        if (!$noteController->canCurrentUserManageEvaluation($evaluationId)) {
            $this->audit->record($userId, 'assistant.action.scope_denied', 'denied', 'evaluation', (string) $evaluationId);
            throw new RuntimeException('Accès à cette évaluation refusé.');
        }
        $evaluationService = new EvaluationNoteService();
        $context = $evaluationService->getEntryContext($evaluationId);
        if (!$context) {
            throw new InvalidArgumentException('Évaluation introuvable ou incohérente.');
        }
        $studentIds = $evaluationService->getActiveStudentIds((int) $context['classe_id'], (int) $context['annee_scolaire_id']);
        if (!in_array((int) $payload['student_id'], $studentIds, true)) {
            $this->audit->record($userId, 'assistant.action.student_scope_denied', 'denied', 'student', (string) $payload['student_id']);
            throw new RuntimeException('Cet élève n’est pas inscrit dans la classe de cette évaluation.');
        }
        $maxima = $evaluationService->getSubjectMaxima((int) $context['classe_id'], (int) $context['groupe_id'], (string) $context['type_evaluation']);
        $subjectId = (int) $payload['subject_id'];
        if (!array_key_exists($subjectId, $maxima)) {
            throw new InvalidArgumentException('Cette sous-matière ne fait pas partie de cette évaluation.');
        }
        $value = $payload['value'] ?? null;
        if ($value !== null && (float) $value > (float) $maxima[$subjectId]) {
            throw new InvalidArgumentException('La note dépasse le maximum officiel de cette sous-matière.');
        }
        if (!$this->conversationOwnedBy($conversationId, $userId)) {
            $this->audit->record($userId, 'assistant.action.prepare', 'denied', 'conversation', (string) $conversationId);
            throw new RuntimeException('Conversation non accessible.');
        }

        // Les données utiles sont validées et encodées côté serveur.
        $normalized = $this->normalizePayload($actionCode, $payload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Impossible de préparer cette action.');
        }
        $hash = hash('sha256', $json);
        $expires = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
        $id = $this->db->insert(
            "INSERT INTO assistant_action_requests
                (utilisateur_id, conversation_id, action_code, target_type, target_id,
                 payload_json, payload_sha256, statut, expires_at)
             VALUES (:user_id, :conversation_id, :action_code, 'evaluation_note', :target_id,
                     :payload, :hash, 'draft', :expires)",
            [
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'action_code' => $actionCode,
                'target_id' => (string) $normalized['evaluation_id'],
                'payload' => $json,
                'hash' => $hash,
                'expires' => $expires,
            ]
        );
        $this->audit->record($userId, 'assistant.action.drafted', 'success', 'action_request', (string) $id, [
            'action_code' => $actionCode,
            'payload_hash' => $hash,
        ]);
        return [
            'request_id' => $id,
            'action' => $actionCode,
            'summary' => 'Une saisie de note a été préparée. Vérification humaine et confirmation requises; aucune note n’a été enregistrée.',
            'confirmation_required' => true,
            'expires_at' => $expires,
        ];
    }

    /**
     * Confirmation explicite : vérifie propriétaire, permission, état, délai
     * et empreinte. Elle ne déclenche pas l'écriture scolaire à ce stade.
     */
    public function confirm(int $userId, int $requestId, string $confirmPhrase): array
    {
        if ($userId <= 0 || $requestId <= 0 || !hash_equals('CONFIRMER', trim($confirmPhrase))) {
            throw new InvalidArgumentException('Confirmation explicite invalide.');
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM assistant_action_requests
             WHERE id = :id AND utilisateur_id = :user_id LIMIT 1',
            ['id' => $requestId, 'user_id' => $userId]
        );
        if (!$row || $row['statut'] !== 'draft' || strtotime((string) $row['expires_at']) <= time()) {
            $this->audit->record($userId, 'assistant.action.confirm', 'denied', 'action_request', (string) $requestId);
            throw new RuntimeException('Proposition absente, expirée ou déjà traitée.');
        }
        $definition = self::SAFE_ACTIONS[$row['action_code']] ?? null;
        if (!$definition || !can($definition['permission'])) {
            $this->audit->record($userId, 'assistant.action.confirm', 'denied', 'action_request', (string) $requestId);
            throw new RuntimeException('Permission métier non accordée au moment de la confirmation.');
        }
        $payload = json_decode((string) $row['payload_json'], true);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_array($payload) || !is_string($encoded) || !hash_equals((string) $row['payload_sha256'], hash('sha256', $encoded))) {
            $this->audit->record($userId, 'assistant.action.integrity_failure', 'error', 'action_request', (string) $requestId);
            throw new RuntimeException('Intégrité de la proposition non vérifiée.');
        }
        $changed = $this->db->update(
            "UPDATE assistant_action_requests
             SET statut = 'confirmed', confirmed_at = NOW()
             WHERE id = :id AND utilisateur_id = :user_id AND statut = 'draft' AND expires_at > NOW()",
            ['id' => $requestId, 'user_id' => $userId]
        );
        if ($changed !== 1) {
            throw new RuntimeException('La proposition a changé ou a déjà été confirmée.');
        }
        $this->audit->record($userId, 'assistant.action.confirmed_pending_adapter', 'success', 'action_request', (string) $requestId, [
            'action_code' => $row['action_code'],
            'payload_hash' => $row['payload_sha256'],
        ]);
        return [
            'request_id' => $requestId,
            'status' => 'confirmed_pending_adapter',
            'message' => 'Confirmation enregistrée. L’exécution automatique reste désactivée tant que l’adaptateur métier et les contrôles de périmètre ne sont pas validés.',
            'executed' => false,
        ];
    }

    private function conversationOwnedBy(int $conversationId, int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM assistant_conversations
             WHERE id = :id AND utilisateur_id = :user_id AND expires_at > NOW() LIMIT 1',
            ['id' => $conversationId, 'user_id' => $userId]
        ) !== null;
    }

    private function validatePayload(string $action, array $payload): void
    {
        if ($action !== 'notes.save_cell') {
            throw new InvalidArgumentException('Action non prise en charge.');
        }
        foreach (['evaluation_id', 'student_id', 'subject_id'] as $field) {
            if (filter_var($payload[$field] ?? null, FILTER_VALIDATE_INT) === false || (int) $payload[$field] < 1) {
                throw new InvalidArgumentException('Identifiants de note invalides.');
            }
        }
        $value = $payload['value'] ?? null;
        if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)) {
            throw new InvalidArgumentException('La note doit être un nombre fini positif ou nul; le maximum est vérifié depuis le barème de l’évaluation.');
        }
        if (!in_array((int) ($payload['absence'] ?? 0), [0, 1], true)) {
            throw new InvalidArgumentException('Statut d’absence invalide.');
        }
        if ($value === null && (int) ($payload['absence'] ?? 0) === 0) {
            throw new InvalidArgumentException('Une note ou un statut d’absence est requis.');
        }
    }

    private function normalizePayload(string $action, array $payload): array
    {
        return [
            'evaluation_id' => (int) $payload['evaluation_id'],
            'student_id' => (int) $payload['student_id'],
            'subject_id' => (int) $payload['subject_id'],
            'value' => isset($payload['value']) && $payload['value'] !== '' ? (float) $payload['value'] : null,
            'absence' => (int) ($payload['absence'] ?? 0),
        ];
    }
}
