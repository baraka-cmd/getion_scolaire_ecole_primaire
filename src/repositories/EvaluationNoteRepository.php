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
}
