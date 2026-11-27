<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

/**
 * Persistance en lecture des listes et dossiers d'élèves.
 * Les valeurs de tri SQL sont sélectionnées exclusivement dans une whitelist.
 */
class StudentRecordRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function paginate(array $filters, int $page, int $perPage, string $role, int $userId): array
    {
        $where = [
            "i.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)"
        ];
        $params = [];

        $search = (string) ($filters['recherche'] ?? '');
        if ($search !== '') {
            $where[] = "(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)";
            $params['r1'] = "%{$search}%";
            $params['r2'] = "%{$search}%";
            $params['r3'] = "%{$search}%";
        }

        $classId = (int) ($filters['classe_id'] ?? 0);
        if ($classId > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $classId;
        }

        if ($role === 'Parent') {
            $where[] = "EXISTS (
                SELECT 1 FROM eleve_parent ep
                INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                WHERE ep.eleve_id = e.id AND pt.utilisateur_id = :parent_user_id
            )";
            $params['parent_user_id'] = $userId;
        } elseif ($role === 'Enseignant') {
            $where[] = "EXISTS (
                SELECT 1 FROM enseignants ens
                WHERE ens.utilisateur_id = :teacher_user_id AND ens.est_actif = 1
                  AND ens.classe_id = i.classe_id
            )";
            $params['teacher_user_id'] = $userId;
        }

        $sex = (string) ($filters['sexe'] ?? '');
        if ($sex !== '') {
            $where[] = 'e.sexe = :sexe';
            $params['sexe'] = $sex;
        }

        $status = $filters['statut'] ?? '';
        if ($status !== '') {
            $where[] = 'e.est_actif = :statut';
            $params['statut'] = (int) $status;
        } else {
            $where[] = 'e.est_actif = 1';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $allowedColumns = ['id', 'nom', 'prenom', 'matricule', 'date_naissance', 'sexe', 'classe_nom'];
        $order = (string) ($filters['ordre'] ?? 'nom');
        if (!in_array($order, $allowedColumns, true)) {
            $order = 'nom';
        }
        $direction = strtoupper((string) ($filters['direction'] ?? 'ASC'));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }
        $orderColumn = $order === 'classe_nom' ? 'c.nom' : 'e.' . $order;

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe, e.photo,
                    e.telephone, e.est_actif, e.date_inscription,
                    c.nom AS classe_nom, c.id AS classe_id,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             {$whereClause}
             ORDER BY {$orderColumn} {$direction}
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function findRecord(int $studentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                    c.nom AS classe_nom, c.id AS classe_id, c.niveau AS classe_niveau,
                    i.date_inscription AS date_inscription_classe,
                    i.statut AS statut_inscription,
                    CONCAT(COALESCE(ens.prenom, ''), ' ', COALESCE(ens.nom, '')) AS titulaire_nom
             FROM eleves e
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             LEFT JOIN classes c ON i.classe_id = c.id
             LEFT JOIN enseignants ens ON c.id = ens.classe_id AND ens.est_actif = 1
             WHERE e.id = :id",
            ['id' => $studentId]
        );
    }

    public function recentGrades(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT n.valeur, ev.type_evaluation, ev.date_evaluation, m.libelle AS matiere,
                    t.libelle AS trimestre
             FROM notes n
             INNER JOIN evaluations ev ON n.evaluation_id = ev.id
             INNER JOIN matieres m ON ev.matiere_id = m.id
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             WHERE n.eleve_id = :eid
             ORDER BY ev.date_evaluation DESC
             LIMIT 20",
            ['eid' => $studentId]
        );
    }

    public function recentAbsences(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT date_absence, type_absence, est_justifiee, motif
             FROM absences
             WHERE eleve_id = :eid
             ORDER BY date_absence DESC
             LIMIT 10",
            ['eid' => $studentId]
        );
    }

    public function payments(int $studentId): array
    {
        return $this->db->fetchAll(
            "SELECT p.montant_total, p.montant_paye, p.statut, p.date_paiement, p.mode_paiement,
                    f.libelle AS frais_libelle
             FROM paiements p
             INNER JOIN frais_scolaires f ON p.frais_scolaire_id = f.id
             WHERE p.eleve_id = :eid
             ORDER BY p.date_paiement DESC",
            ['eid' => $studentId]
        );
    }
}
