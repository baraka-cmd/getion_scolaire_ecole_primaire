<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/FinanceService.php';
require_once __DIR__ . '/../src/services/UserAccountService.php';
require_once __DIR__ . '/../src/services/AuthAccountService.php';
require_once __DIR__ . '/../src/services/RolePermissionService.php';

if (!FinanceService::estFraisScolarite('Scolarité')) {
    throw new RuntimeException('Le frais de scolarité n’a pas été reconnu.');
}
if (FinanceService::estFraisScolarite('Transport')) {
    throw new RuntimeException('Un frais hors scolarité a été reconnu à tort.');
}
if (FinanceService::convertirMontant(10, 'USD', 'CDF', 2000) !== 20000.0) {
    throw new RuntimeException('La conversion USD vers CDF est incorrecte.');
}

final class InMemoryUserRepository extends UserRepository
{
    public array $users = [];
    public array $calls = [];

    public function __construct() {}

    public function findIdentity(int $userId): ?array
    {
        return $this->users[$userId] ?? null;
    }

    public function setActive(int $userId, int $active): void
    {
        $this->calls[] = ['active', $userId, $active];
    }

    public function getLogs(int $userId, int $limit = 50): array
    {
        return [['action' => 'Connexion', 'utilisateur_id' => $userId, 'limit' => $limit]];
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return false;
    }

    public function loginExists(string $login, ?int $excludeId = null): bool
    {
        return false;
    }
}

$userRepository = new InMemoryUserRepository();
$userRepository->users[12] = ['id' => 12, 'est_actif' => 1, 'nom' => 'Test', 'prenom' => 'User'];
$userService = new UserAccountService($userRepository);
$toggle = $userService->toggleActive(12);
if ($toggle['active'] !== 0 || $userRepository->calls !== [['active', 12, 0]]) {
    throw new RuntimeException('Le basculement du statut utilisateur est incorrect.');
}
if (count($userService->getLogs(12, 10)) !== 1) {
    throw new RuntimeException('Les logs utilisateur ne sont pas délgués.');
}

fwrite(STDOUT, "Finance, auth and user domain service smoke tests passed.\n");
