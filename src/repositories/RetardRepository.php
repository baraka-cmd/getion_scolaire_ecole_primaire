<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class RetardRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findActiveStudent(int $studentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.id, e.nom, e.prenom
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id
             WHERE e.id = :student_id AND i.statut = 'Actif' AND e.est_actif = 1",
            ['student_id' => $studentId]
        );
    }

    public function findRetardId(int $studentId, string $date): mixed
    {
        return $this->db->fetchColumn(
            'SELECT id FROM retards WHERE eleve_id = :student_id AND date_retard = :date',
            ['student_id' => $studentId, 'date' => $date]
        );
    }

    public function createRetard(
        int $studentId,
        string $date,
        string $arrivalTime,
        string $expectedTime,
        int $duration,
        ?string $reason,
        int $justified,
        int $userId
    ): int {
        return $this->db->insert(
            'INSERT INTO retards
                (eleve_id, date_retard, heure_arrivee, heure_prevue, duree_retard,
                 motif, est_justifie, saisie_par, created_at)
             VALUES (:student_id, :date, :arrival, :expected, :duration, :reason, :justified, :user_id, NOW())',
            [
                'student_id' => $studentId,
                'date' => $date,
                'arrival' => $arrivalTime,
                'expected' => $expectedTime,
                'duration' => $duration,
                'reason' => $reason,
                'justified' => $justified,
                'user_id' => $userId,
            ]
        );
    }

    public function countForStudent(int $studentId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM retards WHERE eleve_id = :student_id',
            ['student_id' => $studentId]
        );
    }

    public function exists(int $retardId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM retards WHERE id = :id',
            ['id' => $retardId]
        ) !== null;
    }

    public function justify(int $retardId, string $reason): void
    {
        $this->db->update(
            'UPDATE retards SET est_justifie = 1, motif = :reason WHERE id = :id',
            ['reason' => $reason, 'id' => $retardId]
        );
    }

    public function delete(int $retardId): void
    {
        $this->db->delete(
            'DELETE FROM retards WHERE id = :id',
            ['id' => $retardId]
        );
    }
}
