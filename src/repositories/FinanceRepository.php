<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class FinanceRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getActiveYearId(): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1'
        );
    }

    public function findStudentReceipts(int $versementId): ?array
    {
        return $this->db->fetchOne(
            "SELECT v.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom, c.niveau AS classe_niveau,
                    DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(v.date_versement, '%H:%i') AS heure_format,
                    cr.libelle_snapshot AS frais_libelle, cr.periode_code,
                    cr.montant_du AS montant_total, cr.devise AS devise_creance,
                    a.montant_affecte,
                    CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom,
                    an.type AS type_correction, an.motif AS motif_correction,
                    an.numero_contre_ecriture
             FROM finance_versements v
             INNER JOIN finance_affectations a ON a.versement_id = v.id
             INNER JOIN finance_creances cr ON cr.id = a.creance_id
             INNER JOIN eleves e ON e.id = v.eleve_id
             INNER JOIN inscriptions i ON i.eleve_id = e.id
                 AND i.annee_scolaire_id = cr.annee_scolaire_id
             INNER JOIN classes c ON c.id = i.classe_id
             LEFT JOIN utilisateurs u ON u.id = v.enregistre_par
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             WHERE v.id = :id",
            ['id' => $versementId]
        );
    }

    public function findActiveFees(): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             WHERE f.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
               AND f.est_actif = 1
             ORDER BY f.est_obligatoire DESC, f.libelle"
        );
    }

    public function searchStudents(int $yearId, string $search): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, c.nom AS classe_nom
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.annee_scolaire_id = :annee
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE e.est_actif = 1
               AND (e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)
             ORDER BY e.nom, e.prenom
             LIMIT 10",
            [
                'annee' => $yearId,
                'r1' => '%' . $search . '%',
                'r2' => '%' . $search . '%',
                'r3' => '%' . $search . '%',
            ]
        );
    }

    public function isStudentEnrolled(int $studentId, int $yearId): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM inscriptions
             WHERE eleve_id = :eleve AND annee_scolaire_id = :annee',
            ['eleve' => $studentId, 'annee' => $yearId]
        ) > 0;
    }

    public function createFinancialFollowUp(array $data): int
    {
        return (int) $this->db->insert(
            "INSERT INTO finance_suivis
                (eleve_id, annee_scolaire_id, type_suivi, contact_parent, date_relance,
                 motif, suite_donnee, statut, saisi_par)
             VALUES (:eleve, :annee, :type, :contact, :relance, :motif, :suite, :statut, :user)",
            [
                'eleve' => $data['eleve_id'],
                'annee' => $data['annee_id'],
                'type' => $data['type'],
                'contact' => $data['contact_parent'],
                'relance' => $data['date_relance'],
                'motif' => $data['motif'],
                'suite' => $data['suite_donnee'],
                'statut' => $data['statut'],
                'user' => $data['utilisateur_id'],
            ]
        );
    }

    public function findActiveClasses(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT c.* FROM classes c
             WHERE c.annee_scolaire_id = :annee
             ORDER BY c.niveau',
            ['annee' => $yearId]
        );
    }
    public function listFeesForYear(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance,
                    (SELECT COUNT(*) FROM finance_creances c WHERE c.frais_scolaire_id = f.id) AS nb_creances,
                    (SELECT COALESCE(SUM(a.montant_affecte), 0)
                     FROM finance_affectations a
                     INNER JOIN finance_creances c ON c.id = a.creance_id
                     INNER JOIN finance_versements v ON v.id = a.versement_id
                     LEFT JOIN finance_annulations an ON an.versement_id = v.id
                     WHERE c.frais_scolaire_id = f.id AND an.id IS NULL) AS total_encaisse
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             WHERE f.annee_scolaire_id = :aid
             ORDER BY f.est_obligatoire DESC, f.libelle",
            ['aid' => $yearId]
        );
    }

    public function findFee(int $feeId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, libelle, annee_scolaire_id, est_actif
             FROM frais_scolaires WHERE id = :id",
            ['id' => $feeId]
        );
    }

    public function feeExists(string $label, int $yearId, int $classId, int $excludeId = 0): bool
    {
        $sql = "SELECT COUNT(*) FROM frais_scolaires
                WHERE libelle = :libelle AND annee_scolaire_id = :aid
                  AND COALESCE(classe_id, 0) = :classe";
        $params = ['libelle' => $label, 'aid' => $yearId, 'classe' => $classId];
        if ($excludeId > 0) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params) > 0;
    }

    public function schoolYearExists(int $yearId): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM annees_scolaires WHERE id = :id", ['id' => $yearId]
        ) > 0;
    }

    public function classBelongsToYear(int $classId, int $yearId): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes WHERE id = :id AND annee_scolaire_id = :annee",
            ['id' => $classId, 'annee' => $yearId]
        ) > 0;
    }

    public function createFee(array $data): int
    {
        return (int) $this->db->insert(
            "INSERT INTO frais_scolaires
                (libelle, description, montant, classe_id, annee_scolaire_id, est_obligatoire, est_actif)
             VALUES (:libelle, :description, :montant, :classe, :annee, :obligatoire, 1)",
            [
                'libelle' => $data['libelle'], 'description' => $data['description'],
                'montant' => $data['montant'], 'classe' => $data['classe_id'],
                'annee' => $data['annee_id'], 'obligatoire' => $data['est_obligatoire'],
            ]
        );
    }

    public function createFeeConfig(int $feeId, array $data): void
    {
        $this->db->insert(
            "INSERT INTO finance_frais_config
                (frais_scolaire_id, categorie, devise, periodicite, jour_echeance)
             VALUES (:id, :categorie, :devise, :periodicite, :jour)",
            ['id' => $feeId, 'categorie' => $data['categorie'], 'devise' => $data['devise'],
             'periodicite' => $data['periodicite'], 'jour' => $data['jour_echeance']]
        );
    }

    public function updateFee(int $feeId, array $data): void
    {
        $this->db->update(
            "UPDATE frais_scolaires
             SET libelle = :libelle, description = :description, montant = :montant,
                 classe_id = :classe, est_obligatoire = :obligatoire
             WHERE id = :id",
            ['libelle' => $data['libelle'], 'description' => $data['description'],
             'montant' => $data['montant'], 'classe' => $data['classe_id'],
             'obligatoire' => $data['est_obligatoire'], 'id' => $feeId]
        );
        $this->db->update(
            "UPDATE finance_frais_config
             SET categorie = :categorie, devise = :devise, periodicite = :periodicite, jour_echeance = :jour
             WHERE frais_scolaire_id = :id",
            ['categorie' => $data['categorie'], 'devise' => $data['devise'],
             'periodicite' => $data['periodicite'], 'jour' => $data['jour_echeance'], 'id' => $feeId]
        );
    }

    public function countLegacyPaymentsForFee(int $feeId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM paiements WHERE frais_scolaire_id = :fid", ['fid' => $feeId]
        );
    }

    public function countClaimsForFee(int $feeId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM finance_creances WHERE frais_scolaire_id = :fid", ['fid' => $feeId]
        );
    }

    public function deleteFee(int $feeId): void
    {
        $this->db->delete("DELETE FROM frais_scolaires WHERE id = :id", ['id' => $feeId]);
    }

    public function updateFeeStatus(int $feeId, int $status): void
    {
        $this->db->update("UPDATE frais_scolaires SET est_actif = :statut WHERE id = :id",
            ['statut' => $status, 'id' => $feeId]);
    }
}
