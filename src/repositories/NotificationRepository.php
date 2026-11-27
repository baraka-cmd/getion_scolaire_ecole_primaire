<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class NotificationRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findCampaign(int $creatorId, string $requestKey): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nb_destinataires FROM notification_campaigns
             WHERE created_by = :creator AND request_key = :request_key',
            ['creator' => $creatorId, 'request_key' => $requestKey]
        );
    }

    public function getActiveYearId(): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1'
        );
    }

    public function activeClassExists(int $classId, int $yearId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM classes WHERE id = :class_id AND annee_scolaire_id = :year_id',
            ['class_id' => $classId, 'year_id' => $yearId]
        ) !== null;
    }

    public function findRecipients(int $yearId, string $targetType, int $classId, int $parentId): array
    {
        $params = ['year_id' => $yearId];
        $classCondition = '';
        $recipientCondition = '';
        if ($targetType !== 'tous') {
            $classCondition = ' AND i.classe_id = :class_id';
            $params['class_id'] = $classId;
        }
        if ($targetType === 'parent') {
            $recipientCondition = ' AND pt.id = :parent_id';
            $params['parent_id'] = $parentId;
        }
        return $this->db->fetchAll(
            "SELECT u.id, MAX(pt.email) AS email
             FROM utilisateurs u
             INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             WHERE u.est_actif = 1
               AND i.annee_scolaire_id = :year_id
               AND i.statut IN ('Actif','Redoublant')
               {$classCondition}
               {$recipientCondition}
             GROUP BY u.id",
            $params
        );
    }

    public function markRead(int $notificationId, int $userId): int
    {
        return $this->db->update(
            'UPDATE notifications
             SET est_lue = 1, date_lecture = NOW()
             WHERE id = :id AND utilisateur_id = :uid AND est_lue = 0',
            ['id' => $notificationId, 'uid' => $userId]
        );
    }

    public function belongsToUser(int $notificationId, int $userId): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE id = :id AND utilisateur_id = :uid',
            ['id' => $notificationId, 'uid' => $userId]
        ) > 0;
    }

    public function delete(int $notificationId, int $userId): int
    {
        return $this->db->delete(
            'DELETE FROM notifications WHERE id = :id AND utilisateur_id = :uid',
            ['id' => $notificationId, 'uid' => $userId]
        );
    }
}
