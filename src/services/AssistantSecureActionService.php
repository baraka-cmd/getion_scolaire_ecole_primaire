<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/EvaluationNoteService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';
require_once __DIR__ . '/AssistantSecurityAuditService.php';

/**
 * Actions à impact scolaire : proposition signée, validation backend,
 * confirmation explicite, exécution unique et journalisation.
 */
final class AssistantSecureActionService
{
    private Database $db;
    private Security $security;
    private EvaluationNoteService $notes;
    private StudentAccessPolicy $studentPolicy;
    private AssistantSecurityAuditService $audit;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->security = Security::getInstance();
        $this->notes = new EvaluationNoteService();
        $this->studentPolicy = new StudentAccessPolicy($this->db);
        $this->audit = new AssistantSecurityAuditService($this->db);
    }

    /** Prépare une note mais ne la modifie pas encore. Le token n'est rendu qu'une fois. */
    public function prepareGrade(int $userId, int $evaluationId, int $studentId, int $subjectId, mixed $rawValue, mixed $rawAbsence): array
    {
        $this->security->requirePermission('notes.saisir');
        $normalized = $this->validateGrade($userId, $evaluationId, $studentId, $subjectId, $rawValue, $rawAbsence);
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
        $payload = [
            'evaluation_id' => $evaluationId,
            'eleve_id' => $studentId,
            'sous_matiere_id' => $subjectId,
            'valeur' => $normalized['value'],
            'absence' => $normalized['absence'],
            'maximum' => $normalized['maximum'],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Impossible de préparer la demande.');
        }
        $this->db->insert(
            "INSERT INTO assistant_action_requests
                (utilisateur_id, action_code, target_type, target_id, payload_json, payload_sha256,
                 confirmation_token_hash, statut, expires_at)
             VALUES (:user_id, 'grades.store', 'evaluation_note', :target_id, :payload, :payload_hash,
                     :token_hash, 'draft', :expires)",
            [
                'user_id' => $userId,
                'target_id' => (string) $evaluationId,
                'payload' => $json,
                'payload_hash' => hash('sha256', $json),
                'token_hash' => hash('sha256', $token),
                'expires' => $expires,
            ]
        );
        $id = (int) $this->db->getPdo()->lastInsertId();
        $this->audit->record($userId, 'assistant.action.prepared', 'success', 'evaluation_note', (string) $id, [
            'action_code' => 'grades.store', 'evaluation_id' => $evaluationId,
            'student_id' => $studentId, 'subject_id' => $subjectId, 'maximum' => $normalized['maximum'],
        ]);
        return [
            'action_id' => $id,
            'confirmation_token' => $token,
            'expires_at' => $expires,
            'preview' => $payload,
            'requires_confirmation' => true,
        ];
    }

    /** Confirme et exécute une seule fois après revalidation de toute la politique métier. */
    public function confirmGrade(int $userId, int $actionId, string $token): array
    {
        $this->security->requirePermission('assistant.actions.confirmer');
        $this->security->requirePermission('notes.saisir');
        if ($actionId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Confirmation invalide.');
        }

        $this->db->beginTransaction();
        try {
            $request = $this->db->fetchOne(
                'SELECT * FROM assistant_action_requests WHERE id = :id AND utilisateur_id = :user_id FOR UPDATE',
                ['id' => $actionId, 'user_id' => $userId]
            );
            if (!$request || $request['statut'] !== 'draft') {
                throw new DomainException('Cette action est absente, déjà utilisée ou non confirmable.');
            }
            if (strtotime((string) $request['expires_at']) <= time()) {
                $this->db->update("UPDATE assistant_action_requests SET statut = 'expired', confirmation_token_hash = NULL WHERE id = :id", ['id' => $actionId]);
                throw new DomainException('La confirmation a expiré. Préparez une nouvelle demande.');
            }
            if (!is_string($request['confirmation_token_hash']) || !hash_equals($request['confirmation_token_hash'], hash('sha256', $token))) {
                $this->audit->record($userId, 'assistant.action.confirmation_denied', 'denied', 'assistant_action', (string) $actionId, ['reason' => 'token_mismatch']);
                throw new DomainException('Le code de confirmation est incorrect.');
            }
            $json = (string) $request['payload_json'];
            if (!hash_equals((string) $request['payload_sha256'], hash('sha256', $json))) {
                throw new DomainException('Le contenu de la demande a été altéré.');
            }
            $payload = json_decode($json, true);
            if (!is_array($payload) || $request['action_code'] !== 'grades.store') {
                throw new DomainException('Type d’action non pris en charge.');
            }

            // Contrôles d'objet et de règles métier rejoués au moment de la confirmation.
            $validated = $this->validateGrade(
                $userId,
                (int) $payload['evaluation_id'],
                (int) $payload['eleve_id'],
                (int) $payload['sous_matiere_id'],
                $payload['valeur'],
                $payload['absence']
            );
            if ((float) $validated['maximum'] !== (float) $payload['maximum']) {
                throw new DomainException('Le barème a changé depuis la préparation. Préparez une nouvelle demande.');
            }

            $this->notes->saveCell(
                (int) $payload['evaluation_id'],
                (int) $payload['eleve_id'],
                (int) $payload['sous_matiere_id'],
                $validated['value'],
                $validated['absence'],
                $userId
            );
            $this->db->update(
                "UPDATE assistant_action_requests
                 SET statut = 'executed', confirmed_at = NOW(), executed_at = NOW(), confirmation_token_hash = NULL
                 WHERE id = :id AND statut = 'draft'",
                ['id' => $actionId]
            );
            $this->audit->record($userId, 'assistant.action.grade.executed', 'success', 'evaluation_note', (string) $actionId, [
                'evaluation_id' => (int) $payload['evaluation_id'],
                'student_id' => (int) $payload['eleve_id'],
                'subject_id' => (int) $payload['sous_matiere_id'],
                'absence' => $validated['absence'],
                'maximum' => $validated['maximum'],
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        logActivity('Saisie note par assistant', 'notes', null, 'Action confirmée #' . $actionId);
        return ['action_id' => $actionId, 'status' => 'executed', 'message' => 'La note a été enregistrée après confirmation et contrôles backend.'];
    }

    private function validateGrade(int $userId, int $evaluationId, int $studentId, int $subjectId, mixed $rawValue, mixed $rawAbsence): array
    {
        if ($evaluationId <= 0 || $studentId <= 0 || $subjectId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Élève, évaluation et sous-matière sont obligatoires.');
        }
        if (!$this->studentPolicy->canCurrentUserAccessStudent($studentId)) {
            throw new DomainException('L’accès à cet élève est refusé.');
        }
        $evaluation = $this->notes->getEntryContext($evaluationId);
        if (!$evaluation) {
            throw new DomainException('Évaluation introuvable.');
        }
        if (hasRole('Enseignant')) {
            if (!$this->notes->teacherHasClassAssignment($userId, (int) $evaluation['classe_id'], (int) $evaluation['annee_scolaire_id'])) {
                throw new DomainException('Affectation enseignant-classe non autorisée.');
            }
        } elseif (!$this->security->can('notes.saisir')) {
            throw new DomainException('Permission de saisie refusée.');
        }

        $activeIds = array_fill_keys($this->notes->getActiveStudentIds((int) $evaluation['classe_id'], (int) $evaluation['annee_scolaire_id']), true);
        if (!isset($activeIds[$studentId])) {
            throw new DomainException('Cet élève n’est pas actif dans la classe de cette évaluation.');
        }
        $maxima = $this->notes->getSubjectMaxima((int) $evaluation['classe_id'], (int) $evaluation['groupe_id'], (string) $evaluation['type_evaluation']);
        if (!array_key_exists($subjectId, $maxima)) {
            throw new DomainException('La sous-matière ne fait pas partie de cette évaluation.');
        }
        if (!in_array($rawAbsence, [0, 1, '0', '1', false, true], true)) {
            throw new InvalidArgumentException('Indicateur d’absence invalide.');
        }
        $absence = (int) (bool) $rawAbsence;
        if ($rawValue === '' || $rawValue === null) {
            $value = null;
        } elseif (is_numeric($rawValue) && is_finite((float) $rawValue)) {
            $value = (float) $rawValue;
        } else {
            throw new InvalidArgumentException('Valeur de note invalide.');
        }
        $maximum = (float) $maxima[$subjectId];
        if ($value !== null && ($value < 0 || $value > $maximum)) {
            throw new DomainException('La note doit être comprise entre 0 et ' . $maximum . '.');
        }
        if ($absence === 1 && $value !== null) {
            throw new DomainException('Une absence ne peut pas avoir de note numérique.');
        }
        if ($value === null && $absence === 0) {
            throw new DomainException('Précisez une note ou marquez l’élève absent.');
        }
        return ['value' => $value, 'absence' => $absence, 'maximum' => $maximum];
    }
}
