<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AuthRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }


    public function emailExists(string $email): bool
    {
        return $this->db->exists(
            'SELECT id FROM utilisateurs WHERE email = :email',
            ['email' => $email]
        );
    }

    public function createRegisteredUser(array $data): int
    {
        try {
            $this->db->beginTransaction();
            $userId = (int) $this->db->insert(
                'INSERT INTO utilisateurs (nom, prenom, email, telephone, login, mot_de_passe, role_id, est_actif)
                 VALUES (:nom, :prenom, :email, :telephone, :login, :password, :role_id, 1)',
                $data
            );
            $this->db->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function logFailedAttempt(?int $userId, string $email, string $ip, string $browser): void
    {
        $this->db->query(
            "INSERT INTO logs_activites (utilisateur_id, action, description, adresse_ip, navigateur)
             VALUES (:uid, 'Tentative échouée', :description, :ip, :navigateur)",
            [
                'uid' => $userId,
                'description' => 'Tentative de connexion avec empreinte email: ' . hash('sha256', mb_strtolower(trim($email))),
                'ip' => $ip,
                'navigateur' => $browser,
            ]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.id, u.nom, u.prenom, u.email, u.login, u.mot_de_passe,
                    u.photo, u.est_actif, u.tentatives_echecs, u.bloque_jusqua, u.role_id,
                    r.nom AS role_nom, r.niveau_acces
             FROM utilisateurs u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.email = :email
             LIMIT 1",
            ['email' => mb_strtolower(trim($email))]
        );
    }

    public function findByGoogleId(string $googleId): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.id, u.nom, u.prenom, u.email, u.login, u.google_id, u.photo, u.est_actif,
                    u.tentatives_echecs, u.bloque_jusqua, u.role_id,
                    r.nom AS role_nom, r.niveau_acces
             FROM utilisateurs u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.google_id = :google_id LIMIT 1",
            ['google_id' => $googleId]
        );
    }

    public function findByEmailForGoogle(string $email): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.id, u.nom, u.prenom, u.email, u.login, u.google_id, u.photo, u.est_actif,
                    u.tentatives_echecs, u.bloque_jusqua, u.role_id,
                    r.nom AS role_nom, r.niveau_acces
             FROM utilisateurs u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.email = :email LIMIT 1",
            ['email' => mb_strtolower(trim($email))]
        );
    }

    public function linkGoogleId(int $userId, string $googleId): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET google_id = :google_id WHERE id = :id',
            ['google_id' => $googleId, 'id' => $userId]
        );
    }

    public function loginExists(string $login): bool
    {
        return $this->db->exists(
            'SELECT id FROM utilisateurs WHERE login = :login',
            ['login' => $login]
        );
    }

    public function createGoogleUser(array $data): int
    {
        return (int) $this->db->insert(
            'INSERT INTO utilisateurs
                (nom, prenom, email, login, google_id, photo, role_id, est_actif)
             VALUES (:nom, :prenom, :email, :login, :google_id, :photo, :role_id, 1)',
            $data
        );
    }

    public function updateSuccessfulLogin(int $userId, string $ip): void
    {
        $this->db->update(
            'UPDATE utilisateurs
             SET derniere_connexion = NOW(), derniere_ip = :ip,
                 tentatives_echecs = 0, bloque_jusqua = NULL
             WHERE id = :id',
            ['ip' => $ip, 'id' => $userId]
        );
    }

    public function updateLoginAttempts(int $userId, int $attempts, ?string $blockedUntil): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET tentatives_echecs = :tentatives, bloque_jusqua = :bloque WHERE id = :id',
            ['tentatives' => $attempts, 'bloque' => $blockedUntil, 'id' => $userId]
        );
    }

    public function resetLoginAttempts(int $userId): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET tentatives_echecs = 0, bloque_jusqua = NULL WHERE id = :id',
            ['id' => $userId]
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->update(
            'UPDATE utilisateurs SET mot_de_passe = :hash WHERE id = :id',
            ['hash' => $hash, 'id' => $userId]
        );
    }
}
