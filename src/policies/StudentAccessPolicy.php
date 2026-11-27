<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class StudentAccessPolicy
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function canCurrentUserAccessStudent(int $studentId): bool
    {
        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        if ($studentId <= 0 || $userId <= 0) {
            return false;
        }

        $user = $this->db->fetchOne(
            "SELECT r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :user_id AND u.est_actif = 1 AND r.est_actif = 1",
            ['user_id' => $userId]
        );
        if (!$user) {
            return false;
        }

        if (in_array($user['role_nom'], ['Admin', 'Directeur'], true)) {
            return true;
        }

        if ($user['role_nom'] === 'Parent') {
            return (bool) $this->db->fetchColumn(
                "SELECT EXISTS (
                    SELECT 1
                    FROM parents_tuteurs pt
                    INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
                    WHERE pt.utilisateur_id = :user_id AND ep.eleve_id = :student_id
                )",
                ['user_id' => $userId, 'student_id' => $studentId]
            );
        }

        if ($user['role_nom'] === 'Enseignant') {
            return (bool) $this->db->fetchColumn(
                "SELECT EXISTS (
                    SELECT 1
                    FROM enseignants ens
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

        return function_exists('can') ? can('eleves.consulter') : false;
    }
}
