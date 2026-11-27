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

    public function createTransactional(array $data): int
    {
        return $this->repository->createTransactional($data);
    }

    public function getActiveRoles(): array
    {
        return $this->repository->getActiveRoles();
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


    /**
     * Prépare les données de la liste administrative sans exposer de SQL au contrôleur.
     * Les paramètres et le format de sortie restent ceux du contrat AJAX existant.
     */
    public function getAdminList(
        int $page,
        int $perPage,
        string $search,
        string $role,
        string $status,
        string $order,
        string $direction
    ): array {
        $result = $this->repository->getAdminList(
            $search,
            $role,
            $status,
            $order,
            $direction,
            $perPage,
            ($page - 1) * $perPage
        );

        foreach ($result['users'] as &$user) {
            $user['derniere_connexion'] = $user['derniere_connexion']
                ? formatDateTime($user['derniere_connexion'])
                : 'Jamais';
            $user['date_creation'] = formatDate($user['created_at']);
            $user['initials'] = mb_strtoupper(
                mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1)
            );
        }
        unset($user);

        return ['total' => $result['total'], 'utilisateurs' => $result['users']];
    }

    public function getAdminDetails(int $userId): ?array
    {
        $user = $this->repository->findAdminDetails($userId);
        if (!$user) {
            return null;
        }

        $user['derniere_connexion'] = $user['derniere_connexion']
            ? formatDateTime($user['derniere_connexion'])
            : 'Jamais';
        $user['date_creation'] = formatDate($user['created_at']);

        return $user;
    }

    public function getAuthenticationHistory(int $userId): array
    {
        return $this->repository->getAuthenticationHistory($userId);
    }

    public function getLogs(int $userId, int $limit = 50): array
    {
        return $this->repository->getLogs($userId, $limit);
    }
}
