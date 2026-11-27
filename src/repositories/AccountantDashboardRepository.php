<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../config/database.php';

/** Accès SQL du tableau de bord financier. Les calculs SQL sont conservés à l'identique. */
class AccountantDashboardRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }

    public function activeYearId(): int
    {
        $year = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        if ($year <= 0) $year = (int) $this->db->fetchColumn("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
        return $year;
    }
    public function yearLabel(int $yearId): string
    {
        return (string) $this->db->fetchColumn("SELECT libelle FROM annees_scolaires WHERE id = :id", ['id' => $yearId]);
    }
    public function balances(int $yearId): array
    {
        return $this->db->fetchOne("SELECT
 COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN cr.montant_du ELSE 0 END), 0) AS du_usd,
 COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN COALESCE(p.montant_paye, 0) ELSE 0 END), 0) AS paye_usd,
 COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS reste_usd,
 COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN cr.montant_du ELSE 0 END), 0) AS du_cdf,
 COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN COALESCE(p.montant_paye, 0) ELSE 0 END), 0) AS paye_cdf,
 COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS reste_cdf
 FROM finance_creances cr LEFT JOIN (
 SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a
 INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id
 WHERE an.id IS NULL GROUP BY a.creance_id
 ) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee", ['annee' => $yearId]);
    }
    public function receiptsThisMonth(int $yearId): array
    {
        return $this->db->fetchOne("SELECT
 COALESCE(SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END), 0) AS usd,
 COALESCE(SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END), 0) AS cdf, COUNT(*) AS nombre
 FROM finance_versements v LEFT JOIN finance_annulations an ON an.versement_id = v.id
 WHERE an.id IS NULL AND YEAR(v.date_versement) = YEAR(CURDATE()) AND MONTH(v.date_versement) = MONTH(CURDATE())
 AND EXISTS (SELECT 1 FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id
 WHERE a.versement_id = v.id AND cr.annee_scolaire_id = :annee_exists)", ['annee_exists' => $yearId]);
    }
    public function overdueReceivables(int $yearId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM finance_creances cr LEFT JOIN (
 SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a
 INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id
 WHERE an.id IS NULL GROUP BY a.creance_id
 ) p ON p.creance_id = cr.id WHERE cr.annee_scolaire_id = :annee AND cr.date_echeance < CURDATE()
 AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0", ['annee' => $yearId]);
    }
    public function scheduledFollowups(int $yearId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM finance_suivis WHERE annee_scolaire_id = :annee AND statut = 'à_faire' AND date_relance >= CURDATE()", ['annee' => $yearId]);
    }
    public function monthlyReceipts(int $yearId): array
    {
        return $this->db->fetchAll("SELECT DATE_FORMAT(v.date_versement, '%Y-%m') AS mois,
 COALESCE(SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu WHEN v.devise_recue = 'CDF' THEN v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0) ELSE 0 END), 0) AS equivalent_usd
 FROM finance_versements v LEFT JOIN finance_annulations an ON an.versement_id = v.id
 WHERE an.id IS NULL AND v.date_versement >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
 AND v.date_versement < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
 AND EXISTS (SELECT 1 FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id
 WHERE a.versement_id = v.id AND cr.annee_scolaire_id = :annee)
 GROUP BY DATE_FORMAT(v.date_versement, '%Y-%m') ORDER BY mois", ['annee' => $yearId]);
    }
    public function classBalances(int $yearId): array
    {
        return $this->db->fetchAll("SELECT c.id, c.nom,
 COUNT(DISTINCT CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN cr.eleve_id END) AS eleves_debiteurs,
 COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_usd,
 COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_cdf
 FROM classes c INNER JOIN inscriptions i ON i.classe_id = c.id AND i.annee_scolaire_id = :inscription_year
 INNER JOIN finance_creances cr ON cr.eleve_id = i.eleve_id AND cr.annee_scolaire_id = i.annee_scolaire_id
 LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye FROM finance_affectations a
 INNER JOIN finance_versements v ON v.id = a.versement_id LEFT JOIN finance_annulations an ON an.versement_id = v.id
 WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id
 GROUP BY c.id, c.nom HAVING eleves_debiteurs > 0
 ORDER BY eleves_debiteurs DESC, solde_usd DESC, solde_cdf DESC LIMIT 8", ['inscription_year' => $yearId]);
    }
    public function studentDebtStates(int $yearId): array
    {
        return $this->db->fetchAll("SELECT etat, COUNT(*) AS nombre FROM (
 SELECT cr.eleve_id, CASE WHEN SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)) <= 0 THEN 'soldee'
 WHEN SUM(COALESCE(p.montant_paye, 0)) > 0 THEN 'partielle' ELSE 'impayee' END AS etat
 FROM finance_creances cr LEFT JOIN (SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
 FROM finance_affectations a INNER JOIN finance_versements v ON v.id = a.versement_id
 LEFT JOIN finance_annulations an ON an.versement_id = v.id WHERE an.id IS NULL GROUP BY a.creance_id) p ON p.creance_id = cr.id
 WHERE cr.annee_scolaire_id = :annee GROUP BY cr.eleve_id) situations GROUP BY etat", ['annee' => $yearId]);
    }
    public function latestReceipts(int $yearId): array
    {
        return $this->db->fetchAll("SELECT v.id, v.numero_recu, v.montant_recu, v.devise_recue, v.mode_paiement,
 DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format, e.matricule, e.nom, e.prenom,
 COALESCE(affectations.frais, '') AS frais, COALESCE(affectations.affecte_usd, 0) AS affecte_usd,
 COALESCE(affectations.affecte_cdf, 0) AS affecte_cdf
 FROM finance_versements v INNER JOIN eleves e ON e.id = v.eleve_id
 LEFT JOIN finance_annulations an ON an.versement_id = v.id
 INNER JOIN (SELECT a.versement_id, GROUP_CONCAT(DISTINCT CONCAT(cr.libelle_snapshot, ' · ', cr.periode_code)
 ORDER BY cr.libelle_snapshot, cr.periode_code SEPARATOR ', ') AS frais,
 SUM(CASE WHEN cr.devise = 'USD' THEN a.montant_affecte ELSE 0 END) AS affecte_usd,
 SUM(CASE WHEN cr.devise = 'CDF' THEN a.montant_affecte ELSE 0 END) AS affecte_cdf
 FROM finance_affectations a INNER JOIN finance_creances cr ON cr.id = a.creance_id
 WHERE cr.annee_scolaire_id = :allocation_year GROUP BY a.versement_id) affectations ON affectations.versement_id = v.id
 WHERE an.id IS NULL ORDER BY v.date_versement DESC, v.id DESC LIMIT 8", ['allocation_year' => $yearId]);
    }
}