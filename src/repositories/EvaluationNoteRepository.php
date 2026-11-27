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
}
