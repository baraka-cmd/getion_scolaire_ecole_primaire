<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class EnseignantRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findForAjax(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    DATE_FORMAT(e.date_embauche, '%d/%m/%Y') AS date_embauche_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                    TIMESTAMPDIFF(YEAR, e.date_embauche, CURDATE()) AS anciennete,
                    c.nom AS classe_nom, c.niveau AS classe_niveau, c.salle AS classe_salle,
                    c.capacite_max AS classe_capacite,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves,
                    u.login AS compte_login, u.email AS compte_email
             FROM enseignants e
             LEFT JOIN classes c ON e.classe_id = c.id
             LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
             WHERE e.id = :id",
            ['id' => $id]
        );
    }

    public function findDetail(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    DATE_FORMAT(e.date_embauche, '%d/%m/%Y') AS date_embauche_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                    TIMESTAMPDIFF(YEAR, e.date_embauche, CURDATE()) AS anciennete,
                    c.nom AS classe_nom, c.niveau AS classe_niveau, c.salle AS classe_salle,
                    c.capacite_max AS classe_capacite,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves,
                    u.login AS compte_login
             FROM enseignants e
             LEFT JOIN classes c ON e.classe_id = c.id
             LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
             WHERE e.id = :id",
            ['id' => $id]
        );
    }

    public function findClassId(int $teacherId): mixed
    {
        return $this->db->fetchColumn(
            'SELECT classe_id FROM enseignants WHERE id = :id',
            ['id' => $teacherId]
        );
    }

    public function findStudentsByClass(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :class_id AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom",
            ['class_id' => $classId]
        );
    }

    public function findTimetableByClass(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT edt.jour, edt.heure_debut, edt.heure_fin, m.libelle AS matiere
             FROM emploi_du_temps edt
             INNER JOIN matieres m ON edt.matiere_id = m.id
             WHERE edt.classe_id = :class_id
             ORDER BY FIELD(edt.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'), edt.heure_debut",
            ['class_id' => $classId]
        );
    }

    public function findActiveClasses(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.salle,
                    (SELECT COUNT(*) FROM enseignants e WHERE e.classe_id = c.id AND e.est_actif = 1) AS a_titulaire
             FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    public function findAvailableClasses(int $excludeTeacherId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.salle
             FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
               AND c.id NOT IN (
                   SELECT COALESCE(classe_id, 0) FROM enseignants
                   WHERE est_actif = 1 AND id != :teacher_id
               )
             ORDER BY c.niveau",
            ['teacher_id' => $excludeTeacherId]
        );
    }

    public function findOtherActiveTeacherIdForClass(int $classId, int $excludeTeacherId): mixed
    {
        return $this->db->fetchColumn(
            'SELECT id FROM enseignants
             WHERE classe_id = :class_id AND id != :teacher_id AND est_actif = 1',
            ['class_id' => $classId, 'teacher_id' => $excludeTeacherId]
        );
    }

    public function updateClassAssignment(int $teacherId, ?int $classId): void
    {
        $this->db->update(
            'UPDATE enseignants SET classe_id = :class_id WHERE id = :teacher_id',
            ['class_id' => $classId, 'teacher_id' => $teacherId]
        );
    }

    public function findSpecialties(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT specialite
             FROM enseignants
             WHERE specialite IS NOT NULL AND specialite != ''
             ORDER BY specialite"
        );
    }
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        $search = $filters['recherche'] ?? '';
        $specialty = $filters['specialite'] ?? '';
        $status = $filters['statut'] ?? '';

        if (!empty($search)) {
            $where[] = "(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3 OR e.email LIKE :r4 OR e.specialite LIKE :r5)";
            foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $key) {
                $params[$key] = "%{$search}%";
            }
        }
        if (!empty($specialty)) {
            $where[] = "e.specialite = :specialite";
            $params['specialite'] = $specialty;
        }
        if ($status !== '') {
            $where[] = "e.est_actif = :statut";
            $params['statut'] = (int) $status;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $order = $filters['ordre'] ?? 'nom';
        $direction = strtoupper((string) ($filters['direction'] ?? 'ASC'));
        $allowed = ['id', 'nom', 'prenom', 'matricule', 'specialite', 'classe_nom', 'date_embauche'];
        if (!in_array($order, $allowed, true)) $order = 'nom';
        if (!in_array($direction, ['ASC', 'DESC'], true)) $direction = 'ASC';
        $orderColumn = $order === 'classe_nom' ? 'c.nom' : 'e.' . $order;

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM enseignants e LEFT JOIN classes c ON e.classe_id = c.id {$whereClause}",
            $params
        );
        $rows = $this->db->fetchAll(
            "SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau,
                    DATE_FORMAT(e.date_embauche, '%d/%m/%Y') AS date_embauche_format,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'Actif') AS nb_eleves
             FROM enseignants e LEFT JOIN classes c ON e.classe_id = c.id
             {$whereClause}
             ORDER BY {$orderColumn} {$direction}
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => ($page - 1) * $perPage])
        );
        return ['enseignants' => $rows, 'total' => $total];
    }
}
