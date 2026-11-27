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
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut = 'Actif') AS nb_inscrits
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
                    (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id AND statut = 'Actif') AS nb_inscrits
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
}
