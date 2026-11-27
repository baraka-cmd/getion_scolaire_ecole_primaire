<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../config/database.php';

/** Requêtes de lecture des vues financières (aucun rendu ni orchestration HTTP). */
class PaymentReadRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }

    public function activeYearId(): int { return (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"); }
    public function schoolYears(): array { return $this->db->fetchAll("SELECT id, libelle, est_cloturee FROM annees_scolaires ORDER BY date_debut DESC"); }
    public function yearExists(int $yearId): bool { return (bool) $this->db->fetchColumn("SELECT COUNT(*) FROM annees_scolaires WHERE id = :id", ['id' => $yearId]); }
    public function activeClasses(int $yearId): array { return $this->db->fetchAll("SELECT id, nom FROM classes WHERE annee_scolaire_id = :annee AND est_actif = 1 ORDER BY niveau", ['annee' => $yearId]); }

    public function paymentListStats(int $yearId): array
    {
        $totals = $this->db->fetchOne("SELECT COALESCE(SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END), 0) AS total_usd, COALESCE(SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END), 0) AS total_cdf, COUNT(DISTINCT v.id) AS nb_versements FROM finance_versements v INNER JOIN (SELECT a.versement_id, MIN(cr.annee_scolaire_id) AS annee_scolaire_id FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id GROUP BY a.versement_id) cr ON cr.versement_id = v.id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE cr.annee_scolaire_id = :annee AND an.id IS NULL", ['annee' => $yearId]);
        $month = $this->db->fetchOne("SELECT COALESCE(SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END), 0) AS mois_usd, COALESCE(SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END), 0) AS mois_cdf, COUNT(DISTINCT v.id) AS nb_versements_mois FROM finance_versements v INNER JOIN (SELECT a.versement_id, MIN(cr.annee_scolaire_id) AS annee_scolaire_id FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id GROUP BY a.versement_id) cr ON cr.versement_id = v.id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE cr.annee_scolaire_id = :annee AND an.id IS NULL AND YEAR(v.date_versement) = YEAR(CURDATE()) AND MONTH(v.date_versement) = MONTH(CURDATE())", ['annee' => $yearId]);
        $unpaid = (int) $this->db->fetchColumn("SELECT COUNT(DISTINCT cr.eleve_id) FROM finance_creances cr LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0", ['annee' => $yearId]);
        $students = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = :annee AND statut IN ('Actif', 'Redoublant')", ['annee' => $yearId]);
        return ['totauxVersements' => $totals, 'totauxMois' => $month, 'nbImpayes' => $unpaid, 'nbElevesTotal' => $students];
    }
    public function unpaidListStats(int $yearId): array
    {
        $balances = $this->db->fetchOne("SELECT COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_usd, COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_cdf FROM finance_creances cr LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee", ['annee' => $yearId]);
        $students = (int) $this->db->fetchColumn("SELECT COUNT(DISTINCT cr.eleve_id) FROM finance_creances cr LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0", ['annee' => $yearId]);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = :annee AND statut IN ('Actif', 'Redoublant')", ['annee' => $yearId]);
        $late = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM finance_creances cr LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee AND cr.date_echeance < CURDATE() AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0", ['annee' => $yearId]);
        return ['soldesOuverts' => $balances, 'nbElevesImpayes' => $students, 'nbElevesTotal' => $total, 'nbCreancesEnRetard' => $late];
    }
    public function feesForYear(int $yearId): array
    {
        return $this->db->fetchAll("SELECT f.*, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance, (SELECT COUNT(*) FROM finance_creances cr WHERE cr.frais_scolaire_id = f.id) AS nb_creances, (SELECT COUNT(DISTINCT cr.eleve_id) FROM finance_creances cr WHERE cr.frais_scolaire_id = f.id) AS nb_eleves, (SELECT COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN a.montant_affecte ELSE 0 END), 0) FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE cr.frais_scolaire_id = f.id AND an.id IS NULL) AS encaisse_usd, (SELECT COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN a.montant_affecte ELSE 0 END), 0) FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE cr.frais_scolaire_id = f.id AND an.id IS NULL) AS encaisse_cdf FROM frais_scolaires f INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id WHERE f.annee_scolaire_id = :aid ORDER BY f.est_obligatoire DESC, f.libelle", ['aid' => $yearId]);
    }
    public function feeTotals(int $yearId): array
    {
        return $this->db->fetchAll("SELECT c.* FROM classes c WHERE c.annee_scolaire_id = :annee ORDER BY c.niveau", ['annee' => $yearId]);
    }
}