<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class PasswordResetRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findActiveUserByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nom, prenom, email FROM utilisateurs
             WHERE email = :email AND est_actif = 1 LIMIT 1',
            ['email' => $email]
        );
    }

    public function saveToken(int $userId, string $tokenHash): void
    {
        $this->db->query(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
             ON DUPLICATE KEY UPDATE
                 token_hash = VALUES(token_hash),
                 expires_at = VALUES(expires_at),
                 created_at = NOW()',
            ['user_id' => $userId, 'token_hash' => $tokenHash]
        );
    }

    public function deleteToken(int $userId, string $tokenHash): void
    {
        $this->db->delete(
            'DELETE FROM password_reset_tokens WHERE user_id = :user_id AND token_hash = :token_hash',
            ['user_id' => $userId, 'token_hash' => $tokenHash]
        );
    }

    public function findValidToken(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND expires_at > NOW()',
            ['token_hash' => $tokenHash]
        );
    }

    public function consumeTokenAndUpdatePassword(string $tokenHash, string $passwordHash): bool
    {
        try {
            $this->db->beginTransaction();
            $reset = $this->db->fetchOne(
                'SELECT user_id FROM password_reset_tokens
                 WHERE token_hash = :token_hash AND expires_at > NOW()
                 FOR UPDATE',
                ['token_hash' => $tokenHash]
            );
            if (!$reset) {
                $this->db->rollback();
                return false;
            }

            $updated = $this->db->update(
                'UPDATE utilisateurs
                 SET mot_de_passe = :password, tentatives_echecs = 0, bloque_jusqua = NULL
                 WHERE id = :user_id AND est_actif = 1',
                ['password' => $passwordHash, 'user_id' => $reset['user_id']]
            );
            if ($updated !== 1) {
                $this->db->rollback();
                return false;
            }

            $this->db->delete(
                'DELETE FROM password_reset_tokens WHERE user_id = :user_id',
                ['user_id' => $reset['user_id']]
            );
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
}
