<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AcademicResultsRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findTermsByYear(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT id, annee_scolaire_id, numero, libelle, date_debut, date_fin
             FROM trimestres
             WHERE annee_scolaire_id = :year_id
             ORDER BY numero",
            ['year_id' => $yearId]
        );
    }

    public function findSubjectGroupRows(int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT g.id AS groupe_id, g.libelle AS groupe_libelle, g.code AS groupe_code,
                    m.id AS sous_matiere_id, m.libelle AS sous_matiere_libelle,
                    m.code AS sous_matiere_code,
                    COALESCE(cm.maxima_override, m.maxima) AS maxima_periode
             FROM classe_matieres cm
             INNER JOIN matieres m ON m.id = cm.matiere_id
             INNER JOIN matieres g ON g.id = m.parent_id AND g.est_groupe = 1 AND g.est_actif = 1
             WHERE cm.classe_id = :class_id AND cm.est_active = 1
               AND m.est_groupe = 0 AND m.est_actif = 1
             ORDER BY g.id, m.id",
            ['class_id' => $classId]
        );
    }

    public function findAnnualGradeCoverageRows(
        int $classId,
        array $trimesterIds,
        array $groupIds
    ): array {
        $trimesterPlaceholders = [];
        $params = ['class_id' => $classId];
        foreach (array_values($trimesterIds) as $index => $trimesterId) {
            $placeholder = 'term_' . $index;
            $trimesterPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $trimesterId;
        }

        $groupPlaceholders = [];
        foreach (array_values(array_unique($groupIds)) as $index => $groupId) {
            $placeholder = 'group_' . $index;
            $groupPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $groupId;
        }

        return $this->db->fetchAll(
            "SELECT n.eleve_id,
                    COUNT(DISTINCT CONCAT(ev.trimestre_id, ':', ev.type_evaluation, ':', m.id)) AS notes_completees
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             INNER JOIN matieres m ON m.id = n.sous_matiere_id
                 AND m.parent_id = ev.groupe_id AND m.est_groupe = 0 AND m.est_actif = 1
             INNER JOIN classe_matieres cm ON cm.classe_id = ev.classe_id
                 AND cm.matiere_id = m.id AND cm.est_active = 1
             WHERE ev.classe_id = :class_id
               AND ev.trimestre_id IN (" . implode(', ', $trimesterPlaceholders) . ")
               AND ev.groupe_id IN (" . implode(', ', $groupPlaceholders) . ")
               AND ev.type_evaluation IN ('Période 1', 'Période 2', 'Examen')
               AND (n.absence = 1 OR n.valeur IS NOT NULL)
             GROUP BY n.eleve_id",
            $params
        );
    }

    public function findTerm(int $termId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, annee_scolaire_id, numero, libelle
             FROM trimestres
             WHERE id = :term_id",
            ['term_id' => $termId]
        );
    }

    public function findQuarterScoreRows(int $termId, int $classId): array
    {
        return $this->db->fetchAll(
            "SELECT n.eleve_id, n.sous_matiere_id,
                    COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Période 1'
                        THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS p1,
                    COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Période 2'
                        THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS p2,
                    COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Examen'
                        THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS examen
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             WHERE ev.trimestre_id = :term_id AND ev.classe_id = :class_id
             GROUP BY n.eleve_id, n.sous_matiere_id",
            ['term_id' => $termId, 'class_id' => $classId]
        );
    }

    public function findClassForYear(int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, annee_scolaire_id
             FROM classes
             WHERE id = :class_id AND annee_scolaire_id = :year_id',
            ['class_id' => $classId, 'year_id' => $yearId]
        );
    }

    public function findAnnualStudents(int $classId, int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :class_id
               AND i.annee_scolaire_id = :year_id
               AND i.statut IN ('Actif', 'Redoublant')
               AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['class_id' => $classId, 'year_id' => $yearId]
        );
    }

    public function findAnnualParticipation(int $classId, array $termIds): array
    {
        if ($termIds === []) {
            return [];
        }
        $placeholders = [];
        $params = ['class_id' => $classId];
        foreach (array_values($termIds) as $index => $termId) {
            $placeholder = 'term_' . $index;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $termId;
        }
        return $this->db->fetchAll(
            "SELECT n.eleve_id,
                    SUM(CASE WHEN n.absence = 0 THEN 1 ELSE 0 END) AS notes_present,
                    SUM(CASE WHEN n.absence = 1 THEN 1 ELSE 0 END) AS notes_absence
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             WHERE ev.classe_id = :class_id
               AND ev.trimestre_id IN (" . implode(', ', $placeholders) . ")
             GROUP BY n.eleve_id",
            $params
        );
    }
}
