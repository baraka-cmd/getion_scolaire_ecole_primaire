<?php

defined('ACCESS') or die('Accès interdit');

class AcademicResultsService
{
    private Database $db;
    private array $quarterNotesCache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public static function getDisplayPeriodMapping(string $label): ?array
    {
        $mapping = [
            'Période 1' => ['trimestre_numero' => 1, 'type' => 'Période 1', 'composante' => 'p1'],
            'Période 2' => ['trimestre_numero' => 1, 'type' => 'Période 2', 'composante' => 'p2'],
            'Période 3' => ['trimestre_numero' => 2, 'type' => 'Période 1', 'composante' => 'p1'],
            'Période 4' => ['trimestre_numero' => 2, 'type' => 'Période 2', 'composante' => 'p2'],
            'Période 5' => ['trimestre_numero' => 3, 'type' => 'Période 1', 'composante' => 'p1'],
            'Période 6' => ['trimestre_numero' => 3, 'type' => 'Période 2', 'composante' => 'p2'],
        ];

        return $mapping[$label] ?? null;
    }

    public static function calculatePercentage(float $obtained, float $maximum): float
    {
        return $maximum > 0 ? round(($obtained / $maximum) * 100, 1) : 0.0;
    }

    public static function determineAnnualDecision(float $obtained, float $maximum, bool $bulletinComplete): string
    {
        if (!$bulletinComplete || $maximum <= 0) {
            return 'Bulletin incomplet';
        }

        return ($obtained * 2) >= $maximum ? 'Passe' : 'Ajourné';
    }

    public static function calculateSubjectQuarterFromScores(
        float $maxPeriode,
        float $noteP1,
        float $noteP2,
        float $noteExamen
    ): array {
        return [
            'p1' => ['max' => $maxPeriode, 'obt' => $noteP1],
            'p2' => ['max' => $maxPeriode, 'obt' => $noteP2],
            'ex' => ['max' => $maxPeriode * 2, 'obt' => $noteExamen],
            'total_max' => $maxPeriode * 4,
            'total_obt' => $noteP1 + $noteP2 + $noteExamen,
        ];
    }

    public static function emptySubjectQuarter(float $maxPeriode): array
    {
        return self::calculateSubjectQuarterFromScores($maxPeriode, 0, 0, 0);
    }

    public function getTrimestresByYear(int $anneeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, annee_scolaire_id, numero, libelle, date_debut, date_fin
             FROM trimestres
             WHERE annee_scolaire_id = :aid
             ORDER BY numero",
            ['aid' => $anneeId]
        );

        $trimestres = [];
        foreach ($rows as $row) {
            $trimestres[(int) $row['numero']] = $row;
        }

        return $trimestres;
    }

    public function getSubjectGroupsForClass(int $classeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT g.id AS groupe_id, g.libelle AS groupe_libelle, g.code AS groupe_code,
                    m.id AS sous_matiere_id, m.libelle AS sous_matiere_libelle,
                    m.code AS sous_matiere_code,
                    COALESCE(cm.maxima_override, m.maxima) AS maxima_periode
             FROM classe_matieres cm
             INNER JOIN matieres m ON m.id = cm.matiere_id
             INNER JOIN matieres g ON g.id = m.parent_id AND g.est_groupe = 1 AND g.est_actif = 1
             WHERE cm.classe_id = :cid AND cm.est_active = 1
               AND m.est_groupe = 0 AND m.est_actif = 1
             ORDER BY g.id, m.id",
            ['cid' => $classeId]
        );

        $groups = [];
        foreach ($rows as $row) {
            $groupId = (int) $row['groupe_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'id' => $groupId,
                    'libelle' => $row['groupe_libelle'],
                    'code' => $row['groupe_code'],
                    'sous_matieres' => [],
                ];
            }

            $groups[$groupId]['sous_matieres'][] = [
                'id' => (int) $row['sous_matiere_id'],
                'libelle' => $row['sous_matiere_libelle'],
                'code' => $row['sous_matiere_code'],
                'maxima_periode' => (float) $row['maxima_periode'],
            ];
        }

        return array_values($groups);
    }

    public function getAnnualGradeCoverage(int $classeId, array $trimestreIds, array $subjectGroups): array
    {
        $subjectGroupIds = [];
        $subjectCount = 0;
        foreach ($subjectGroups as $group) {
            $subjectGroupIds[] = (int) $group['id'];
            $subjectCount += count($group['sous_matieres']);
        }

        $expected = $subjectCount * 9;
        $completedByStudent = [];
        if ($expected === 0 || empty($trimestreIds) || empty($subjectGroupIds)) {
            return ['expected' => $expected, 'students' => $completedByStudent];
        }

        $trimesterPlaceholders = [];
        $params = ['cid' => $classeId];
        foreach (array_values($trimestreIds) as $index => $trimestreId) {
            $placeholder = 'tid_' . $index;
            $trimesterPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $trimestreId;
        }
        $groupPlaceholders = [];
        foreach (array_values(array_unique($subjectGroupIds)) as $index => $groupId) {
            $placeholder = 'gid_' . $index;
            $groupPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = (int) $groupId;
        }

        $rows = $this->db->fetchAll(
            "SELECT n.eleve_id,
                    COUNT(DISTINCT CONCAT(ev.trimestre_id, ':', ev.type_evaluation, ':', m.id)) AS notes_completees
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             INNER JOIN matieres m ON m.id = n.sous_matiere_id
                 AND m.parent_id = ev.groupe_id AND m.est_groupe = 0 AND m.est_actif = 1
             INNER JOIN classe_matieres cm ON cm.classe_id = ev.classe_id
                 AND cm.matiere_id = m.id AND cm.est_active = 1
             WHERE ev.classe_id = :cid
               AND ev.trimestre_id IN (" . implode(', ', $trimesterPlaceholders) . ")
               AND ev.groupe_id IN (" . implode(', ', $groupPlaceholders) . ")
               AND ev.type_evaluation IN ('Période 1', 'Période 2', 'Examen')
               AND (n.absence = 1 OR n.valeur IS NOT NULL)
             GROUP BY n.eleve_id",
            $params
        );

        foreach ($rows as $row) {
            $completedByStudent[(int) $row['eleve_id']] = min($expected, (int) $row['notes_completees']);
        }

        return ['expected' => $expected, 'students' => $completedByStudent];
    }

    public function resolveAssessment(int $selectedTrimestreId, string $displayType): ?array
    {
        $selected = $this->db->fetchOne(
            "SELECT id, annee_scolaire_id, numero, libelle
             FROM trimestres
             WHERE id = :tid",
            ['tid' => $selectedTrimestreId]
        );
        if (!$selected) {
            return null;
        }

        $period = self::getDisplayPeriodMapping($displayType);
        if ($period !== null) {
            if ((int) $selected['numero'] !== $period['trimestre_numero']) {
                return null;
            }

            $target = $selected;
            $type = $period['type'];
            $component = $period['composante'];
        } elseif ($displayType === 'Examen' || $displayType === 'Trimestre') {
            $target = $selected;
            $type = $displayType === 'Examen' ? 'Examen' : null;
            $component = $displayType === 'Examen' ? 'ex' : 'total';
        } else {
            return null;
        }

        return [
            'trimestre' => $target,
            'type' => $type,
            'composante' => $component,
        ];
    }

    public function calculateSubjectQuarter(
        int $eleveId,
        int $classeId,
        int $sousMatiereId,
        int $trimestreId,
        float $maxPeriode
    ): array {
        $cacheKey = $classeId . ':' . $trimestreId;
        if (!array_key_exists($cacheKey, $this->quarterNotesCache)) {
            $rows = $this->db->fetchAll(
                "SELECT n.eleve_id, n.sous_matiere_id,
                        COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Période 1'
                            THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS p1,
                        COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Période 2'
                            THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS p2,
                        COALESCE(MAX(CASE WHEN ev.type_evaluation = 'Examen'
                            THEN CASE WHEN n.absence = 0 THEN COALESCE(n.valeur, 0) ELSE 0 END END), 0) AS examen
                 FROM evaluations ev
                 INNER JOIN notes n ON n.evaluation_id = ev.id
                 WHERE ev.trimestre_id = :tid AND ev.classe_id = :cid
                 GROUP BY n.eleve_id, n.sous_matiere_id",
                ['tid' => $trimestreId, 'cid' => $classeId]
            );

            $this->quarterNotesCache[$cacheKey] = [];
            foreach ($rows as $row) {
                $this->quarterNotesCache[$cacheKey][(int) $row['eleve_id']][(int) $row['sous_matiere_id']] = [
                    'p1' => (float) $row['p1'],
                    'p2' => (float) $row['p2'],
                    'examen' => (float) $row['examen'],
                ];
            }
        }

        $notes = $this->quarterNotesCache[$cacheKey][$eleveId][$sousMatiereId]
            ?? ['p1' => 0.0, 'p2' => 0.0, 'examen' => 0.0];

        return self::calculateSubjectQuarterFromScores(
            $maxPeriode,
            (float) $notes['p1'],
            (float) $notes['p2'],
            (float) $notes['examen']
        );
    }

    public function calculateStudentAssessment(
        int $eleveId,
        int $classeId,
        int $selectedTrimestreId,
        string $displayType,
        array $subjectGroups
    ): ?array {
        $assessment = $this->resolveAssessment($selectedTrimestreId, $displayType);
        if ($assessment === null) {
            return null;
        }

        return $this->calculateStudentAssessmentForResolved(
            $eleveId,
            $classeId,
            $assessment,
            $subjectGroups
        );
    }

    public function calculateStudentAssessmentForResolved(
        int $eleveId,
        int $classeId,
        array $assessment,
        array $subjectGroups
    ): array {
        $targetTrimester = $assessment['trimestre'];
        $component = $assessment['composante'];
        $totalMaximum = 0.0;
        $totalObtained = 0.0;

        foreach ($subjectGroups as $group) {
            foreach ($group['sous_matieres'] as $subject) {
                $maxPeriode = (float) $subject['maxima_periode'];
                $quarter = $this->calculateSubjectQuarter(
                    $eleveId,
                    $classeId,
                    (int) $subject['id'],
                    (int) $targetTrimester['id'],
                    $maxPeriode
                );

                if ($component === 'total') {
                    $maximum = $quarter['total_max'];
                    $obtained = $quarter['total_obt'];
                } elseif ($component === 'ex') {
                    $maximum = $quarter['ex']['max'];
                    $obtained = $quarter['ex']['obt'];
                } else {
                    $maximum = $quarter[$component]['max'];
                    $obtained = $quarter[$component]['obt'];
                }

                $totalMaximum += $maximum;
                $totalObtained += $obtained;
            }
        }

        return [
            'total_max' => $totalMaximum,
            'total_obt' => $totalObtained,
            'pourcentage' => self::calculatePercentage($totalObtained, $totalMaximum),
            'trimestre' => $targetTrimester,
        ];
    }
}
