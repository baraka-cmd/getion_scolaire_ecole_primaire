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
}
