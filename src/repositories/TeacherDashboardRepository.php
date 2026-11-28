<?php
/**
 * Persistence du tableau de bord enseignant.
 * Les requêtes et paramètres SQL restent centralisés ici.
 */
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class TeacherDashboardRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function assignedClass(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.id, c.nom, c.niveau, c.salle, c.capacite_max
             FROM classes c
             INNER JOIN enseignants e ON c.id = e.classe_id
             WHERE e.utilisateur_id = :uid AND c.annee_scolaire_id = (
                 SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1
             )",
            ['uid' => $userId]
        ) ?: null;
    }

    public function activeStudentCount(int $classId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
            ['cid' => $classId]
        );
    }

    public function dailyAttendance(int $classId): array
    {
        $present = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN inscriptions i ON a.eleve_id = i.eleve_id
             WHERE i.classe_id = :cid AND a.date_absence = CURDATE() AND a.type_absence = 'Présent'",
            ['cid' => $classId]
        );
        $absent = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN inscriptions i ON a.eleve_id = i.eleve_id
             WHERE i.classe_id = :cid AND a.date_absence = CURDATE() AND a.type_absence = 'Absence'",
            ['cid' => $classId]
        );
        return ['present' => $present, 'absent' => $absent];
    }

    public function activeTermId(): int
    {
        return (int) $this->db->fetchColumn("SELECT trimestre_actif_id FROM parametres_ecole LIMIT 1");
    }

    public function enteredNotesCount(int $classId, int $termId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notes n
             INNER JOIN evaluations ev ON n.evaluation_id = ev.id
             WHERE ev.classe_id = :cid AND ev.trimestre_id = :tid",
            ['cid' => $classId, 'tid' => $termId]
        );
    }

    public function attendanceForDate(int $classId, string $date): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences a
             INNER JOIN inscriptions i ON a.eleve_id = i.eleve_id
             WHERE i.classe_id = :cid AND a.date_absence = :date AND a.type_absence = 'Présent'",
            ['cid' => $classId, 'date' => $date]
        );
    }

    public function genderCounts(int $classId): array
    {
        $boys = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions i INNER JOIN eleves e ON i.eleve_id = e.id WHERE i.classe_id = :cid AND e.sexe = 'M' AND i.statut = 'Actif'",
            ['cid' => $classId]
        );
        $girls = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions i INNER JOIN eleves e ON i.eleve_id = e.id WHERE i.classe_id = :cid AND e.sexe = 'F' AND i.statut = 'Actif'",
            ['cid' => $classId]
        );
        return ['boys' => $boys, 'girls' => $girls];
    }

    public function recentAbsences(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.nom, e.prenom, a.date_absence, a.type_absence, a.est_justifiee, a.motif
             FROM absences a
             INNER JOIN inscriptions i ON a.eleve_id = i.eleve_id
             INNER JOIN eleves e ON a.eleve_id = e.id
             WHERE i.classe_id = :cid AND a.type_absence = 'Absence'
             ORDER BY a.date_absence DESC
             LIMIT 5",
            ['cid' => $classId]
        );
    }

    public function upcomingExams(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT ev.type_evaluation, ev.date_evaluation, m.libelle as matiere
             FROM evaluations ev
             INNER JOIN matieres m ON ev.groupe_id = m.id
             WHERE ev.classe_id = :cid AND ev.date_evaluation >= CURDATE()
             ORDER BY ev.date_evaluation ASC
             LIMIT 5",
            ['cid' => $classId]
        );
    }

    public function studentsInDifficulty(int $classId, int $termId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.nom, e.prenom, r.moyenne_generale
             FROM resultats_trimestriels r
             INNER JOIN inscriptions i ON r.eleve_id = i.eleve_id
             INNER JOIN eleves e ON r.eleve_id = e.id
             WHERE i.classe_id = :cid AND r.trimestre_id = :tid AND r.moyenne_generale < 10
             ORDER BY r.moyenne_generale ASC
             LIMIT 5",
            ['cid' => $classId, 'tid' => $termId]
        );
    }

    public function upcomingBirthdays(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.nom, e.prenom, DATE_FORMAT(e.date_naissance, '%d/%m') as date_naissance,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) + 1 as age
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id
             WHERE i.classe_id = :cid AND i.statut = 'Actif'
             AND DATE_FORMAT(e.date_naissance, '%m-%d')
                 BETWEEN DATE_FORMAT(CURDATE(), '%m-%d')
                 AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d')
             ORDER BY DATE_FORMAT(e.date_naissance, '%m-%d')
             LIMIT 5",
            ['cid' => $classId]
        );
    }
}
