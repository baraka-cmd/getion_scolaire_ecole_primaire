<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class ParentStudentRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findParentIdByTelephone(string $telephone): ?int
    {
        $parent = $this->db->fetchOne(
            'SELECT id FROM parents_tuteurs WHERE telephone_principal = :telephone LIMIT 1',
            ['telephone' => $telephone]
        );

        return $parent ? (int) $parent['id'] : null;
    }

    public function createParent(
        string $nom,
        string $prenom,
        string $lien,
        string $telephone,
        ?string $email,
        ?string $profession
    ): int {
        return $this->db->insert(
            'INSERT INTO parents_tuteurs
                (nom, prenom, lien, profession, telephone_principal, email, est_contact_urgence)
             VALUES (:nom, :prenom, :lien, :profession, :telephone, :email, 1)',
            [
                'nom' => $nom,
                'prenom' => $prenom,
                'lien' => $lien,
                'profession' => $profession,
                'telephone' => $telephone,
                'email' => $email,
            ]
        );
    }

    public function hasLink(int $studentId, int $parentId): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM eleve_parent WHERE eleve_id = :student_id AND parent_id = :parent_id',
            ['student_id' => $studentId, 'parent_id' => $parentId]
        ) > 0;
    }

    public function createLink(int $studentId, int $parentId, int $isPrimary): void
    {
        $this->db->query(
            'INSERT INTO eleve_parent (eleve_id, parent_id, est_tuteur_principal)
             VALUES (:student_id, :parent_id, :is_primary)',
            [
                'student_id' => $studentId,
                'parent_id' => $parentId,
                'is_primary' => $isPrimary,
            ]
        );
    }

    public function unsetPrimaryLinks(int $parentId): void
    {
        $this->db->update(
            'UPDATE eleve_parent SET est_tuteur_principal = 0 WHERE parent_id = :parent_id',
            ['parent_id' => $parentId]
        );
    }

    public function countLinksForStudent(int $studentId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM eleve_parent WHERE eleve_id = :student_id',
            ['student_id' => $studentId]
        );
    }

    public function deleteLink(int $studentId, int $parentId): void
    {
        $this->db->delete(
            'DELETE FROM eleve_parent WHERE eleve_id = :student_id AND parent_id = :parent_id',
            ['student_id' => $studentId, 'parent_id' => $parentId]
        );
    }

    public function getStudentParentsForDetail(int $studentId): array
    {
        return $this->db->fetchAll(
            'SELECT pt.id, pt.nom, pt.prenom, pt.lien, pt.telephone_principal, pt.email, pt.profession,
                    ep.est_tuteur_principal
             FROM parents_tuteurs pt
             INNER JOIN eleve_parent ep ON pt.id = ep.parent_id
             WHERE ep.eleve_id = :student_id
             ORDER BY ep.est_tuteur_principal DESC',
            ['student_id' => $studentId]
        );
    }

    public function getStudentParents(int $studentId): array
    {
        return $this->db->fetchAll(
            'SELECT pt.*, ep.est_tuteur_principal
             FROM parents_tuteurs pt
             INNER JOIN eleve_parent ep ON pt.id = ep.parent_id
             WHERE ep.eleve_id = :student_id
             ORDER BY ep.est_tuteur_principal DESC',
            ['student_id' => $studentId]
        );
    }

    public function getStudentParentsForAjax(int $studentId): array
    {
        return $this->db->fetchAll(
            'SELECT pt.*, ep.est_tuteur_principal
             FROM parents_tuteurs pt
             INNER JOIN eleve_parent ep ON pt.id = ep.parent_id
             WHERE ep.eleve_id = :student_id',
            ['student_id' => $studentId]
        );
    }

    public function getParentChildrenForDetail(int $parentId): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe,
                    DATE_FORMAT(e.date_naissance, \'%d/%m/%Y\') AS date_naissance_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                    c.nom AS classe_nom, c.niveau AS classe_niveau,
                    ep.est_tuteur_principal
             FROM eleve_parent ep
             INNER JOIN eleves e ON ep.eleve_id = e.id AND e.est_actif = 1
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = \'Actif\'
             LEFT JOIN classes c ON i.classe_id = c.id
             WHERE ep.parent_id = :parent_id
             ORDER BY ep.est_tuteur_principal DESC, e.nom',
            ['parent_id' => $parentId]
        );
    }

    public function getParentChildren(int $parentId): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe,
                    c.nom AS classe_nom,
                    ep.est_tuteur_principal
             FROM eleve_parent ep
             INNER JOIN eleves e ON ep.eleve_id = e.id AND e.est_actif = 1
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = \'Actif\'
             LEFT JOIN classes c ON i.classe_id = c.id
             WHERE ep.parent_id = :parent_id
             ORDER BY e.nom',
            ['parent_id' => $parentId]
        );
    }

    public function getAvailableStudents(?int $parentId = null): array
    {
        if ($parentId !== null && $parentId > 0) {
            return $this->db->fetchAll(
                'SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe,
                        c.nom AS classe_nom
                 FROM eleves e
                 LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = \'Actif\'
                 LEFT JOIN classes c ON i.classe_id = c.id
                 WHERE e.est_actif = 1
                   AND e.id NOT IN (
                       SELECT eleve_id FROM eleve_parent WHERE parent_id = :parent_id
                   )
                 ORDER BY e.nom',
                ['parent_id' => $parentId]
            );
        }

        return $this->db->fetchAll(
            'SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe,
                    c.nom AS classe_nom
             FROM eleves e
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = \'Actif\'
             LEFT JOIN classes c ON i.classe_id = c.id
             WHERE e.est_actif = 1
             ORDER BY e.nom'
        );
    }
}
