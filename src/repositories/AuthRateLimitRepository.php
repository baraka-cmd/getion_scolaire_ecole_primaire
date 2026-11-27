<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AuthRateLimitRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function isBlocked(string $scope, string $subjectType, string $subjectHash): bool
    {
        return (bool) $this->db->fetchColumn(
            'SELECT EXISTS (
                SELECT 1 FROM auth_rate_limits
                WHERE scope = :scope AND subject_type = :subject_type
                  AND subject_hash = :subject_hash AND blocked_until > NOW()
             )',
            ['scope' => $scope, 'subject_type' => $subjectType, 'subject_hash' => $subjectHash]
        );
    }

    public function recordFailure(
        string $scope,
        string $subjectType,
        string $subjectHash,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds
    ): bool {
        $this->db->beginTransaction();
        try {
            $this->db->query(
                'INSERT IGNORE INTO auth_rate_limits
                    (scope, subject_type, subject_hash, failed_attempts, window_started_at)
                 VALUES (:scope, :subject_type, :subject_hash, 0, NOW())',
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
            $resetWindow = $windowStartedAt < time() - $windowSeconds;
            $attempts = $resetWindow ? 1 : (int) $row['failed_attempts'] + 1;
            $blockedUntil = $attempts >= $maxAttempts
                ? date('Y-m-d H:i:s', time() + $blockSeconds)
                : null;

            $this->db->update(
                'UPDATE auth_rate_limits
                 SET failed_attempts = :attempts,
                     window_started_at = CASE WHEN :reset_window = 1 THEN NOW() ELSE window_started_at END,
                     blocked_until = :blocked_until
                 WHERE scope = :scope AND subject_type = :subject_type
                   AND subject_hash = :subject_hash',
                [
                    'attempts' => $attempts,
                    'reset_window' => $resetWindow ? 1 : 0,
                    'blocked_until' => $blockedUntil,
                    'scope' => $scope,
                    'subject_type' => $subjectType,
                    'subject_hash' => $subjectHash,
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
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function clear(string $scope, string $subjectType, string $subjectHash): void
    {
        $this->db->delete(
            'DELETE FROM auth_rate_limits
             WHERE scope = :scope AND subject_type = :subject_type AND subject_hash = :subject_hash',
            ['scope' => $scope, 'subject_type' => $subjectType, 'subject_hash' => $subjectHash]
        );
    }
}
