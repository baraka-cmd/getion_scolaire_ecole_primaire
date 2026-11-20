<?php

defined('ACCESS') or die('Accès interdit');

class AuthRateLimitService
{
    private const SCOPES = ['login', 'password_reset'];

    public function __construct(private Database $db)
    {
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    public function isBlocked(string $scope, string $subject, string $subjectType = 'ip'): bool
    {
        $this->validateKey($scope, $subjectType);
        return (bool) $this->db->fetchColumn(
            'SELECT EXISTS (
                SELECT 1 FROM auth_rate_limits
                WHERE scope = :scope AND subject_type = :subject_type
                  AND subject_hash = :subject_hash AND blocked_until > NOW()
             )',
            [
                'scope' => $scope,
                'subject_type' => $subjectType,
                'subject_hash' => hash('sha256', $subject)
            ]
        );
    }

    public function recordFailure(
        string $scope,
        string $subject,
        int $maxAttempts = 10,
        int $windowSeconds = 900,
        int $blockSeconds = 900,
        string $subjectType = 'ip'
    ): bool {
        $this->validateKey($scope, $subjectType);
        $subjectHash = hash('sha256', $subject);

        $this->db->beginTransaction();
        try {
            $this->db->query(
                "INSERT IGNORE INTO auth_rate_limits
                    (scope, subject_type, subject_hash, failed_attempts, window_started_at)
                 VALUES (:scope, :subject_type, :subject_hash, 0, NOW())",
                ['scope' => $scope, 'subject_type' => $subjectType, 'subject_hash' => $subjectHash]
            );
            $row = $this->db->fetchOne(
                'SELECT failed_attempts, window_started_at
                 FROM auth_rate_limits
                 WHERE scope = :scope AND subject_type = :subject_type
                   AND subject_hash = :subject_hash
                 FOR UPDATE',
                ['scope' => $scope, 'subject_type' => $subjectType, 'subject_hash' => $subjectHash]
            );
            if (!$row) {
                throw new RuntimeException('Impossible de réserver le compteur de limitation.');
            }

            $windowStartedAt = strtotime((string) $row['window_started_at']);
            $attempts = $windowStartedAt < time() - $windowSeconds
                ? 1
                : (int) $row['failed_attempts'] + 1;
            $blockedUntil = $attempts >= $maxAttempts
                ? date('Y-m-d H:i:s', time() + $blockSeconds)
                : null;

            $this->db->update(
                'UPDATE auth_rate_limits
                 SET failed_attempts = :attempts,
                     window_started_at = CASE
                         WHEN :reset_window = 1 THEN NOW()
                         ELSE window_started_at
                     END,
                     blocked_until = :blocked_until
                 WHERE scope = :scope AND subject_type = :subject_type
                   AND subject_hash = :subject_hash',
                [
                    'attempts' => $attempts,
                    'reset_window' => $windowStartedAt < time() - $windowSeconds ? 1 : 0,
                    'blocked_until' => $blockedUntil,
                    'scope' => $scope,
                    'subject_type' => $subjectType,
                    'subject_hash' => $subjectHash
                ]
            );
            $this->db->query(
                'DELETE FROM auth_rate_limits
                 WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                 LIMIT 500'
            );
            $this->db->commit();
            return $blockedUntil !== null;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function clear(string $scope, string $subject, string $subjectType = 'ip'): void
    {
        $this->validateKey($scope, $subjectType);
        $this->db->delete(
            'DELETE FROM auth_rate_limits
             WHERE scope = :scope AND subject_type = :subject_type AND subject_hash = :subject_hash',
            [
                'scope' => $scope,
                'subject_type' => $subjectType,
                'subject_hash' => hash('sha256', $subject)
            ]
        );
    }

    private function validateKey(string $scope, string $subjectType): void
    {
        if (!in_array($scope, self::SCOPES, true) || !in_array($subjectType, ['ip', 'email'], true)) {
            throw new InvalidArgumentException('Contexte de limitation invalide.');
        }
    }
}
