<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

/**
 * Accès aux données des résultats et bulletins.
 *
 * Ce repository conserve volontairement les requêtes historiques à l'identique :
 * la migration MVC ne doit modifier ni les règles scolaires ni les données retournées.
 */
class ResultsBulletinRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findActiveSchoolYearId(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
        );
    }

    public function findStudentForResults(int $studentId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau, c.id AS classe_id,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id AND c.annee_scolaire_id = i.annee_scolaire_id
             WHERE e.id = :eid AND i.annee_scolaire_id = :aid",
            ['eid' => $studentId, 'aid' => $yearId]
        );
    }

    public function findStudentForBulletin(int $studentId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau, c.id AS classe_id
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE e.id = :eid AND i.annee_scolaire_id = :aid
               AND c.annee_scolaire_id = i.annee_scolaire_id",
            ['eid' => $studentId, 'aid' => $yearId]
        );
    }

    public function findSchoolYear(int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM annees_scolaires WHERE id = :id",
            ['id' => $yearId]
        );
    }

    public function findSchoolParameters(): ?array
    {
        return $this->db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
    }
}
