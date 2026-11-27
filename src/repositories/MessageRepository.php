<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class MessageRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function recipientsForAll(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id FROM utilisateurs WHERE est_actif = 1 AND id != :uid',
            ['uid' => $userId]
        );
    }

    public function recipientsForRole(string $roleName, int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT u.id FROM utilisateurs u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE r.nom = :role AND u.est_actif = 1 AND u.id != :uid",
            ['role' => $roleName, 'uid' => $userId]
        );
    }

    public function recipientsForClass(int $classId, int $userId): array
    {
        $recipients = $this->db->fetchAll(
            "SELECT DISTINCT u.id FROM utilisateurs u
             INNER JOIN parents_tuteurs pt ON u.id = pt.utilisateur_id
             INNER JOIN eleve_parent ep ON pt.id = ep.parent_id
             INNER JOIN inscriptions i ON ep.eleve_id = i.eleve_id
             INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id
             WHERE i.classe_id = :cid
               AND i.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
               AND i.statut = 'Actif' AND u.est_actif = 1 AND u.id != :uid",
            ['cid' => $classId, 'uid' => $userId]
        );
        $teacherId = $this->db->fetchColumn(
            "SELECT u.id FROM utilisateurs u
             INNER JOIN enseignants e ON u.id = e.utilisateur_id
             WHERE e.classe_id = :cid AND e.est_actif = 1 AND u.est_actif = 1 AND u.id != :uid",
            ['cid' => $classId, 'uid' => $userId]
        );
        if ($teacherId) {
            $recipients[] = ['id' => (int) $teacherId];
        }
        return $recipients;
    }

    public function create(int $senderId, int $recipientId, string $subject, string $content, ?int $parentId): int
    {
        return (int) $this->db->insert(
            'INSERT INTO messages
                (expediteur_id, destinataire_id, sujet, contenu, message_parent_id, date_envoi)
             VALUES (:sender, :recipient, :subject, :content, :parent, NOW())',
            [
                'sender' => $senderId,
                'recipient' => $recipientId,
                'subject' => $subject,
                'content' => $content,
                'parent' => $parentId,
            ]
        );
    }

    public function searchUsers(int $userId, string $search): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.nom, u.prenom, u.login, r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.est_actif = 1 AND u.id != :uid
               AND (CONCAT(u.prenom, ' ', u.nom) LIKE :r1 OR u.login LIKE :r2 OR r.nom LIKE :r3)
             ORDER BY u.nom
             LIMIT 20",
            ['uid' => $userId, 'r1' => '%' . $search . '%', 'r2' => '%' . $search . '%', 'r3' => '%' . $search . '%']
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM messages
             WHERE destinataire_id = :uid AND est_lu = 0
               AND est_supprime_destinataire = 0 AND est_archive_destinataire = 0',
            ['uid' => $userId]
        );
    }
}
