<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AuthRepository.php';

class AuthAccountService
{
    private AuthRepository $repository;

    public function __construct(?AuthRepository $repository = null)
    {
        $this->repository = $repository ?? new AuthRepository();
    }


    public function emailExists(string $email): bool
    {
        return $this->repository->emailExists($email);
    }

    public function createRegisteredUser(array $data): int
    {
        return $this->repository->createRegisteredUser($data);
    }

    public function logFailedAttempt(?int $userId, string $email, string $ip, string $browser): void
    {
        $this->repository->logFailedAttempt($userId, $email, $ip, $browser);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->repository->findByEmail($email);
    }

    public function findByGoogleId(string $googleId): ?array
    {
        return $this->repository->findByGoogleId($googleId);
    }

    public function findByEmailForGoogle(string $email): ?array
    {
        return $this->repository->findByEmailForGoogle($email);
    }

    public function linkGoogleId(int $userId, string $googleId): void
    {
        $this->repository->linkGoogleId($userId, $googleId);
    }

    public function loginExists(string $login): bool
    {
        return $this->repository->loginExists($login);
    }

    public function createGoogleUser(array $data): int
    {
        return $this->repository->createGoogleUser($data);
    }

    public function recordSuccessfulLogin(int $userId, string $ip): void
    {
        $this->repository->updateSuccessfulLogin($userId, $ip);
    }

    public function recordFailedLogin(int $userId, int $attempts, ?string $blockedUntil): void
    {
        $this->repository->updateLoginAttempts($userId, $attempts, $blockedUntil);
    }

    public function resetLoginAttempts(int $userId): void
    {
        $this->repository->resetLoginAttempts($userId);
    }

    public function updatePasswordHash(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);
        $this->repository->updatePasswordHash($userId, $hash);
    }
}
