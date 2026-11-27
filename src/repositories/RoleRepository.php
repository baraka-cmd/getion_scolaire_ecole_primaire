<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class RoleRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }


    public function findById(int $roleId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nom, description, niveau_acces, est_actif FROM roles WHERE id = :id',
            ['id' => $roleId]
        );
    }

    public function getRolePermissions(int $roleId): array
    {
        return $this->db->fetchAll(
            "SELECT p.id, p.code, p.libelle, p.module, p.action, p.description
             FROM permissions p
             INNER JOIN role_permission rp ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
             ORDER BY p.module, p.action",
            ['role_id' => $roleId]
        );
    }

    public function getAllPermissions(): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, libelle, module, action, description
             FROM permissions
             ORDER BY module, action'
        );
    }

    public function getAllRolesWithPermissionCounts(): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.nom, r.description, r.niveau_acces, r.est_actif,
                    COUNT(rp.id) as nb_permissions
             FROM roles r
             LEFT JOIN role_permission rp ON r.id = rp.role_id
             GROUP BY r.id, r.nom, r.description, r.niveau_acces, r.est_actif
             ORDER BY r.niveau_acces DESC"
        );
    }


    /**
     * Remplace les permissions d'un rôle atomiquement.
     * En cas d'échec, les associations précédentes restent intactes.
     */
    public function replacePermissions(int $roleId, array $permissionIds): bool
    {
        try {
            $this->db->beginTransaction();
            $this->db->delete(
                'DELETE FROM role_permission WHERE role_id = :role_id',
                ['role_id' => $roleId]
            );

            foreach ($permissionIds as $permissionId) {
                $this->db->query(
                    'INSERT INTO role_permission (role_id, permission_id) VALUES (:role_id, :perm_id)',
                    ['role_id' => $roleId, 'perm_id' => (int) $permissionId]
                );
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Erreur replaceRolePermissions: ' . $e->getMessage());
            return false;
        }
    }

    public function existsByName(string $name): bool
    {
        return $this->db->exists(
            'SELECT id FROM roles WHERE nom = :nom',
            ['nom' => $name]
        );
    }

    public function countUsers(int $roleId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM utilisateurs WHERE role_id = :role_id',
            ['role_id' => $roleId]
        );
    }
}
