<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class EvaluationNoteRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findNoteId(int $evaluationId, int $studentId, int $subjectId): mixed
    {
        return $this->db->fetchColumn(
            'SELECT id FROM notes
             WHERE evaluation_id = :evaluation_id
               AND eleve_id = :student_id
               AND sous_matiere_id = :subject_id',
            [
                'evaluation_id' => $evaluationId,
                'student_id' => $studentId,
                'subject_id' => $subjectId,
            ]
        );
    }

    public function updateNote(int $noteId, ?float $value, int $absence): void
    {
        $this->db->update(
            'UPDATE notes
             SET valeur = :value, absence = :absence, date_modification = NOW()
             WHERE id = :id',
            ['value' => $value, 'absence' => $absence, 'id' => $noteId]
        );
    }

    public function deleteNote(int $noteId): void
    {
        $this->db->delete(
            'DELETE FROM notes WHERE id = :id',
            ['id' => $noteId]
        );
    }

    public function createNote(
        int $studentId,
        int $evaluationId,
        int $subjectId,
        ?float $value,
        int $absence,
        int $userId
    ): void {
        $this->db->insert(
            'INSERT INTO notes
                (eleve_id, evaluation_id, sous_matiere_id, valeur, absence, saisie_par, date_saisie)
             VALUES (:student_id, :evaluation_id, :subject_id, :value, :absence, :user_id, NOW())',
            [
                'student_id' => $studentId,
                'evaluation_id' => $evaluationId,
                'subject_id' => $subjectId,
                'value' => $value,
                'absence' => $absence,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * Contexte cohérent d'une évaluation pour la saisie des notes.
     * La classe doit appartenir à la même année que le trimestre.
     */
    public function findEvaluationEntryContext(int $evaluationId): ?array
    {
        return $this->db->fetchOne(
            "SELECT ev.id, ev.classe_id, ev.groupe_id, ev.type_evaluation,
                    c.annee_scolaire_id
             FROM evaluations ev
             INNER JOIN trimestres t ON t.id = ev.trimestre_id
             INNER JOIN classes c ON c.id = ev.classe_id
                 AND c.annee_scolaire_id = t.annee_scolaire_id
             WHERE ev.id = :id",
            ['id' => $evaluationId]
        );
    }

    public function findActiveStudentIdsForEvaluation(int $classId, int $yearId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll(
                "SELECT i.eleve_id
                 FROM inscriptions i
                 INNER JOIN eleves e ON e.id = i.eleve_id AND e.est_actif = 1
                 WHERE i.classe_id = :class_id
                   AND i.annee_scolaire_id = :year_id
                   AND i.statut = 'Actif'",
                ['class_id' => $classId, 'year_id' => $yearId]
            ),
            'eleve_id'
        ));
    }

    public function findActiveSubjectMaximaForEvaluation(int $classId, int $groupId, string $evaluationType): array
    {
        $rows = $this->db->fetchAll(
            "SELECT sm.id, COALESCE(cm.maxima_override, sm.maxima) AS maxima
             FROM matieres sm
             INNER JOIN classe_matieres cm ON cm.matiere_id = sm.id
                 AND cm.classe_id = :class_id AND cm.est_active = 1
             WHERE sm.parent_id = :group_id AND sm.est_groupe = 0 AND sm.est_actif = 1",
            ['class_id' => $classId, 'group_id' => $groupId]
        );

        $multiplier = $evaluationType === 'Examen' ? 2 : 1;
        $maxima = [];
        foreach ($rows as $row) {
            $maxima[(int) $row['id']] = (float) $row['maxima'] * $multiplier;
        }
        return $maxima;
    }

    public function findStudentNotes(int $studentId, int $trimesterId = 0): array
    {
        $trimesterFilter = $trimesterId > 0 ? 'AND ev.trimestre_id = :term_id' : '';
        $params = ['student_id' => $studentId];
        if ($trimesterId > 0) {
            $params['term_id'] = $trimesterId;
        }

        return $this->db->fetchAll(
            "SELECT n.valeur, n.absence,
                    ev.type_evaluation,
                    DATE_FORMAT(ev.date_evaluation, '%d/%m/%Y') AS date_eval,
                    sm.libelle AS sous_matiere,
                    sm.maxima,
                    m.libelle AS groupe,
                    t.libelle AS trimestre
             FROM notes n
             INNER JOIN evaluations ev ON n.evaluation_id = ev.id
             INNER JOIN matieres sm ON n.sous_matiere_id = sm.id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             WHERE n.eleve_id = :student_id {$trimesterFilter}
             ORDER BY t.numero, m.libelle, sm.libelle,
                      FIELD(ev.type_evaluation, 'Période 1', 'Période 2', 'Examen')",
            $params
        );
    }
    public function findEvaluationList(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['annee_id'] ?? 0) > 0) {
            $where[] = "t.annee_scolaire_id = :aid";
            $params['aid'] = (int) $filters['annee_id'];
        }
        if (($filters['trimestre_id'] ?? 0) > 0) {
            $where[] = "ev.trimestre_id = :tid";
            $params['tid'] = (int) $filters['trimestre_id'];
        }
        if (($filters['classe_id'] ?? 0) > 0) {
            $where[] = "ev.classe_id = :cid";
            $params['cid'] = (int) $filters['classe_id'];
        }
        if (($filters['groupe_id'] ?? 0) > 0) {
            $where[] = "ev.groupe_id = :gid";
            $params['gid'] = (int) $filters['groupe_id'];
        }
        $allowedTypes = ['Période 1', 'Période 2', 'Examen'];
        if (in_array($filters['type_evaluation'] ?? '', $allowedTypes, true)) {
            $where[] = "ev.type_evaluation = :type";
            $params['type'] = $filters['type_evaluation'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $this->db->fetchAll(
            "SELECT ev.*,
                    t.libelle AS trimestre_libelle,
                    t.numero AS trimestre_numero,
                    t.est_actif AS trimestre_actif,
                    c.nom AS classe_nom,
                    c.niveau AS classe_niveau,
                    m.libelle AS groupe_libelle,
                    m.code AS groupe_code,
                    (SELECT COUNT(*) FROM notes n WHERE n.evaluation_id = ev.id) AS nb_notes_saisies,
                    (SELECT COUNT(*) FROM inscriptions i
                     WHERE i.classe_id = ev.classe_id AND i.annee_scolaire_id = c.annee_scolaire_id
                       AND i.statut = 'Actif')
                    * (SELECT COUNT(*)
                       FROM classe_matieres cm
                       INNER JOIN matieres sm ON sm.id = cm.matiere_id
                       WHERE cm.classe_id = ev.classe_id AND cm.est_active = 1
                         AND sm.parent_id = ev.groupe_id AND sm.est_groupe = 0 AND sm.est_actif = 1) AS nb_notes_attendues
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             {$whereClause}
             ORDER BY t.numero, c.niveau, FIELD(ev.type_evaluation, 'Période 1', 'Période 2', 'Examen')",
            $params
        );
    }

    public function findEvaluationDetails(int $evaluationId): ?array
    {
        return $this->db->fetchOne(
            "SELECT ev.*,
                    t.libelle AS trimestre_libelle,
                    c.nom AS classe_nom,
                    m.libelle AS groupe_libelle
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             WHERE ev.id = :id",
            ['id' => $evaluationId]
        );
    }


    public function isClassAndTermConsistent(int $classId, int $termId): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes c
             INNER JOIN trimestres t ON t.annee_scolaire_id = c.annee_scolaire_id
             WHERE c.id = :cid AND t.id = :tid",
            ['cid' => $classId, 'tid' => $termId]
        ) === 1;
    }

    public function evaluationExists(int $termId, int $classId, int $groupId, string $type): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM evaluations
             WHERE trimestre_id = :tid AND classe_id = :cid AND groupe_id = :gid AND type_evaluation = :type",
            ['tid' => $termId, 'cid' => $classId, 'gid' => $groupId, 'type' => $type]
        ) > 0;
    }

    public function insertEvaluation(int $termId, int $groupId, int $classId, string $type, string $date): int
    {
        return (int) $this->db->insert(
            "INSERT INTO evaluations (trimestre_id, groupe_id, classe_id, type_evaluation, date_evaluation)
             VALUES (:tid, :gid, :cid, :type, :date_eval)",
            ['tid' => $termId, 'gid' => $groupId, 'cid' => $classId, 'type' => $type, 'date_eval' => $date]
        );
    }

    public function evaluationIdExists(int $evaluationId): bool
    {
        return (bool) $this->db->fetchOne("SELECT id FROM evaluations WHERE id = :id", ['id' => $evaluationId]);
    }

    public function countNotesForEvaluation(int $evaluationId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notes WHERE evaluation_id = :eid",
            ['eid' => $evaluationId]
        );
    }

    public function updateEvaluationDate(int $evaluationId, string $date): void
    {
        $this->db->update(
            "UPDATE evaluations SET date_evaluation = :date_eval WHERE id = :id",
            ['date_eval' => $date, 'id' => $evaluationId]
        );
    }

    public function deleteEvaluation(int $evaluationId): void
    {
        $this->db->delete("DELETE FROM evaluations WHERE id = :id", ['id' => $evaluationId]);
    }


    public function findEvaluationGridContext(int $evaluationId): ?array
    {
        return $this->db->fetchOne("SELECT ev.*, t.libelle AS trimestre_libelle, t.numero AS trimestre_numero, c.nom AS classe_nom, c.niveau AS classe_niveau, c.annee_scolaire_id, m.libelle AS groupe_libelle, m.id AS groupe_id FROM evaluations ev INNER JOIN trimestres t ON ev.trimestre_id = t.id INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id INNER JOIN matieres m ON ev.groupe_id = m.id WHERE ev.id = :id", ['id' => $evaluationId]);
    }

    public function findActiveSubjectsForClassGroup(int $classId, int $groupId): array
    {
        return $this->db->fetchAll("SELECT m.id, m.libelle, m.code, COALESCE(cm.maxima_override, m.maxima) AS maxima FROM matieres m INNER JOIN classe_matieres cm ON m.id = cm.matiere_id AND cm.classe_id = :cid AND cm.est_active = 1 WHERE m.parent_id = :gid AND m.est_groupe = 0 AND m.est_actif = 1 ORDER BY m.id", ['gid' => $groupId, 'cid' => $classId]);
    }

    public function findActiveStudentsForClassYear(int $classId, int $yearId): array
    {
        return $this->db->fetchAll("SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe FROM inscriptions i INNER JOIN eleves e ON i.eleve_id = e.id INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid AND i.statut = 'Actif' AND e.est_actif = 1 ORDER BY e.nom, e.prenom", ['cid' => $classId, 'aid' => $yearId]);
    }

    public function findNotesForEvaluationGrid(int $evaluationId): array
    {
        return $this->db->fetchAll("SELECT n.id, n.eleve_id, n.sous_matiere_id, n.valeur, n.absence FROM notes n WHERE n.evaluation_id = :eid", ['eid' => $evaluationId]);
    }

    public function findTermForGeneration(int $classId, int $termId): ?array
    {
        return $this->db->fetchOne("SELECT t.* FROM trimestres t INNER JOIN classes c ON c.annee_scolaire_id = t.annee_scolaire_id WHERE t.id = :tid AND c.id = :cid", ['tid' => $termId, 'cid' => $classId]);
    }

    public function findActiveGroupsForEvaluationGeneration(): array
    {
        return $this->db->fetchAll("SELECT m.id, m.libelle FROM matieres m WHERE m.est_groupe = 1 AND m.est_actif = 1 ORDER BY m.id");
    }

    public function findEvaluationAccessContext(int $evaluationId): ?array
    {
        return $this->db->fetchOne("SELECT ev.classe_id, t.annee_scolaire_id FROM evaluations ev INNER JOIN trimestres t ON t.id = ev.trimestre_id INNER JOIN classes c ON c.id = ev.classe_id AND c.annee_scolaire_id = t.annee_scolaire_id WHERE ev.id = :id", ['id' => $evaluationId]);
    }

    public function teacherHasClassAssignment(int $userId, int $classId, int $yearId): bool
    {
        return (bool) $this->db->fetchColumn("SELECT EXISTS (SELECT 1 FROM enseignants ens INNER JOIN classes c ON c.id = ens.classe_id WHERE ens.utilisateur_id = :user_id AND ens.est_actif = 1 AND c.id = :class_id AND c.annee_scolaire_id = :year_id AND c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1))", ['user_id'=>$userId,'class_id'=>$classId,'year_id'=>$yearId]);
    }

    public function findEvaluationIdsForProgression(int $termId, int $classId, string $type): array
    {
        return $this->db->fetchAll("SELECT id FROM evaluations WHERE trimestre_id = :tid AND classe_id = :cid AND type_evaluation = :type", ['tid'=>$termId,'cid'=>$classId,'type'=>$type]);
    }

    public function countNotesForEvaluationIds(array $evaluationIds): int
    {
        if (!$evaluationIds) return 0;
        $marks = implode(',', array_fill(0, count($evaluationIds), '?'));
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM notes WHERE evaluation_id IN ({$marks})", array_values($evaluationIds));
    }

    public function countActiveStudentsInClass(int $classId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions i INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id WHERE i.classe_id = :cid AND i.statut = 'Actif'", ['cid'=>$classId]);
    }

    public function countActiveSubjectsForClassGroup(int $classId, int $groupId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM classe_matieres cm INNER JOIN matieres sm ON sm.id = cm.matiere_id WHERE cm.classe_id = :cid AND cm.est_active = 1 AND sm.parent_id = :gid AND sm.est_groupe = 0 AND sm.est_actif = 1", ['cid'=>$classId,'gid'=>$groupId]);
    }

    public function findActiveClasses(): array
    {
        return $this->db->fetchAll("SELECT c.* FROM classes c WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1) ORDER BY c.niveau");
    }

    public function findActiveTerms(): array
    {
        return $this->db->fetchAll("SELECT t.* FROM trimestres t WHERE t.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1) ORDER BY t.numero");
    }

    public function findCurrentActiveTerm(): ?array
    {
        return $this->db->fetchOne("SELECT t.* FROM trimestres t WHERE t.est_actif = 1 AND t.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1) LIMIT 1");
    }

    public function findActiveGroupsWithSubjectCounts(): array
    {
        return $this->db->fetchAll("SELECT m.*, (SELECT COUNT(*) FROM matieres sm WHERE sm.parent_id = m.id AND sm.est_groupe = 0 AND sm.est_actif = 1) AS nb_sous_matieres FROM matieres m WHERE m.est_groupe = 1 AND m.est_actif = 1 ORDER BY m.libelle");
    }

    public function findSubjectsForClassGroup(int $groupId, int $classId): array
    {
        return $this->findActiveSubjectsForClassGroup($classId, $groupId);
    }

    public function findEvaluationById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT ev.*, t.libelle AS trimestre_libelle, c.nom AS classe_nom, m.libelle AS groupe_libelle FROM evaluations ev INNER JOIN trimestres t ON ev.trimestre_id = t.id INNER JOIN classes c ON c.id = ev.classe_id INNER JOIN matieres m ON m.id = ev.groupe_id WHERE ev.id = :id", ['id'=>$id]);
    }
}
