<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

/** Requêtes de contrôle d'accès aux dossiers élèves. */
class StudentAccessRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findActiveRole(int $userId): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id AND u.est_actif = 1 AND r.est_actif = 1",
            ['user_id' => $userId]
        );
        return $row ? (string) $row['role_nom'] : null;
    }

    public function parentLinkedToStudent(int $userId, int $studentId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT EXISTS (
                SELECT 1 FROM parents_tuteurs pt
                INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
                WHERE pt.utilisateur_id = :user_id AND ep.eleve_id = :student_id
            )",
            ['user_id' => $userId, 'student_id' => $studentId]
        );
    }

    public function teacherAssignedToStudent(int $userId, int $studentId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT EXISTS (
                SELECT 1 FROM enseignants ens
                INNER JOIN inscriptions i ON i.classe_id = ens.classe_id
                WHERE ens.utilisateur_id = :user_id
                  AND ens.est_actif = 1
                  AND i.eleve_id = :student_id
                  AND i.annee_scolaire_id = (
                      SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
                  )
                  AND i.statut IN ('Actif', 'Redoublant')
            )",
            ['user_id' => $userId, 'student_id' => $studentId]
        );
    }
}
