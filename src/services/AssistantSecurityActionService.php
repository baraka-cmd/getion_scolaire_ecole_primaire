<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'EvaluationNoteService.php';
require_once SRC_PATH . 'policies' . DIRECTORY_SEPARATOR . 'StudentAccessPolicy.php';

/**
 * Contrôles de sécurité et cycle de confirmation des actions assistant.
 * Seule la capacité note.write est autorisée dans cette tranche.
 */
final class AssistantSecurityActionService
{
    private Database $db;
    private Security $security;
    private EvaluationNoteService $notes;
    private StudentAccessPolicy $studentPolicy;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->security = Security::getInstance();
        $this->notes = new EvaluationNoteService();
        $this->studentPolicy = new StudentAccessPolicy($this->db);
    }

    /** G.1 : audite le catalogue en regard de la table permissions. */
    public function auditCapabilities(): array
    {
        $catalog = AssistantCapabilityRegistry::all();
        $codes = array_values(array_unique(array_map(
            static fn(array $item): string => (string) $item['permission'],
            $catalog
        )));
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $known = $this->db->fetchAll(
            "SELECT code FROM permissions WHERE code IN ({$placeholders})",
            $codes
        );
        $knownCodes = array_fill_keys(array_column($known, 'code'), true);
        $items = [];
        foreach ($catalog as $key => $capability) {
            $permission = (string) $capability['permission'];
            $items[] = [
                'capability' => $key,
                'intent' => $capability['intent'],
                'permission' => $permission,
                'permission_defined' => isset($knownCodes[$permission]),
                'permission_granted_current_user' => $this->security->can($permission),
                'read_only' => true,
            ];
        }
        return [
            'capabilities' => $items,
            'missing_permissions' => array_values(array_filter($codes, static fn(string $code): bool => !isset($knownCodes[$code]))),
            'checked_at' => date(DATE_ATOM),
        ];
    }

    /**
     * G.2/G.3 : vérifie la portée dossier et empêche le modèle de choisir un
     * étudiant hors des inscriptions/affectations du compte connecté.
     */
    public function assertStudentScope(int $studentId): void
    {
        if (!$this->studentPolicy->canCurrentUserAccessStudent($studentId)) {
            throw new DomainException('Dossier scolaire non accessible à cet utilisateur.');
        }
    }

    /** G.4/G.5 : crée une demande à usage unique, sans exécuter la mutation. */
    public function proposeNote(int $evaluationId, int $studentId, int $subjectId, mixed $rawValue): array
    {
        requireLogin();
        $this->security->requirePermission('assistant.actions.proposer');
        $this->security->requirePermission('notes.saisir');
        $this->validateNoteTarget($evaluationId, $studentId, $subjectId, $rawValue);

        if ($rawValue === null || $rawValue === '') {
            throw new InvalidArgumentException('Une note numérique est obligatoire pour cette action. Les suppressions de notes ne sont pas exposées à l’assistant.');
        }
        $value = (float) $rawValue;
        $payload = [
            'evaluation_id' => $evaluationId,
            'eleve_id' => $studentId,
            'sous_matiere_id' => $subjectId,
            'valeur' => $value,
            'absence' => 0,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $confirmation = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
        $id = $this->db->insert(
            'INSERT INTO assistant_action_requests
                (utilisateur_id, action_code, payload_json, payload_hash, confirmation_hash, statut, expires_at)
             VALUES (:user_id, :action, :payload, :payload_hash, :confirmation_hash, "pending", :expires)',
            [
                'user_id' => (int) $_SESSION['utilisateur_id'],
                'action' => 'note.write',
                'payload' => $payloadJson,
                'payload_hash' => hash('sha256', $payloadJson),
                'confirmation_hash' => hash('sha256', $confirmation),
                'expires' => $expires,
            ]
        );
        logActivity('Assistant action proposée', 'assistant_action_requests', $id,
            'Action note.write préparée; aucune note modifiée. Évaluation ' . $evaluationId . ', élève ' . $studentId . ', sous-matière ' . $subjectId);
        return [
            'request_id' => $id,
            'confirmation_token' => $confirmation,
            'action' => 'note.write',
            'preview' => $payload,
            'expires_at' => $expires,
            'requires_confirmation' => true,
            'executed' => false,
        ];
    }

    /** G.5–G.7 : confirmation, revalidation et mutation transactionnelle. */
    public function confirmNote(int $requestId, string $token): array
    {
        requireLogin();
        $this->security->requirePermission('assistant.actions.proposer');
        $this->security->requirePermission('notes.saisir');
        if ($requestId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Confirmation invalide.');
        }

        $userId = (int) $_SESSION['utilisateur_id'];
        $this->db->beginTransaction();
        try {
            $request = $this->db->fetchOne(
                'SELECT * FROM assistant_action_requests
                 WHERE id = :id AND utilisateur_id = :user_id FOR UPDATE',
                ['id' => $requestId, 'user_id' => $userId]
            );
            if (!$request || $request['action_code'] !== 'note.write') {
                throw new RuntimeException('Demande introuvable ou non autorisée.');
            }
            if ($request['statut'] !== 'pending') {
                throw new DomainException('Cette demande a déjà été traitée ou annulée.');
            }
            if (strtotime((string) $request['expires_at']) <= time()) {
                $this->db->update('UPDATE assistant_action_requests SET statut = "expired" WHERE id = :id', ['id' => $requestId]);
                throw new DomainException('La confirmation a expiré. Préparez une nouvelle demande.');
            }
            if (!hash_equals((string) $request['confirmation_hash'], hash('sha256', $token))) {
                throw new DomainException('Jeton de confirmation incorrect.');
            }
            if (!hash_equals((string) $request['payload_hash'], hash('sha256', (string) $request['payload_json']))) {
                throw new RuntimeException('Intégrité de la demande compromise.');
            }

            $payload = json_decode((string) $request['payload_json'], true, 16, JSON_THROW_ON_ERROR);
            $this->validateNoteTarget(
                (int) $payload['evaluation_id'],
                (int) $payload['eleve_id'],
                (int) $payload['sous_matiere_id'],
                $payload['valeur']
            );
            $saved = $this->notes->saveCell(
                (int) $payload['evaluation_id'],
                (int) $payload['eleve_id'],
                (int) $payload['sous_matiere_id'],
                $payload['valeur'] === null ? null : (float) $payload['valeur'],
                0,
                $userId
            );
            $result = ['saved' => $saved, 'evaluation_id' => (int) $payload['evaluation_id']];
            $this->db->update(
                'UPDATE assistant_action_requests
                 SET statut = "executed", executed_at = NOW(), result_json = :result
                 WHERE id = :id AND statut = "pending"',
                ['result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $requestId]
            );
            $this->db->insert(
                'INSERT INTO logs_activites
                    (utilisateur_id, action, table_concernee, enregistrement_id, description, adresse_ip, navigateur)
                 VALUES (:uid, :action, :table_name, :record_id, :description, :ip, :agent)',
                [
                    'uid' => $userId,
                    'action' => 'Assistant action confirmée',
                    'table_name' => 'notes',
                    'record_id' => null,
                    'description' => 'note.write exécutée après confirmation; request=' . $requestId
                        . '; evaluation=' . (int) $payload['evaluation_id']
                        . '; student=' . (int) $payload['eleve_id']
                        . '; subject=' . (int) $payload['sous_matiere_id'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
            $this->db->commit();
            return ['request_id' => $requestId, 'executed' => true, 'result' => $result];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Contrôle métier recalculé au moment de la proposition ET de l'exécution. */
    private function validateNoteTarget(int $evaluationId, int $studentId, int $subjectId, mixed $rawValue): void
    {
        if ($evaluationId <= 0 || $studentId <= 0 || $subjectId <= 0) {
            throw new InvalidArgumentException('Évaluation, élève et sous-matière sont obligatoires.');
        }
        $context = $this->notes->getEntryContext($evaluationId);
        if (!$context) {
            throw new DomainException('Évaluation inexistante ou incohérente.');
        }
        $this->assertStudentScope($studentId);
        if (hasRole('Enseignant') && !$this->notes->teacherHasClassAssignment(
            (int) $_SESSION['utilisateur_id'],
            (int) $context['classe_id'],
            (int) $context['annee_scolaire_id']
        )) {
            throw new DomainException('Cet enseignant n’est pas affecté à cette classe.');
        }
        $students = array_fill_keys($this->notes->getActiveStudentIds(
            (int) $context['classe_id'],
            (int) $context['annee_scolaire_id']
        ), true);
        if (!isset($students[$studentId])) {
            throw new DomainException('L’élève n’est pas inscrit activement dans cette classe/année.');
        }
        $maxima = $this->notes->getSubjectMaxima(
            (int) $context['classe_id'],
            (int) $context['groupe_id'],
            (string) $context['type_evaluation']
        );
        if (!array_key_exists($subjectId, $maxima)) {
            throw new DomainException('La sous-matière ne fait pas partie de cette évaluation.');
        }
        if ($rawValue === null || $rawValue === '' || !is_numeric($rawValue)
            || !is_finite((float) $rawValue)
            || (float) $rawValue < 0 || (float) $rawValue > (float) $maxima[$subjectId]) {
            throw new DomainException('Une note numérique comprise dans le barème est obligatoire.');
        }
    }
}
