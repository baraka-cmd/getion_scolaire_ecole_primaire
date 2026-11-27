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

    public function findActiveSessionIdentity(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT u.role_id, r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.est_actif = 1 AND r.est_actif = 1',
            ['id' => $userId]
        );
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


    public function createTransactional(array $data): int
    {
        try {
            $this->db->beginTransaction();
            $userId = $this->create($data);
            $this->db->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function getActiveRoles(): array
    {
        return $this->db->fetchAll(
            'SELECT id, nom, description FROM roles WHERE est_actif = 1 ORDER BY niveau_acces DESC'
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


    /**
     * Recherche et pagination des utilisateurs pour l'administration.
     * Les colonnes de tri proviennent d'une liste blanche, jamais de SQL utilisateur.
     */
    public function getAdminList(
        string $search,
        string $role,
        string $status,
        string $order,
        string $direction,
        int $limit,
        int $offset
    ): array {
        $where = [];
        $params = [];

        if (!empty($search)) {
            $where[] = "(u.nom LIKE :recherche1 OR u.prenom LIKE :recherche2 OR u.email LIKE :recherche3 OR u.login LIKE :recherche4)";
            $params['recherche1'] = "%{$search}%";
            $params['recherche2'] = "%{$search}%";
            $params['recherche3'] = "%{$search}%";
            $params['recherche4'] = "%{$search}%";
        }

        if (!empty($role)) {
            $where[] = "u.role_id = :role";
            $params['role'] = (int) $role;
        }

        if ($status !== '') {
            $where[] = "u.est_actif = :statut";
            $params['statut'] = (int) $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $allowedColumns = [
            'id' => 'u.id',
            'nom' => 'u.nom',
            'prenom' => 'u.prenom',
            'email' => 'u.email',
            'login' => 'u.login',
            'role_nom' => 'r.nom',
            'derniere_connexion' => 'u.derniere_connexion',
            'est_actif' => 'u.est_actif',
        ];
        $order = $allowedColumns[$order] ?? $allowedColumns['nom'];
        $direction = in_array(strtoupper($direction), ['ASC', 'DESC'], true)
            ? strtoupper($direction)
            : 'ASC';

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM utilisateurs u
             LEFT JOIN roles r ON u.role_id = r.id
             {$whereClause}",
            $params
        );

        $users = $this->db->fetchAll(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.login, u.photo,
                    u.est_actif, u.derniere_connexion, u.created_at,
                    COALESCE(r.nom, 'Rôle inconnu') AS role_nom, r.id AS role_id
             FROM utilisateurs u
             LEFT JOIN roles r ON u.role_id = r.id
             {$whereClause}
             ORDER BY {$order} {$direction}, u.id ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );

        return ['total' => $total, 'users' => $users];
    }

    public function findAdminDetails(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.login, u.photo,
                    u.est_actif, u.derniere_connexion, u.created_at,
                    u.role_id, r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.id = :id",
            ['id' => $userId]
        );
    }

    public function getAuthenticationHistory(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT action, description, date_action
             FROM logs_activites
             WHERE utilisateur_id = :uid AND action IN ('Connexion', 'Tentative échouée', 'Déconnexion')
             ORDER BY date_action DESC
             LIMIT 20",
            ['uid' => $userId]
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
