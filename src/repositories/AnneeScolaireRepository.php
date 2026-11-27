<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AnneeScolaireRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function countLabel(string $label, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM annees_scolaires WHERE libelle = :label';
        $params = ['label' => $label];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function countOverlappingDates(string $start, string $end, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM annees_scolaires
                WHERE (date_debut BETWEEN :start1 AND :end1)
                   OR (date_fin BETWEEN :start2 AND :end2)
                   OR (date_debut <= :start3 AND date_fin >= :end3)';
        $params = [
            'start1' => $start, 'end1' => $end,
            'start2' => $start, 'end2' => $end,
            'start3' => $start, 'end3' => $end,
        ];
        if ($excludeId !== null) {
            $sql = 'SELECT COUNT(*) FROM annees_scolaires WHERE id != :exclude_id AND (
                    (date_debut BETWEEN :start1 AND :end1)
                    OR (date_fin BETWEEN :start2 AND :end2)
                    OR (date_debut <= :start3 AND date_fin >= :end3))';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM annees_scolaires WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function findSchoolYearConfigurationForUpdate(): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, annee_scolaire_active_id FROM parametres_ecole WHERE id = 1 FOR UPDATE'
        );
    }

    public function countEnrollments(int $yearId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = :year_id',
            ['year_id' => $yearId]
        );
    }

    public function countClasses(int $yearId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = :year_id',
            ['year_id' => $yearId]
        );
    }

    public function deleteTerms(int $yearId): void
    {
        $this->db->delete('DELETE FROM trimestres WHERE annee_scolaire_id = :year_id', ['year_id' => $yearId]);
    }

    public function deleteYear(int $yearId): void
    {
        $this->db->delete('DELETE FROM annees_scolaires WHERE id = :id', ['id' => $yearId]);
    }

    public function findListForAjax(): array
    {
        return $this->db->fetchAll(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM trimestres t WHERE t.annee_scolaire_id = a.id) AS nb_trimestres,
                    (SELECT COUNT(*) FROM classes c WHERE c.annee_scolaire_id = a.id) AS nb_classes,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.annee_scolaire_id = a.id) AS nb_inscriptions
             FROM annees_scolaires a
             ORDER BY a.date_debut DESC"
        );
    }

    public function findForAjax(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM trimestres WHERE annee_scolaire_id = a.id) AS nb_trimestres,
                    (SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = a.id) AS nb_classes,
                    (SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = a.id) AS nb_inscriptions
             FROM annees_scolaires a
             WHERE a.id = :id",
            ['id' => $id]
        );
    }

    public function findTerms(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM trimestres WHERE annee_scolaire_id = :year_id ORDER BY numero',
            ['year_id' => $yearId]
        );
    }

    public function findTermStates(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT id, numero, est_actif FROM trimestres WHERE annee_scolaire_id = :year_id',
            ['year_id' => $yearId]
        );
    }

    public function updateTerm(array $params): void
    {
        $this->db->update(
            'UPDATE trimestres
             SET libelle = :lib, date_debut = :debut, date_fin = :fin, est_actif = :actif
             WHERE annee_scolaire_id = :aid AND numero = :num',
            $params
        );
    }

    public function createTerm(array $params): void
    {
        $this->db->insert(
            'INSERT INTO trimestres
                (annee_scolaire_id, numero, libelle, date_debut, date_fin, est_actif)
             VALUES (:aid, :num, :lib, :debut, :fin, :actif)',
            $params
        );
    }

    public function findAll(): array
    {
        return $this->db->fetchAll(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = a.id) AS nb_classes,
                    (SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = a.id) AS nb_inscriptions
             FROM annees_scolaires a
             ORDER BY a.date_debut DESC"
        );
    }

    public function findActive(): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM annees_scolaires WHERE est_active = 1 LIMIT 1'
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM annees_scolaires WHERE id = :id',
            ['id' => $id]
        );
    }

    /** Persist school-year mutations; the controller/service owns the encompassing transaction. */
    public function createYear(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO annees_scolaires (libelle, date_debut, date_fin, est_active, est_cloturee)
             VALUES (:libelle, :debut, :fin, 0, 0)',
            ['libelle' => $data['libelle'], 'debut' => $data['debut'], 'fin' => $data['fin']]
        );
    }

    public function updateYear(array $data): void
    {
        $this->db->update(
            'UPDATE annees_scolaires SET libelle = :libelle, date_debut = :debut, date_fin = :fin WHERE id = :id',
            ['libelle' => $data['libelle'], 'debut' => $data['debut'], 'fin' => $data['fin'], 'id' => $data['id']]
        );
    }

    public function deactivateAllYears(): void
    {
        $this->db->update('UPDATE annees_scolaires SET est_active = 0');
    }

    public function activateYear(int $yearId): void
    {
        $this->db->update('UPDATE annees_scolaires SET est_active = 1 WHERE id = :id', ['id' => $yearId]);
    }

    public function setActiveYearInSchoolSettings(int $yearId): void
    {
        $this->db->update(
            'UPDATE parametres_ecole SET annee_scolaire_active_id = :id WHERE id = 1',
            ['id' => $yearId]
        );
    }

    public function findValidActiveYearForUpdate(int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id FROM annees_scolaires WHERE id = :id AND est_active = 1 AND est_cloturee = 0 FOR UPDATE',
            ['id' => $yearId]
        );
    }

    public function closeYear(int $yearId): void
    {
        $this->db->update(
            'UPDATE annees_scolaires SET est_cloturee = 1, est_active = 0
             WHERE id = :id AND est_active = 0 AND est_cloturee = 0',
            ['id' => $yearId]
        );
    }

}
