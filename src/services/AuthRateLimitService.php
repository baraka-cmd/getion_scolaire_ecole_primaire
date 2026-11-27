<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AuthRateLimitRepository.php';

class AuthRateLimitService
{
    private const SCOPES = ['login', 'password_reset'];
    private AuthRateLimitRepository $repository;

    public function __construct(?Database $db = null, ?AuthRateLimitRepository $repository = null)
    {
        $this->repository = $repository ?? new AuthRateLimitRepository($db);
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    public function isBlocked(string $scope, string $subject, string $subjectType = 'ip'): bool
    {
        $this->validateKey($scope, $subjectType);
        return $this->repository->isBlocked($scope, $subjectType, hash('sha256', $subject));
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
        return $this->repository->recordFailure(
            $scope,
            $subjectType,
            hash('sha256', $subject),
            $maxAttempts,
            $windowSeconds,
            $blockSeconds
        );
    }

    public function clear(string $scope, string $subject, string $subjectType = 'ip'): void
    {
        $this->validateKey($scope, $subjectType);
        $this->repository->clear($scope, $subjectType, hash('sha256', $subject));
    }

    private function validateKey(string $scope, string $subjectType): void
    {
        if (!in_array($scope, self::SCOPES, true) || !in_array($subjectType, ['ip', 'email'], true)) {
            throw new InvalidArgumentException('Contexte de limitation invalide.');
        }
    }
}
