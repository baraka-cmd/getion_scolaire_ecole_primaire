<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AcademicResultsRepository.php';

class AcademicResultsService
{
    private AcademicResultsRepository $repository;
    private array $quarterNotesCache = [];

    public function __construct(?AcademicResultsRepository $repository = null)
    {
        $this->repository = $repository ?? new AcademicResultsRepository();
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
        $trimestres = [];
        foreach ($this->repository->findTermsByYear($anneeId) as $row) {
            $trimestres[(int) $row['numero']] = $row;
        }

        return $trimestres;
    }

    public function getSubjectGroupsForClass(int $classeId): array
    {
        $groups = [];
        foreach ($this->repository->findSubjectGroupRows($classeId) as $row) {
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

        $rows = $this->repository->findAnnualGradeCoverageRows(
            $classeId,
            $trimestreIds,
            $subjectGroupIds
        );
        foreach ($rows as $row) {
            $completedByStudent[(int) $row['eleve_id']] = min($expected, (int) $row['notes_completees']);
        }

        return ['expected' => $expected, 'students' => $completedByStudent];
    }

    public function resolveAssessment(int $selectedTrimestreId, string $displayType): ?array
    {
        $selected = $this->repository->findTerm($selectedTrimestreId);
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
            $rows = $this->repository->findQuarterScoreRows($trimestreId, $classeId);
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

    public static function determineMention(float $percentage): string
    {
        if ($percentage >= 90) return 'Excellent';
        if ($percentage >= 80) return 'Très Bien';
        if ($percentage >= 70) return 'Bien';
        if ($percentage >= 60) return 'Assez Bien';
        if ($percentage >= 50) return 'Passable';
        return 'Insuffisant';
    }

    public function getAnnualResultsForClass(int $classId, int $yearId): array
    {
        $empty = [
            'resultats' => [],
            'effectif' => 0,
            'total_garcons' => 0,
            'total_filles' => 0,
        ];
        if (!$this->repository->findClassForYear($classId, $yearId)) {
            return $empty;
        }

        $students = $this->repository->findAnnualStudents($classId, $yearId);
        if ($students === []) {
            return $empty;
        }

        $terms = $this->getTrimestresByYear($yearId);
        $groups = $this->getSubjectGroupsForClass($classId);
        $termIds = [];
        foreach ([1, 2, 3] as $number) {
            if (!empty($terms[$number]['id'])) {
                $termIds[] = (int) $terms[$number]['id'];
            }
        }
        $coverage = $this->getAnnualGradeCoverage($classId, $termIds, $groups);

        $participation = [];
        foreach ($this->repository->findAnnualParticipation($classId, $termIds) as $row) {
            if ((int) $row['notes_present'] > 0) {
                $participation[(int) $row['eleve_id']] = 'present';
            } elseif ((int) $row['notes_absence'] > 0) {
                $participation[(int) $row['eleve_id']] = 'absent';
            }
        }

        $results = [];
        $boys = 0;
        $girls = 0;
        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $completed = (int) ($coverage['students'][$studentId] ?? 0);
            $expected = (int) $coverage['expected'];
            $complete = $expected > 0 && $completed >= $expected;
            $maximum = 0.0;
            $obtained = 0.0;

            foreach ($groups as $group) {
                foreach ($group['sous_matieres'] as $subject) {
                    $subjectMaximum = (float) $subject['maxima_periode'];
                    foreach ([1, 2, 3] as $number) {
                        $termId = (int) ($terms[$number]['id'] ?? 0);
                        $quarter = $termId > 0
                            ? $this->calculateSubjectQuarter(
                                $studentId,
                                $classId,
                                (int) $subject['id'],
                                $termId,
                                $subjectMaximum
                            )
                            : self::emptySubjectQuarter($subjectMaximum);
                        $maximum += $quarter['total_max'];
                        $obtained += $quarter['total_obt'];
                    }
                }
            }

            $percentage = self::calculatePercentage($obtained, $maximum);
            $decision = self::determineAnnualDecision($obtained, $maximum, $complete);
            $results[] = [
                'eleve_id' => $studentId,
                'matricule' => $student['matricule'],
                'nom' => $student['nom'],
                'prenom' => $student['prenom'],
                'sexe' => $student['sexe'],
                'total_max' => $maximum,
                'total_obt' => $obtained,
                'pourcentage' => $percentage,
                'mention' => self::determineMention($percentage),
                'decision' => $decision,
                'is_admis' => $decision === 'Passe',
                'participation' => $participation[$studentId] ?? 'non_evalue',
                'bulletin_complet' => $complete,
                'notes_completees' => $completed,
                'notes_attendues' => $expected,
                'taux_completude' => $expected > 0
                    ? round(($completed / $expected) * 100, 1)
                    : 0.0,
            ];
            if ($student['sexe'] === 'M') {
                $boys++;
            } else {
                $girls++;
            }
        }

        usort($results, static function (array $a, array $b): int {
            $percentage = round((float) $b['pourcentage'], 1) <=> round((float) $a['pourcentage'], 1);
            if ($percentage !== 0) return $percentage;
            foreach (['nom', 'prenom', 'matricule'] as $field) {
                $name = strnatcasecmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
                if ($name !== 0) return $name;
            }
            return (int) $a['eleve_id'] <=> (int) $b['eleve_id'];
        });

        $effectif = count($results);
        foreach ($results as $index => &$result) {
            $result['rang'] = $index + 1;
            $result['effectif'] = $effectif;
        }
        unset($result);

        return [
            'resultats' => $results,
            'effectif' => $effectif,
            'total_garcons' => $boys,
            'total_filles' => $girls,
        ];
    }
}
