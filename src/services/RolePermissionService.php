<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/RoleRepository.php';
require_once __DIR__ . '/../core/Security.php';

class RolePermissionService
{
    private RoleRepository $repository;
    private Security $security;

    public function __construct(?RoleRepository $repository = null, ?Security $security = null)
    {
        $this->repository = $repository ?? new RoleRepository();
        $this->security = $security ?? Security::getInstance();
    }

    public function getRole(int $roleId): ?array
    {
        return $this->repository->findById($roleId);
    }

    public function getRolePermissions(int $roleId): array
    {
        return $this->repository->getRolePermissions($roleId);
    }

    public function getAllPermissionsGrouped(): array
    {
        $permissions = $this->repository->getAllPermissions();
        $grouped = [];
        foreach ($permissions as $permission) {
            $module = $permission['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = ['module' => $module, 'permissions' => []];
            }
            $grouped[$module]['permissions'][] = $permission;
        }
        return array_values($grouped);
    }

    public function updatePermissions(int $roleId, array $permissionIds): bool
    {
        return $this->security->updateRolePermissions($roleId, $permissionIds);
    }

    public function createRole(string $name, string $description, int $accessLevel): ?int
    {
        if ($this->repository->existsByName($name)) {
            throw new DomainException('Ce rôle existe déjà.');
        }
        return $this->security->createRole($name, $description, $accessLevel);
    }

    public function countUsers(int $roleId): int
    {
        return $this->repository->countUsers($roleId);
    }

    public function nameExists(string $name): bool
    {
        return $this->repository->existsByName($name);
    }

    public function deleteRole(int $roleId): bool
    {
        return $this->security->deleteRole($roleId);
    }

    public function assignPermission(int $roleId, int $permissionId): bool
    {
        return $this->security->assignPermission($roleId, $permissionId);
    }

    public function revokePermission(int $roleId, int $permissionId): bool
    {
        return $this->security->revokePermission($roleId, $permissionId);
    }

    public function getAllRoles(): array
    {
        return $this->repository->getAllRolesWithPermissionCounts();
    }
}
