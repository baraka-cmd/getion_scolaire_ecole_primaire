<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class StatisticsRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getActiveYearId(): int
    {
        $id = $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
        if (!$id) {
            $id = $this->db->fetchColumn("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
        }
        return (int) ($id ?? 0);
    }

    public function getYears(): array
    {
        return $this->db->fetchAll(
            'SELECT id, libelle, date_debut, date_fin, est_active
             FROM annees_scolaires ORDER BY date_debut DESC'
        );
    }

    public function getClasses(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT id, nom FROM classes
             WHERE annee_scolaire_id = :year_id
             ORDER BY niveau, nom',
            ['year_id' => $yearId]
        );
    }

    public function getTerms(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT id, libelle, numero FROM trimestres
             WHERE annee_scolaire_id = :year_id
             ORDER BY numero',
            ['year_id' => $yearId]
        );
    }

    public function getActiveTermId(int $yearId): ?int
    {
        if ($yearId <= 0) {
            return null;
        }
        $id = $this->db->fetchColumn(
            'SELECT id FROM trimestres
             WHERE annee_scolaire_id = :year_id AND est_actif = 1 LIMIT 1',
            ['year_id' => $yearId]
        );
        return $id ? (int) $id : null;
    }

    public function getYear(int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, libelle, date_debut, date_fin
             FROM annees_scolaires WHERE id = :id',
            ['id' => $yearId]
        );
    }

    public function getClass(int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id FROM classes WHERE id = :class_id AND annee_scolaire_id = :year_id',
            ['class_id' => $classId, 'year_id' => $yearId]
        );
    }

    public function getTerm(int $termId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, libelle FROM trimestres
             WHERE id = :term_id AND annee_scolaire_id = :year_id',
            ['term_id' => $termId, 'year_id' => $yearId]
        );
    }
}
