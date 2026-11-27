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
