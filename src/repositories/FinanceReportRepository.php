<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

/**
 * Requêtes de référence utilisées par les rapports financiers.
 * Les filtres et calculs du rapport restent dans FinanceReportService.
 */
class FinanceReportRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findSchoolYears(): array
    {
        return $this->db->fetchAll(
            "SELECT id, libelle, date_debut, date_fin
             FROM annees_scolaires
             ORDER BY date_debut DESC"
        );
    }

    public function getActiveYearId(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
        );
    }

    public function findClassesForYear(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom, niveau
             FROM classes
             WHERE annee_scolaire_id = :annee
             ORDER BY niveau, nom",
            ['annee' => $yearId]
        );
    }

    public function findFeesForYear(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT f.id, f.libelle, f.classe_id, f.est_actif, f.est_obligatoire,
                    cfg.categorie, cfg.devise, cfg.periodicite,
                    c.nom AS classe_nom
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             LEFT JOIN classes c ON c.id = f.classe_id
             WHERE f.annee_scolaire_id = :annee
             ORDER BY cfg.categorie, f.libelle",
            ['annee' => $yearId]
        );
    }
}
