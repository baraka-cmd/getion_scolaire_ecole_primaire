<?php

defined('ACCESS') or die('Accès interdit');

/** Journal append-only pour événements de sécurité assistant. */
final class AssistantSecurityAuditService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function record(
        ?int $actorId,
        string $eventCode,
        string $outcome,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        ?string $requestId = null
    ): void {
        if (!in_array($outcome, ['success', 'denied', 'error'], true)) {
            throw new InvalidArgumentException('Résultat d’audit invalide.');
        }
        $encoded = json_encode($this->sanitize($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = $ip !== '' ? hash('sha256', $ip . '|' . session_id()) : null;

        $this->db->insert(
            'INSERT INTO assistant_security_audit
                (actor_user_id, event_code, target_type, target_id, outcome, request_id, details_json, ip_hash)
             VALUES (:actor, :event, :target_type, :target_id, :outcome, :request_id, :details, :ip_hash)',
            [
                'actor' => $actorId && $actorId > 0 ? $actorId : null,
                'event' => mb_substr($eventCode, 0, 100, 'UTF-8'),
                'target_type' => $targetType ? mb_substr($targetType, 0, 80, 'UTF-8') : null,
                'target_id' => $targetId ? mb_substr($targetId, 0, 100, 'UTF-8') : null,
                'outcome' => $outcome,
                'request_id' => $requestId ? mb_substr($requestId, 0, 64, 'UTF-8') : null,
                'details' => $encoded === false ? '{}' : $encoded,
                'ip_hash' => $ipHash,
            ]
        );
    }

    private function sanitize(array $details): array
    {
        $safe = [];
        foreach ($details as $key => $value) {
            $name = strtolower((string) $key);
            if (preg_match('/password|token|secret|authorization|note_value|raw_payload|email|phone|address/', $name)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[mb_substr((string) $key, 0, 64, 'UTF-8')] = is_string($value)
                    ? mb_substr($value, 0, 300, 'UTF-8')
                    : $value;
            }
        }
        return $safe;
    }
}
