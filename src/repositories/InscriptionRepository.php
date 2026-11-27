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
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut IN ('Actif', 'Redoublant')) AS nb_inscrits
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
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut IN ('Actif', 'Redoublant')) AS nb_inscrits
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

    

    public function activeEnrollmentForStudent(int $studentId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT i.id, c.nom FROM inscriptions i INNER JOIN classes c ON c.id = i.classe_id
             WHERE i.eleve_id = :eid AND i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant') LIMIT 1",
            ['eid' => $studentId, 'aid' => $yearId]
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
            $this->db->fetchOne('SELECT id FROM eleves WHERE id = :id AND est_actif = 1 FOR UPDATE', ['id' => $studentId]);
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
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND annee_scolaire_id = :aid AND statut IN ('Actif', 'Redoublant')",
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

    
    public function createEnrollmentSafely(int $studentId, int $classId, int $yearId, string $status): array
    {
        $this->db->beginTransaction();
        try {
            $student = $this->db->fetchOne('SELECT id, nom, prenom FROM eleves WHERE id = :id AND est_actif = 1 FOR UPDATE', ['id' => $studentId]);
            $class = $this->db->fetchOne(
                "SELECT id, capacite_max, annee_scolaire_id, est_actif FROM classes WHERE id = :id FOR UPDATE",
                ['id' => $classId]
            );
            if (!$student || !$class || (int) $class['est_actif'] !== 1 || (int) $class['annee_scolaire_id'] !== $yearId) {
                $this->db->rollback();
                return ['ok' => false, 'errors' => ['eleve_id' => !$student ? 'Élève introuvable ou inactif.' : 'Classe ou année scolaire invalide.']];
            }
            $existing = $this->activeEnrollmentForStudent($studentId, $yearId);
            if ($existing) {
                $this->db->rollback();
                return ['ok' => false, 'errors' => ['eleve_id' => 'Cet élève est déjà inscrit en ' . $existing['nom'] . ' pour cette année.']];
            }
            $count = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND annee_scolaire_id = :aid AND statut IN ('Actif', 'Redoublant')",
                ['cid' => $classId, 'aid' => $yearId]
            );
            if ($count >= (int) $class['capacite_max']) {
                $this->db->rollback();
                return ['ok' => false, 'errors' => ['classe_id' => 'Classe complète (' . (int) $class['capacite_max'] . ' élèves maximum).']];
            }
            $id = $this->insertEnrollment($studentId, $classId, $yearId, $status);
            $this->db->commit();
            return ['ok' => true, 'id' => $id, 'student' => $student];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    
    public function transitionSourceClass(int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id, a.date_debut AS source_date_debut
             FROM classes c INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             WHERE c.id = :cid AND c.annee_scolaire_id = :aid",
            ['cid' => $classId, 'aid' => $yearId]
        );
    }

    public function schoolYear(int $yearId): ?array
    {
        return $this->db->fetchOne('SELECT id, date_debut FROM annees_scolaires WHERE id = :id', ['id' => $yearId]);
    }

    public function transitionStudents(int $classId, int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance,
                    i.id AS inscription_id, i.statut
             FROM inscriptions i INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
               AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['cid' => $classId, 'aid' => $yearId]
        );
    }

    public function transitionDestinationClasses(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.capacite_max,
                    (SELECT COUNT(*) FROM inscriptions i
                     WHERE i.classe_id = c.id AND i.annee_scolaire_id = c.annee_scolaire_id
                       AND i.statut IN ('Actif', 'Redoublant')) AS nb_inscrits
             FROM classes c WHERE c.annee_scolaire_id = :aid AND c.est_actif = 1
             ORDER BY c.niveau, c.nom",
            ['aid' => $yearId]
        );
    }

    public function validSourceEnrollment(int $enrollmentId, int $studentId, int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT i.id, i.eleve_id FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id AND e.est_actif = 1
             WHERE i.id = :iid AND i.eleve_id = :eid AND i.classe_id = :cid
               AND i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant')",
            ['iid' => $enrollmentId, 'eid' => $studentId, 'cid' => $classId, 'aid' => $yearId]
        );
    }

    public function validDestinationClass(int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, niveau FROM classes WHERE id = :cid AND annee_scolaire_id = :aid AND est_actif = 1',
            ['cid' => $classId, 'aid' => $yearId]
        );
    }

    public function enrollmentExistsInYear(int $studentId, int $yearId): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM inscriptions WHERE eleve_id = :eid AND annee_scolaire_id = :aid',
            ['eid' => $studentId, 'aid' => $yearId]
        ) > 0;
    }

    public function applyTransitionPlans(array $plans, int $yearId): array
    {
        $this->db->beginTransaction();
        try {
            $studentIds = array_values(array_unique(array_map(static fn(array $p): int => (int) $p['eleve_id'], $plans)));
            sort($studentIds, SORT_NUMERIC);
            if ($studentIds) {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $this->db->fetchAll('SELECT id FROM eleves WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE', $studentIds);
            }
            foreach ($plans as $plan) {
                if ($this->enrollmentExistsInYear((int) $plan['eleve_id'], $yearId)) {
                    throw new DomainException('Un élève est déjà inscrit pour l’année de destination.');
                }
            }
            $destinationIds = [];
            foreach ($plans as $plan) {
                if ($plan['classe_destination_id'] !== null) $destinationIds[(int) $plan['classe_destination_id']] = true;
            }
            $capacity = [];
            foreach (array_keys($destinationIds) as $classId) {
                $class = $this->db->fetchOne(
                    "SELECT id, capacite_max FROM classes
                     WHERE id = :cid AND annee_scolaire_id = :aid AND est_actif = 1 FOR UPDATE",
                    ['cid' => $classId, 'aid' => $yearId]
                );
                if (!$class) throw new DomainException('Une classe de destination n’est plus disponible.');
                $current = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND annee_scolaire_id = :aid
                     AND statut IN ('Actif', 'Redoublant')",
                    ['cid' => $classId, 'aid' => $yearId]
                );
                $capacity[$classId] = ['max' => (int) $class['capacite_max'], 'count' => $current];
            }
            foreach ($plans as $plan) {
                $classId = $plan['classe_destination_id'];
                if ($classId !== null && ++$capacity[$classId]['count'] > $capacity[$classId]['max']) {
                    throw new DomainException("La classe de destination {$classId} n’a plus assez de places.");
                }
            }

            $totals = ['inscrits' => 0, 'redoublants' => 0, 'diplomes' => 0];
            foreach ($plans as $plan) {
                if ($plan['est_diplome']) {
                    $updated = $this->db->update(
                        "UPDATE inscriptions SET statut = 'Diplômé', motif_statut = 'Réussite en fin de 6e primaire',
                         date_changement_statut = CURDATE()
                         WHERE id = :iid AND eleve_id = :eid AND classe_id = :cid AND annee_scolaire_id = :aid AND statut IN ('Actif', 'Redoublant')",
                        ['iid' => $plan['inscription_source_id'], 'eid' => $plan['eleve_id'], 'cid' => $plan['classe_source_id'], 'aid' => $plan['annee_source_id']]
                    );
                    if ($updated !== 1) throw new DomainException('Une inscription de fin de cycle a changé pendant le traitement.');
                    $totals['diplomes']++;
                    continue;
                }
                $status = $plan['est_reussite'] ? 'Actif' : 'Redoublant';
                $this->insertEnrollment((int) $plan['eleve_id'], (int) $plan['classe_destination_id'], $yearId, $status);
                $totals['inscrits']++;
                if (!$plan['est_reussite']) $totals['redoublants']++;
            }
            $this->db->commit();
            return $totals;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

}
