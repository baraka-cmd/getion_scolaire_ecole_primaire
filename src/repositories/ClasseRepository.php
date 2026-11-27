<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class ClasseRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findPaginatedList(
        string $search,
        int $schoolYearId,
        string $orderBy,
        string $direction,
        int $limit,
        int $offset
    ): array {
        $allowedOrder = ['id', 'nom', 'niveau', 'salle', 'capacite_max', 'nb_eleves'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'niveau';
        }
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $conditions = [];
        $params = [];
        if ($search !== '') {
            $conditions[] = '(c.nom LIKE :search_name OR c.salle LIKE :search_room)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_room'] = '%' . $search . '%';
        }
        if ($schoolYearId > 0) {
            $conditions[] = 'c.annee_scolaire_id = :school_year_id';
            $params['school_year_id'] = $schoolYearId;
        } else {
            $conditions[] = 'c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)';
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes c {$where}",
            $params
        );
        $orderExpression = $orderBy === 'nb_eleves' ? 'nb_eleves' : 'c.' . $orderBy;
        $rows = $this->db->fetchAll(
            "SELECT c.*,
                    COALESCE(e.nom, '—') AS titulaire_nom,
                    COALESCE(e.prenom, '') AS titulaire_prenom,
                    e.id AS titulaire_id,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves,
                    a.libelle AS annee_libelle
             FROM classes c
             LEFT JOIN enseignants e ON c.id = e.classe_id AND e.est_actif = 1
             INNER JOIN annees_scolaires a ON c.annee_scolaire_id = a.id
             {$where}
             ORDER BY {$orderExpression} {$direction}
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );
        return ['total' => $total, 'classes' => $rows];
    }

    public function countNameInYear(string $name, int $yearId, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM classes WHERE nom = :name AND annee_scolaire_id = :year_id';
        $params = ['name' => $name, 'year_id' => $yearId];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function countRoomInYear(string $room, int $yearId, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM classes WHERE salle = :room AND annee_scolaire_id = :year_id';
        $params = ['room' => $room, 'year_id' => $yearId];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function findBasic(int $classId): ?array
    {
        return $this->db->fetchOne('SELECT id, nom FROM classes WHERE id = :id', ['id' => $classId]);
    }

    public function countActiveStudents(int $classId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :class_id AND statut = 'Actif'",
            ['class_id' => $classId]
        );
    }

    public function countStudents(int $classId): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM inscriptions WHERE classe_id = :class_id', ['class_id' => $classId]);
    }

    public function countEvaluations(int $classId): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM evaluations WHERE classe_id = :class_id', ['class_id' => $classId]);
    }

    public function findTeacherClass(int $teacherId): ?int
    {
        $classId = $this->db->fetchColumn(
            'SELECT classe_id FROM enseignants WHERE id = :teacher_id',
            ['teacher_id' => $teacherId]
        );
        return $classId === false || $classId === null ? null : (int) $classId;
    }

    public function findForAjax(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.*,
                    e.id AS titulaire_id,
                    CONCAT(e.prenom, ' ', e.nom) AS titulaire_nom,
                    a.libelle AS annee_libelle,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves
             FROM classes c
             LEFT JOIN enseignants e ON c.id = e.classe_id AND e.est_actif = 1
             INNER JOIN annees_scolaires a ON c.annee_scolaire_id = a.id
             WHERE c.id = :id",
            ['id' => $id]
        );
    }

    public function findAvailableTeachersForClass(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT id, matricule, nom, prenom, specialite
             FROM enseignants
             WHERE est_actif = 1 AND (classe_id IS NULL OR classe_id = :class_id)
             ORDER BY nom",
            ['class_id' => $classId]
        );
    }

    public function findStudentsForAjax(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance,
                    i.date_inscription, i.statut
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :class_id AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['class_id' => $classId]
        );
    }

    public function findSchoolYears(): array
    {
        return $this->db->fetchAll(
            'SELECT id, libelle, est_active FROM annees_scolaires ORDER BY date_debut DESC'
        );
    }

    public function findAvailableTeachers(?int $classId): array
    {
        $sql = 'SELECT id, matricule, nom, prenom, specialite, classe_id
                FROM enseignants
                WHERE est_actif = 1';
        $params = [];

        if ($classId) {
            $sql .= ' AND (classe_id IS NULL OR classe_id = :class_id)';
            $params['class_id'] = $classId;
        } else {
            $sql .= ' AND classe_id IS NULL';
        }

        $sql .= ' ORDER BY nom';
        return $this->db->fetchAll($sql, $params);
    }

    public function findDetail(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.*,
                    CONCAT(COALESCE(e.prenom, ''), ' ', COALESCE(e.nom, '')) AS titulaire_nom,
                    e.id AS titulaire_id, e.matricule AS titulaire_matricule,
                    e.telephone AS titulaire_telephone, e.email AS titulaire_email,
                    a.libelle AS annee_libelle,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves
             FROM classes c
             LEFT JOIN enseignants e ON c.id = e.classe_id AND e.est_actif = 1
             INNER JOIN annees_scolaires a ON c.annee_scolaire_id = a.id
             WHERE c.id = :id",
            ['id' => $id]
        );
    }

    public function findStudents(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance,
                    DATE_FORMAT(i.date_inscription, '%d/%m/%Y') AS date_inscription,
                    i.statut
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :class_id AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['class_id' => $classId]
        );
    }

    public function releaseClassHolder(int $classId): void
    {
        $this->db->update(
            'UPDATE enseignants SET classe_id = NULL WHERE classe_id = :class_id',
            ['class_id' => $classId]
        );
    }

    public function releaseTeacherFromClass(int $teacherId): void
    {
        $this->db->update(
            'UPDATE enseignants SET classe_id = NULL WHERE id = :teacher_id',
            ['teacher_id' => $teacherId]
        );
    }

    public function assignTeacherToClass(int $teacherId, int $classId): void
    {
        $this->db->update(
            'UPDATE enseignants SET classe_id = :class_id WHERE id = :teacher_id',
            ['class_id' => $classId, 'teacher_id' => $teacherId]
        );
    }
}
