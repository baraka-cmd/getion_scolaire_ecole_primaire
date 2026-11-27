<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AbsenceRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function hasActiveEnrollment(int $studentId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT e.id
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id
             WHERE e.id = :student_id AND i.statut = 'Actif' AND e.est_actif = 1",
            ['student_id' => $studentId]
        );
    }

    public function findAbsenceId(int $studentId, string $date): ?int
    {
        $id = $this->db->fetchColumn(
            'SELECT id FROM absences WHERE eleve_id = :student_id AND date_absence = :date',
            ['student_id' => $studentId, 'date' => $date]
        );

        return $id === null ? null : (int) $id;
    }

    public function updateStatus(int $absenceId, string $type, ?string $reason, int $userId): void
    {
        $this->db->update(
            'UPDATE absences
             SET type_absence = :type, motif = :reason, saisie_par = :user_id
             WHERE id = :id',
            [
                'type' => $type,
                'reason' => $reason,
                'user_id' => $userId,
                'id' => $absenceId,
            ]
        );
    }

    public function createStatus(int $studentId, string $date, string $type, ?string $reason, int $userId): void
    {
        $this->db->insert(
            "INSERT INTO absences (eleve_id, date_absence, type_absence, motif, saisie_par, created_at)
             VALUES (:student_id, :date, :type, :reason, :user_id, NOW())",
            [
                'student_id' => $studentId,
                'date' => $date,
                'type' => $type,
                'reason' => $reason,
                'user_id' => $userId,
            ]
        );
    }

    public function updateReason(int $studentId, string $date, ?string $reason): void
    {
        $this->db->update(
            'UPDATE absences SET motif = :reason WHERE eleve_id = :student_id AND date_absence = :date',
            ['reason' => $reason, 'student_id' => $studentId, 'date' => $date]
        );
    }

    public function findAbsenceStudentId(int $absenceId): ?int
    {
        $studentId = $this->db->fetchColumn(
            'SELECT eleve_id FROM absences WHERE id = :id',
            ['id' => $absenceId]
        );

        return $studentId === null ? null : (int) $studentId;
    }

    public function updateJustification(int $absenceId, string $reason): void
    {
        $this->db->update(
            'UPDATE absences
             SET est_justifiee = 1, motif = :reason, date_justification = NOW()
             WHERE id = :id',
            ['reason' => $reason, 'id' => $absenceId]
        );
    }

    public function createJustifiedAbsence(int $studentId, string $date, string $reason, int $userId): void
    {
        $this->db->insert(
            "INSERT INTO absences
                (eleve_id, date_absence, type_absence, est_justifiee, motif,
                 justificatif_document, date_justification, saisie_par)
             VALUES (:student_id, :date, 'Absence', 1, :reason, NULL, NOW(), :user_id)",
            [
                'student_id' => $studentId,
                'date' => $date,
                'reason' => $reason,
                'user_id' => $userId,
            ]
        );
    }

    public function findAbsenceIdForJustification(int $studentId, string $date): ?int
    {
        $id = $this->db->fetchColumn(
            'SELECT id FROM absences WHERE eleve_id = :student_id AND date_absence = :date',
            ['student_id' => $studentId, 'date' => $date]
        );

        return $id === null ? null : (int) $id;
    }

    public function listAbsences(
        array $filters,
        int $userId,
        string $role,
        int $page,
        int $perPage
    ): array {
        [$where, $params] = $this->buildFilters($filters, $userId, $role);
        $whereClause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN eleves e ON a.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $absences = $this->db->fetchAll(
            "SELECT a.*, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom,
                    DATE_FORMAT(a.date_absence, '%d/%m/%Y') AS date_format,
                    CONCAT(u.prenom, ' ', u.nom) AS saisie_par_nom
             FROM absences a
             INNER JOIN eleves e ON a.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             LEFT JOIN utilisateurs u ON a.saisie_par = u.id
             {$whereClause}
             ORDER BY a.date_absence DESC, e.nom
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return ['absences' => $absences, 'total' => $total];
    }

    public function getStatistics(array $filters, int $userId, string $role): array
    {
        $where = [];
        $params = [];
        if ($filters['classe_id'] > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $filters['classe_id'];
        }

        if ($filters['trimestre_id'] > 0) {
            $trimester = $this->db->fetchOne(
                'SELECT date_debut, date_fin FROM trimestres WHERE id = :id',
                ['id' => $filters['trimestre_id']]
            );
            if ($trimester) {
                $where[] = 'a.date_absence BETWEEN :debut AND :fin';
                $params['debut'] = $trimester['date_debut'];
                $params['fin'] = $trimester['date_fin'];
            }
        }

        $this->appendStudentScope($where, $params, $userId, $role);
        $whereClause = $where === [] ? '' : 'AND ' . implode(' AND ', $where);

        $totalAbsences = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN eleves e ON a.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             WHERE a.type_absence = 'Absence' {$whereClause}",
            $params
        );
        $absencesJustifiees = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN eleves e ON a.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             WHERE a.type_absence = 'Absence' AND a.est_justifiee = 1 {$whereClause}",
            $params
        );
        $topAbsents = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom,
                    COUNT(a.id) AS nb_absences,
                    SUM(CASE WHEN a.est_justifiee = 1 THEN 1 ELSE 0 END) AS nb_justifiees
             FROM absences a
             INNER JOIN eleves e ON a.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE a.type_absence = 'Absence' {$whereClause}
             GROUP BY e.id, e.matricule, e.nom, e.prenom, e.sexe, c.nom
             ORDER BY nb_absences DESC
             LIMIT 10",
            $params
        );

        return [
            'total_absences' => $totalAbsences,
            'absences_justifiees' => $absencesJustifiees,
            'top_absents' => $topAbsents,
        ];
    }

    public function findStudentAbsences(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, DATE_FORMAT(a.date_absence, '%d/%m/%Y') AS date_format,
                    CONCAT(u.prenom, ' ', u.nom) AS saisie_par_nom
             FROM absences a
             LEFT JOIN utilisateurs u ON a.saisie_par = u.id
             WHERE a.eleve_id = :student_id
             ORDER BY a.date_absence DESC",
            ['student_id' => $studentId]
        );
    }

    public function getActiveClasses(): array
    {
        return $this->db->fetchAll(
            "SELECT c.* FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    public function findTeacherClass(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT e.classe_id FROM enseignants e WHERE e.utilisateur_id = :user_id AND e.est_actif = 1 LIMIT 1',
            ['user_id' => $userId]
        );
    }

    public function getDashboardCounts(string $date): array
    {
        $totalAbsences = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences WHERE date_absence = :date AND type_absence = 'Absence'",
            ['date' => $date]
        );
        $totalPresences = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences WHERE date_absence = :date AND type_absence = 'Présent'",
            ['date' => $date]
        );

        return ['absences' => $totalAbsences, 'presences' => $totalPresences];
    }

    private function buildFilters(array $filters, int $userId, string $role): array
    {
        $where = [];
        $params = [];

        if ($filters['classe_id'] > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $filters['classe_id'];
        }
        if ($filters['date_debut'] !== '') {
            $where[] = 'a.date_absence >= :debut';
            $params['debut'] = $filters['date_debut'];
        }
        if ($filters['date_fin'] !== '') {
            $where[] = 'a.date_absence <= :fin';
            $params['fin'] = $filters['date_fin'];
        }
        if ($filters['type'] !== '') {
            $where[] = 'a.type_absence = :type';
            $params['type'] = $filters['type'];
        }
        if ($filters['justifiee'] !== '') {
            $where[] = 'a.est_justifiee = :justifiee';
            $params['justifiee'] = (int) $filters['justifiee'];
        }
        $this->appendStudentScope($where, $params, $userId, $role);
        if ($filters['recherche'] !== '') {
            $where[] = '(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)';
            $params['r1'] = '%' . $filters['recherche'] . '%';
            $params['r2'] = '%' . $filters['recherche'] . '%';
            $params['r3'] = '%' . $filters['recherche'] . '%';
        }

        return [$where, $params];
    }

    private function appendStudentScope(array &$where, array &$params, int $userId, string $role): void
    {
        if ($role === 'Parent') {
            $where[] = 'EXISTS (
                SELECT 1
                FROM eleve_parent ep
                INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                WHERE ep.eleve_id = e.id AND pt.utilisateur_id = :absence_parent_user_id
            )';
            $params['absence_parent_user_id'] = $userId;
        } elseif ($role === 'Enseignant') {
            $where[] = "EXISTS (
                SELECT 1
                FROM enseignants ens
                WHERE ens.utilisateur_id = :absence_teacher_user_id
                  AND ens.est_actif = 1
                  AND ens.classe_id = i.classe_id
                  AND i.annee_scolaire_id = (
                      SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
                  )
            )";
            $params['absence_teacher_user_id'] = $userId;
        }
    }
}
