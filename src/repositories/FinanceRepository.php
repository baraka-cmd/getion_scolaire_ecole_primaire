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
}
