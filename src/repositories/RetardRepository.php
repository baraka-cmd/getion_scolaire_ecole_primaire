<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class RetardRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findActiveStudent(int $studentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.id, e.nom, e.prenom
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id
             WHERE e.id = :student_id AND i.statut = 'Actif' AND e.est_actif = 1",
            ['student_id' => $studentId]
        );
    }

    public function findRetardId(int $studentId, string $date): mixed
    {
        return $this->db->fetchColumn(
            'SELECT id FROM retards WHERE eleve_id = :student_id AND date_retard = :date',
            ['student_id' => $studentId, 'date' => $date]
        );
    }

    public function createRetard(
        int $studentId,
        string $date,
        string $arrivalTime,
        string $expectedTime,
        int $duration,
        ?string $reason,
        int $justified,
        int $userId
    ): int {
        return $this->db->insert(
            'INSERT INTO retards
                (eleve_id, date_retard, heure_arrivee, heure_prevue, duree_retard,
                 motif, est_justifie, saisie_par, created_at)
             VALUES (:student_id, :date, :arrival, :expected, :duration, :reason, :justified, :user_id, NOW())',
            [
                'student_id' => $studentId,
                'date' => $date,
                'arrival' => $arrivalTime,
                'expected' => $expectedTime,
                'duration' => $duration,
                'reason' => $reason,
                'justified' => $justified,
                'user_id' => $userId,
            ]
        );
    }

    public function countForStudent(int $studentId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM retards WHERE eleve_id = :student_id',
            ['student_id' => $studentId]
        );
    }

    public function exists(int $retardId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM retards WHERE id = :id',
            ['id' => $retardId]
        ) !== null;
    }

    public function justify(int $retardId, string $reason): void
    {
        $this->db->update(
            'UPDATE retards SET est_justifie = 1, motif = :reason WHERE id = :id',
            ['reason' => $reason, 'id' => $retardId]
        );
    }

    public function delete(int $retardId): void
    {
        $this->db->delete(
            'DELETE FROM retards WHERE id = :id',
            ['id' => $retardId]
        );
    }

    public function listRetards(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ((int) ($filters['classe_id'] ?? 0) > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = (int) $filters['classe_id'];
        }
        if (($filters['date_debut'] ?? '') !== '') {
            $where[] = 'r.date_retard >= :debut';
            $params['debut'] = $filters['date_debut'];
        }
        if (($filters['date_fin'] ?? '') !== '') {
            $where[] = 'r.date_retard <= :fin';
            $params['fin'] = $filters['date_fin'];
        }
        if (($filters['justifie'] ?? '') !== '') {
            $where[] = 'r.est_justifie = :justifie';
            $params['justifie'] = (int) $filters['justifie'];
        }
        if (($filters['recherche'] ?? '') !== '') {
            $where[] = '(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)';
            $needle = '%' . $filters['recherche'] . '%';
            $params['r1'] = $needle;
            $params['r2'] = $needle;
            $params['r3'] = $needle;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM retards r
             INNER JOIN eleves e ON r.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             {$whereClause}",
            $params
        );
        $rows = $this->db->fetchAll(
            "SELECT r.*, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom,
                    DATE_FORMAT(r.date_retard, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(r.heure_arrivee, '%H:%i') AS heure_arrivee_format,
                    TIME_FORMAT(r.heure_prevue, '%H:%i') AS heure_prevue_format,
                    CONCAT(u.prenom, ' ', u.nom) AS saisie_par_nom
             FROM retards r
             INNER JOIN eleves e ON r.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             LEFT JOIN utilisateurs u ON r.saisie_par = u.id
             {$whereClause}
             ORDER BY r.date_retard DESC, e.nom
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => ($page - 1) * $perPage])
        );
        return ['retards' => $rows, 'total' => $total];
    }

    public function getRetardStatistics(int $classId, int $trimesterId): array
    {
        $where = [];
        $params = [];
        if ($classId > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $classId;
        }
        if ($trimesterId > 0) {
            $trimester = $this->db->fetchOne('SELECT date_debut, date_fin FROM trimestres WHERE id = :id', ['id' => $trimesterId]);
            if ($trimester) {
                $where[] = 'r.date_retard BETWEEN :debut AND :fin';
                $params['debut'] = $trimester['date_debut'];
                $params['fin'] = $trimester['date_fin'];
            }
        }
        $clause = $where ? 'AND ' . implode(' AND ', $where) : '';
        $base = "FROM retards r INNER JOIN eleves e ON r.eleve_id = e.id INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'";
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) {$base} WHERE 1=1 {$clause}", $params);
        $justified = (int) $this->db->fetchColumn("SELECT COUNT(*) {$base} WHERE r.est_justifie = 1 {$clause}", $params);
        $average = (float) $this->db->fetchColumn("SELECT COALESCE(AVG(r.duree_retard), 0) {$base} WHERE 1=1 {$clause}", $params);
        $top = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, c.nom AS classe_nom,
                    COUNT(r.id) AS nb_retards, SUM(r.duree_retard) AS total_duree,
                    ROUND(AVG(r.duree_retard), 1) AS moyenne_duree,
                    SUM(CASE WHEN r.est_justifie = 1 THEN 1 ELSE 0 END) AS nb_justifies
             {$base} INNER JOIN classes c ON i.classe_id = c.id
             WHERE 1=1 {$clause}
             GROUP BY e.id, e.matricule, e.nom, e.prenom, e.sexe, c.nom
             ORDER BY nb_retards DESC LIMIT 10",
            $params
        );
        return ['total_retards'=>$total, 'retards_justifies'=>$justified,
            'retards_non_justifies'=>$total-$justified, 'duree_moyenne'=>round($average,1), 'top_retards'=>$top];
    }

    public function findStudentRetards(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT r.*, DATE_FORMAT(r.date_retard, '%d/%m/%Y') AS date_format,
                    TIME_FORMAT(r.heure_arrivee, '%H:%i') AS heure_arrivee_format,
                    TIME_FORMAT(r.heure_prevue, '%H:%i') AS heure_prevue_format,
                    CONCAT(u.prenom, ' ', u.nom) AS saisie_par_nom
             FROM retards r LEFT JOIN utilisateurs u ON r.saisie_par = u.id
             WHERE r.eleve_id = :eid ORDER BY r.date_retard DESC",
            ['eid'=>$studentId]
        );
    }

    public function findStudentIdForRetard(int $retardId): ?int
    {
        $id = $this->db->fetchColumn('SELECT eleve_id FROM retards WHERE id = :id', ['id'=>$retardId]);
        return $id === null ? null : (int) $id;
    }

    public function getActiveClasses(): array
    {
        return $this->db->fetchAll("SELECT c.* FROM classes c WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1) ORDER BY c.niveau");
    }

    public function findTeacherClass(int $userId): int
    {
        return (int) $this->db->fetchColumn("SELECT e.classe_id FROM enseignants e WHERE e.utilisateur_id = :uid AND e.est_actif = 1 LIMIT 1", ['uid'=>$userId]);
    }

    public function getDashboardCounts(string $today): array
    {
        return [
            'today'=>(int)$this->db->fetchColumn('SELECT COUNT(*) FROM retards WHERE date_retard = :date',['date'=>$today]),
            'month'=>(int)$this->db->fetchColumn('SELECT COUNT(*) FROM retards WHERE MONTH(date_retard) = MONTH(CURDATE()) AND YEAR(date_retard) = YEAR(CURDATE())')
        ];
    }

    public function getGlobalStatistics(): array
    {
        return [
            'total'=>(int)$this->db->fetchColumn('SELECT COUNT(*) FROM retards'),
            'justified'=>(int)$this->db->fetchColumn('SELECT COUNT(*) FROM retards WHERE est_justifie = 1'),
            'average'=>(float)$this->db->fetchColumn('SELECT AVG(duree_retard) FROM retards'),
            'month'=>(int)$this->db->fetchColumn('SELECT COUNT(*) FROM retards WHERE MONTH(date_retard) = MONTH(CURDATE()) AND YEAR(date_retard) = YEAR(CURDATE())')
        ];
    }


    public function studentBelongsToClass(int $studentId, int $classId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT i.id FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.eleve_id = :student AND i.classe_id = :class
               AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1
             LIMIT 1",
            ['student' => $studentId, 'class' => $classId]
        );
    }

    public function getMarkingData(int $classId, string $date): array
    {
        $class = $this->db->fetchOne('SELECT * FROM classes WHERE id = :id', ['id' => $classId]);
        if (!$class) return ['classe' => null, 'eleves' => [], 'retards' => []];
        $students = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.photo,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format
             FROM inscriptions i INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :cid AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['cid' => $classId]
        );
        $late = $this->db->fetchAll(
            "SELECT r.eleve_id, r.heure_arrivee, r.duree_retard, r.motif, r.est_justifie,
                    TIME_FORMAT(r.heure_arrivee, '%H:%i') AS heure_arrivee_format
             FROM retards r
             WHERE r.eleve_id IN (SELECT eleve_id FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif')
               AND r.date_retard = :date",
            ['cid' => $classId, 'date' => $date]
        );
        return ['classe' => $class, 'eleves' => $students, 'retards' => $late];
    }

}
