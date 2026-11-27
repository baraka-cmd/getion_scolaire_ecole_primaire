<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

/**
 * Persistance des lectures et listes du module d'inscription.
 * Aucun contrôle HTTP ou règle métier ne doit être placé ici.
 */
class InscriptionRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $yearId = (int) ($filters['annee_id'] ?? 0);
        if ($yearId > 0) {
            $where[] = 'i.annee_scolaire_id = :annee';
            $params['annee'] = $yearId;
        } else {
            $where[] = 'i.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)';
        }

        $search = (string) ($filters['recherche'] ?? '');
        if ($search !== '') {
            $where[] = '(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)';
            $params['r1'] = '%' . $search . '%';
            $params['r2'] = '%' . $search . '%';
            $params['r3'] = '%' . $search . '%';
        }

        $classId = (int) ($filters['classe_id'] ?? 0);
        if ($classId > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $classId;
        }

        $status = (string) ($filters['statut'] ?? '');
        if ($status !== '') {
            $where[] = 'i.statut = :statut';
            $params['statut'] = $status;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             INNER JOIN classes c ON i.classe_id = c.id
             {$whereClause}",
            $params
        );
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT i.*, e.matricule, e.nom, e.prenom, e.sexe,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    c.nom AS classe_nom, c.niveau AS classe_niveau,
                    a.libelle AS annee_libelle,
                    DATE_FORMAT(i.date_inscription, '%d/%m/%Y') AS date_inscription_format
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN annees_scolaires a ON i.annee_scolaire_id = a.id
             {$whereClause}
             ORDER BY i.date_inscription DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return ['inscriptions' => $rows, 'total' => $total];
    }

    public function studentHistory(int $studentId): array
    {
        $history = $this->db->fetchAll(
            "SELECT i.*, c.nom AS classe_nom, c.niveau AS classe_niveau,
                    a.libelle AS annee_libelle,
                    DATE_FORMAT(i.date_inscription, '%d/%m/%Y') AS date_inscription_format
             FROM inscriptions i
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN annees_scolaires a ON i.annee_scolaire_id = a.id
             WHERE i.eleve_id = :eid
             ORDER BY a.date_debut DESC, i.date_inscription DESC",
            ['eid' => $studentId]
        );
        $student = $this->db->fetchOne(
            'SELECT id, matricule, nom, prenom FROM eleves WHERE id = :id',
            ['id' => $studentId]
        );
        return ['eleve' => $student, 'historique' => $history];
    }

    public function availableClasses(int $yearId): array
    {
        if ($yearId <= 0) {
            $yearId = (int) $this->db->fetchColumn(
                'SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1'
            );
        }
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.capacite_max,
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut = 'Actif') AS nb_inscrits
             FROM classes c
             WHERE c.annee_scolaire_id = :aid
             ORDER BY c.niveau",
            ['aid' => $yearId]
        );
    }

    public function schoolYears(): array
    {
        return $this->db->fetchAll('SELECT * FROM annees_scolaires ORDER BY date_debut DESC');
    }

    public function activeClasses(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut = 'Actif') AS nb_inscrits
             FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    public function unenrolledStudents(): array
    {
        $activeYear = $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1'
        );
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance
             FROM eleves e
             WHERE e.est_actif = 1
               AND e.id NOT IN (
                   SELECT eleve_id FROM inscriptions
                   WHERE annee_scolaire_id = :aid AND statut = 'Actif'
               )
             ORDER BY e.nom, e.prenom",
            ['aid' => $activeYear]
        );
    }

    public function findEnrollment(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT i.*, e.matricule, e.nom, e.prenom, c.nom AS classe_nom, a.libelle AS annee_libelle
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN annees_scolaires a ON i.annee_scolaire_id = a.id
             WHERE i.id = :id",
            ['id' => $id]
        );
    }

    public function enrollmentHistory(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, c.nom AS classe_nom, c.niveau, a.libelle AS annee_libelle
             FROM inscriptions i
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN annees_scolaires a ON i.annee_scolaire_id = a.id
             WHERE i.eleve_id = :eid
             ORDER BY a.date_debut DESC",
            ['eid' => $studentId]
        );
    }
    
    public function enrollmentStatistics(): array
    {
        $year = '(SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)';
        return [
            'actifs' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = {$year} AND statut = 'Actif'"),
            'redoublants' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = {$year} AND statut = 'Redoublant'"),
            'transferes' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = {$year} AND statut = 'Transféré'"),
        ];
    }

    public function classesForTransition(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, a.libelle AS annee_libelle, a.id AS annee_id,
                    a.date_debut AS annee_date_debut, a.est_active
             FROM classes c INNER JOIN annees_scolaires a ON c.annee_scolaire_id = a.id
             ORDER BY a.date_debut DESC, c.niveau"
        );
    }

    public function destinationSchoolYears(): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.libelle, a.date_debut, a.est_active FROM annees_scolaires a
             WHERE EXISTS (SELECT 1 FROM classes c WHERE c.annee_scolaire_id = a.id AND c.est_actif = 1)
             ORDER BY a.date_debut"
        );
    }

    public function activeYearId(): ?int
    {
        $value = $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
        return $value === null ? null : (int) $value;
    }

    public function getClassCapacity(int $classId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, capacite_max, annee_scolaire_id, est_actif FROM classes WHERE id = :id',
            ['id' => $classId]
        );
    }

    public function activeEnrollmentForStudent(int $studentId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT i.id, c.nom FROM inscriptions i INNER JOIN classes c ON c.id = i.classe_id
             WHERE i.eleve_id = :eid AND i.annee_scolaire_id = :aid AND i.statut = 'Actif' LIMIT 1",
            ['eid' => $studentId, 'aid' => $yearId]
        );
    }

    public function activeStudent(int $studentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nom, prenom FROM eleves WHERE id = :id AND est_actif = 1',
            ['id' => $studentId]
        );
    }

    public function insertEnrollment(int $studentId, int $classId, int $yearId, string $status): int
    {
        return $this->db->insert(
            "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
             VALUES (:eid, :cid, :aid, CURDATE(), :statut)",
            ['eid' => $studentId, 'cid' => $classId, 'aid' => $yearId, 'statut' => $status]
        );
    }

    public function transferEnrollment(int $studentId, int $oldClassId, int $newClassId, int $yearId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $destination = $this->db->fetchOne(
                "SELECT id, capacite_max, annee_scolaire_id, est_actif FROM classes WHERE id = :id FOR UPDATE",
                ['id' => $newClassId]
            );
            $source = $this->db->fetchOne(
                "SELECT id FROM inscriptions WHERE eleve_id = :eid AND classe_id = :cid
                 AND annee_scolaire_id = :aid AND statut = 'Actif' FOR UPDATE",
                ['eid' => $studentId, 'cid' => $oldClassId, 'aid' => $yearId]
            );
            if (!$source || !$destination || (int) $destination['annee_scolaire_id'] !== $yearId || (int) $destination['est_actif'] !== 1 || $newClassId === $oldClassId) {
                $this->db->rollback();
                return ['ok' => false, 'status' => 409, 'message' => 'L’inscription source ou la classe de destination n’est plus valide.'];
            }
            $count = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND annee_scolaire_id = :aid AND statut = 'Actif'",
                ['cid' => $newClassId, 'aid' => $yearId]
            );
            if ($count >= (int) $destination['capacite_max']) {
                $this->db->rollback();
                return ['ok' => false, 'status' => 409, 'message' => 'Classe complète (' . (int) $destination['capacite_max'] . ' élèves max).'];
            }
            $changed = $this->db->update(
                "UPDATE inscriptions SET statut = 'Transféré', motif_statut = :motif, date_changement_statut = CURDATE()
                 WHERE id = :id AND statut = 'Actif'",
                ['motif' => $reason, 'id' => (int) $source['id']]
            );
            if ($changed !== 1) {
                $this->db->rollback();
                return ['ok' => false, 'status' => 409, 'message' => 'L’inscription a été modifiée entre-temps.'];
            }
            $this->insertEnrollment($studentId, $newClassId, $yearId, 'Actif');
            $this->db->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function updateEnrollmentStatus(int $enrollmentId, string $status, string $reason): int
    {
        return $this->db->update(
            "UPDATE inscriptions SET statut = :statut, motif_statut = :motif, date_changement_statut = CURDATE()
             WHERE id = :id AND statut = 'Actif'",
            ['statut' => $status, 'motif' => $reason, 'id' => $enrollmentId]
        );
    }

}
