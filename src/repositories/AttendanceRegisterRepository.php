<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AttendanceRegisterRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findClosuresOnDate(int $yearId, string $date): array
    {
        return $this->db->fetchAll(
            "SELECT titre, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :year_id
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :date_start AND date_fin >= :date_end
             ORDER BY date_debut",
            ['year_id' => $yearId, 'date_start' => $date, 'date_end' => $date]
        );
    }

    public function findScheduledDays(int $classId, int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT jour FROM emploi_du_temps
             WHERE classe_id = :class_id AND annee_scolaire_id = :year_id
               AND COALESCE(type_creneau, 'cours') <> 'recréation'",
            ['class_id' => $classId, 'year_id' => $yearId]
        );
    }

    public function findClosuresInRange(int $yearId, string $startDate, string $endDate): array
    {
        return $this->db->fetchAll(
            "SELECT titre, type_evenement, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :year_id
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :end_date AND date_fin >= :start_date",
            [
                'year_id' => $yearId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]
        );
    }

    public function findScheduledDayRows(int $classId, int $yearId): array
    {
        return $this->findScheduledDays($classId, $yearId);
    }
}
