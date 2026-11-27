<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class MatiereRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findPaginatedGroupHierarchy(string $search, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' AND (m.libelle LIKE :search_label OR m.code LIKE :search_code OR m.description LIKE :search_description)';
            $term = '%' . $search . '%';
            $params = ['search_label' => $term, 'search_code' => $term, 'search_description' => $term];
        }

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM matieres m
             WHERE m.est_groupe = 1 AND m.est_actif = 1 {$where}",
            $params
        );
        $groups = $this->db->fetchAll(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM matieres sm WHERE sm.parent_id = m.id AND sm.est_actif = 1) AS nb_sous_matieres,
                    (SELECT COUNT(*) FROM classe_matieres cm
                     INNER JOIN matieres sm ON cm.matiere_id = sm.id
                     WHERE sm.parent_id = m.id AND cm.est_active = 1) AS nb_associations
             FROM matieres m
             WHERE m.est_groupe = 1 AND m.est_actif = 1 {$where}
             ORDER BY m.id
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );

        foreach ($groups as &$group) {
            $subjects = $this->db->fetchAll(
                "SELECT m.*,
                        (SELECT COUNT(*) FROM classe_matieres cm
                         WHERE cm.matiere_id = m.id AND cm.est_active = 1) AS nb_classes
                 FROM matieres m
                 WHERE m.parent_id = :parent_id AND m.est_groupe = 0 AND m.est_actif = 1
                 ORDER BY m.id",
                ['parent_id' => $group['id']]
            );
            foreach ($subjects as &$subject) {
                $subject['classes'] = $this->db->fetchAll(
                    "SELECT c.nom, c.niveau, COALESCE(cm.maxima_override, m.maxima) AS maxima_final
                     FROM classe_matieres cm
                     INNER JOIN classes c ON cm.classe_id = c.id
                     INNER JOIN matieres m ON cm.matiere_id = m.id
                     WHERE cm.matiere_id = :subject_id AND cm.est_active = 1
                       AND c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
                     ORDER BY c.niveau",
                    ['subject_id' => $subject['id']]
                );
            }
            unset($subject);
            $group['sous_matieres'] = $subjects;
        }
        unset($group);

        return ['total' => $total, 'groupes' => $groups];
    }

    public function countActiveLabel(string $label, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM matieres WHERE libelle = :label AND est_actif = 1';
        $params = ['label' => $label];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function countCode(string $code, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM matieres WHERE code = :code';
        $params = ['code' => $code];
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function isActiveGroup(int $groupId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM matieres WHERE id = :id AND est_groupe = 1 AND est_actif = 1',
            ['id' => $groupId]
        ) !== null;
    }

    public function countActiveChildren(int $groupId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM matieres WHERE parent_id = :parent_id AND est_actif = 1',
            ['parent_id' => $groupId]
        );
    }

    public function countEvaluationsForSubject(int $subjectId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM evaluations WHERE matiere_id = :subject_id',
            ['subject_id' => $subjectId]
        );
    }

    public function countTimetableEntriesForSubject(int $subjectId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM emploi_du_temps WHERE matiere_id = :subject_id',
            ['subject_id' => $subjectId]
        );
    }

    public function findGroups(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM matieres WHERE est_groupe = 1 AND est_actif = 1 ORDER BY libelle'
        );
    }

    public function findSubSubjectsByGroup(int $groupId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM matieres WHERE parent_id = :group_id AND est_groupe = 0 AND est_actif = 1 ORDER BY id',
            ['group_id' => $groupId]
        );
    }

    public function findActiveChildren(int $groupId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM matieres WHERE parent_id = :group_id AND est_actif = 1 ORDER BY id',
            ['group_id' => $groupId]
        );
    }

    public function findSubSubjectsWithGroups(): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, p.libelle AS groupe_libelle
             FROM matieres m
             INNER JOIN matieres p ON m.parent_id = p.id
             WHERE m.est_groupe = 0 AND m.est_actif = 1
             ORDER BY p.libelle, m.id'
        );
    }

    public function findGroupsForSelect(): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, libelle FROM matieres WHERE est_groupe = 1 AND est_actif = 1 ORDER BY libelle'
        );
    }

    public function findDetail(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.*, p.libelle AS parent_libelle
             FROM matieres m
             LEFT JOIN matieres p ON m.parent_id = p.id
             WHERE m.id = :id',
            ['id' => $id]
        );
    }

    public function findActiveClasses(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.salle
             FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    public function findTotalMaximaForClass(int $classId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(cm.maxima_override, m.maxima)), 0)
             FROM classe_matieres cm
             INNER JOIN matieres m ON cm.matiere_id = m.id
             WHERE cm.classe_id = :class_id AND cm.est_active = 1 AND m.est_actif = 1',
            ['class_id' => $classId]
        );
    }

    public function findScoringData(int $studentId, int $subjectId, int $termId): ?array
    {
        $subject = $this->db->fetchOne(
            'SELECT maxima FROM matieres WHERE id = :id',
            ['id' => $subjectId]
        );
        if (!$subject) {
            return null;
        }

        $scores = [];
        foreach (['Période 1' => 'periode_1', 'Période 2' => 'periode_2', 'Examen' => 'examen'] as $type => $key) {
            $scores[$key] = $this->db->fetchColumn(
                'SELECT n.valeur
                 FROM notes n
                 INNER JOIN evaluations ev ON n.evaluation_id = ev.id
                 WHERE n.eleve_id = :student_id AND ev.matiere_id = :subject_id
                   AND ev.trimestre_id = :term_id AND ev.type_evaluation = :type',
                [
                    'student_id' => $studentId,
                    'subject_id' => $subjectId,
                    'term_id' => $termId,
                    'type' => $type,
                ]
            );
        }

        return ['maxima' => $subject['maxima']] + $scores;
    }

    public function findClassesForSubject(int $subjectId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.salle,
                    cm.maxima_override, cm.est_active,
                    COALESCE(cm.maxima_override, :default_maxima) AS maxima_final
             FROM classes c
             LEFT JOIN classe_matieres cm ON c.id = cm.classe_id AND cm.matiere_id = :subject_id
             CROSS JOIN (SELECT maxima FROM matieres WHERE id = :subject_id_for_maxima) AS m
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau",
            [
                'subject_id' => $subjectId,
                'subject_id_for_maxima' => $subjectId,
                'default_maxima' => 20,
            ]
        );
    }

    public function findGlobalMaxima(int $subjectId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT maxima FROM matieres WHERE id = :subject_id',
            ['subject_id' => $subjectId]
        );
    }

    public function hasClassAssociation(int $subjectId, int $classId): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM classe_matieres WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['subject_id' => $subjectId, 'class_id' => $classId]
        ) > 0;
    }

    public function updateClassAssociation(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->db->update(
            'UPDATE classe_matieres
             SET est_active = 1, maxima_override = :maxima
             WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['maxima' => $maxima, 'subject_id' => $subjectId, 'class_id' => $classId]
        );
    }

    public function createClassAssociation(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->db->insert(
            'INSERT INTO classe_matieres (matiere_id, classe_id, maxima_override, est_active)
             VALUES (:subject_id, :class_id, :maxima, 1)',
            ['subject_id' => $subjectId, 'class_id' => $classId, 'maxima' => $maxima]
        );
    }

    public function countEvaluationsForClass(int $subjectId, int $classId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM evaluations WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['subject_id' => $subjectId, 'class_id' => $classId]
        );
    }

    public function deactivateClassAssociation(int $subjectId, int $classId): void
    {
        $this->db->update(
            'UPDATE classe_matieres SET est_active = 0
             WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['subject_id' => $subjectId, 'class_id' => $classId]
        );
    }

    public function deleteClassAssociation(int $subjectId, int $classId): void
    {
        $this->db->delete(
            'DELETE FROM classe_matieres WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['subject_id' => $subjectId, 'class_id' => $classId]
        );
    }

    public function updateClassMaxima(int $subjectId, int $classId, ?int $maxima): void
    {
        $this->db->update(
            'UPDATE classe_matieres SET maxima_override = :maxima
             WHERE matiere_id = :subject_id AND classe_id = :class_id',
            ['maxima' => $maxima, 'subject_id' => $subjectId, 'class_id' => $classId]
        );
    }
}
