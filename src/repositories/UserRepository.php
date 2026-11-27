<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class UserRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM utilisateurs WHERE email = :email';
        $params = ['email' => mb_strtolower(trim($email))];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude';
            $params['exclude'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params) > 0;
    }

    public function loginExists(string $login, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM utilisateurs WHERE login = :login';
        $params = ['login' => mb_strtolower(trim($login))];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude';
            $params['exclude'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params) > 0;
    }

    public function findIdentity(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, role_id, est_actif, nom, prenom FROM utilisateurs WHERE id = :id',
            ['id' => $userId]
        );
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert(
            'INSERT INTO utilisateurs
                (nom, prenom, email, telephone, login, mot_de_passe, role_id, est_actif)
             VALUES (:nom, :prenom, :email, :telephone, :login, :password, :role_id, 1)',
            $data
        );
    }

    public function update(int $userId, array $data): void
    {
        $data['id'] = $userId;
        $this->db->update(
            'UPDATE utilisateurs
             SET nom = :nom, prenom = :prenom, email = :email, telephone = :telephone,
                 login = :login, role_id = :role_id
             WHERE id = :id',
            $data
        );
    }

    public function delete(int $userId): void
    {
        $this->db->delete('DELETE FROM utilisateurs WHERE id = :id', ['id' => $userId]);
    }

    public function setActive(int $userId, int $active): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET est_actif = :statut WHERE id = :id',
            ['statut' => $active, 'id' => $userId]
        );
    }

    public function setPassword(int $userId, string $hash): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET mot_de_passe = :password WHERE id = :id',
            ['password' => $hash, 'id' => $userId]
        );
    }

    public function getLogs(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT action, table_concernee, description, date_action, adresse_ip
             FROM logs_activites
             WHERE utilisateur_id = :uid
             ORDER BY date_action DESC
             LIMIT :limit',
            ['uid' => $userId, 'limit' => $limit]
        );
    }
}
