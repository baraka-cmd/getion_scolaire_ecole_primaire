<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/UserRepository.php';

class UserAccountService
{
    private UserRepository $repository;

    public function __construct(?UserRepository $repository = null)
    {
        $this->repository = $repository ?? new UserRepository();
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->repository->emailExists($email, $excludeId);
    }

    public function loginExists(string $login, ?int $excludeId = null): bool
    {
        return $this->repository->loginExists($login, $excludeId);
    }

    public function findIdentity(int $userId): ?array
    {
        return $this->repository->findIdentity($userId);
    }

    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    public function update(int $userId, array $data): void
    {
        $this->repository->update($userId, $data);
    }

    public function delete(int $userId): void
    {
        $this->repository->delete($userId);
    }

    public function toggleActive(int $userId): array
    {
        $user = $this->repository->findIdentity($userId);
        if (!$user) {
            throw new DomainException('Utilisateur introuvable.');
        }
        $active = (int) $user['est_actif'] === 1 ? 0 : 1;
        $this->repository->setActive($userId, $active);
        return ['user' => $user, 'active' => $active];
    }

    public function setPassword(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);
        $this->repository->setPassword($userId, $hash);
    }

    public function getLogs(int $userId, int $limit = 50): array
    {
        return $this->repository->getLogs($userId, $limit);
    }
}
