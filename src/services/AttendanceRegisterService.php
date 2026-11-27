<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AttendanceRegisterRepository.php';

class AttendanceRegisterService
{
    private AttendanceRegisterRepository $repository;

    public function __construct(?AttendanceRegisterRepository $repository = null)
    {
        $this->repository = $repository ?? new AttendanceRegisterRepository();
    }

    public function getClosureForDate(int $yearId, string $date, int $classId, string $className): ?string
    {
        return $this->getClosureFromEvents(
            $this->repository->findClosuresOnDate($yearId, $date),
            $date,
            $classId,
            $className
        );
    }

    public function getClosureFromEvents(
        array $events,
        string $date,
        int $classId,
        string $className
    ): ?string {
        foreach ($events as $event) {
            if ($date < $event['date_debut'] || $date > $event['date_fin']) {
                continue;
            }

            $classes = trim((string) ($event['concerne_classes'] ?? ''));
            if ($classes !== '') {
                $targets = array_map('trim', explode(',', $classes));
                if (
                    !in_array((string) $classId, $targets, true)
                    && !in_array($className, $targets, true)
                ) {
                    continue;
                }
            }

            return $event['titre'];
        }

        return null;
    }

    public function isScheduledDay(int $classId, int $yearId, string $date): ?bool
    {
        $scheduledDays = $this->repository->findScheduledDays($classId, $yearId);
        if (!$scheduledDays) {
            return null;
        }

        $weekdayMap = [
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
        ];
        $weekday = $weekdayMap[date('l', strtotime($date))] ?? null;

        return $weekday !== null && in_array($weekday, array_column($scheduledDays, 'jour'), true);
    }

    public function getClosuresInRange(int $yearId, string $startDate, string $endDate): array
    {
        return $this->repository->findClosuresInRange($yearId, $startDate, $endDate);
    }

    public function getScheduledDayRows(int $classId, int $yearId): array
    {
        return $this->repository->findScheduledDayRows($classId, $yearId);
    }

    public function getSchoolYears(): array { return $this->repository->getSchoolYears(); }
    public function getActiveSchoolYearId(): int { return $this->repository->getActiveSchoolYearId(); }
    public function getClasses(int $yearId, ?int $teacherUserId = null): array { return $this->repository->findClasses($yearId, $teacherUserId); }

    public function getPointage(int $classId, array $classData, string $date): array
    {
        $data = $this->repository->getPointageData($classId, (int) $classData['annee_scolaire_id'], $date);
        $byStudent = [];
        foreach ($data['statuts'] as $status) $byStudent[(int) $status['eleve_id']] = $status;
        $closure = $data['appel'] && $data['appel']['statut'] === 'conge'
            ? $data['appel']['motif_conge']
            : $this->getClosureForDate((int) $classData['annee_scolaire_id'], $date, $classId, $classData['nom']);
        $scheduled = $this->isScheduledDay($classId, (int) $classData['annee_scolaire_id'], $date);
        if ($closure === null && $scheduled === false && (!$data['appel'] || $data['appel']['statut'] !== 'valide')) {
            $closure = 'Jour non étudié selon l’emploi du temps.';
        }
        foreach ($data['eleves'] as &$student) $student['pointage'] = $byStudent[(int) $student['id']] ?? null;
        unset($student);
        return ['annee'=>$classData,'eleves'=>$data['eleves'],'appel'=>$data['appel'],'fermeture'=>$closure,'jour_planifie'=>$scheduled];
    }

    public function getRegistre(int $classeId, int $anneeId, ?string $dateReference = null): array
    {
        $registerData = $this->repository->getRegisterData($classeId, $anneeId, '', '');
        $classe = $registerData['classe'];
        if (!$classe) throw new RuntimeException('La classe ne correspond pas à cette année scolaire.');
        // Queries above are fetched once after the school-year boundaries are known.
        $registerData = $this->repository->getRegisterData($classeId, $anneeId, $classe['date_debut'], $classe['date_fin']);
        $eleves = $registerData['eleves'];
        $appels = $registerData['appels'];
        $absences = $registerData['absences'];
        $appelsParDate = [];
        foreach ($appels as $appel) $appelsParDate[$appel['date_appel']] = $appel;
        $evenements = $this->getClosuresInRange($anneeId, $classe['date_debut'], $classe['date_fin']);
        $marques = [];
        foreach ($absences as $absence) $marques[$absence['date_absence']][(int) $absence['eleve_id']] = $absence;
        $joursCours = $this->attendanceRegisterService->getScheduledDayRows($classeId, $anneeId);
        $joursSemaine = [
            'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi'
        ];
        $joursPlanifies = array_column($joursCours, 'jour');
        $debutAnnee = new DateTimeImmutable($classe['date_debut']);
        $finAnnee = new DateTimeImmutable($classe['date_fin']);
        if ($dateReference !== null) {
            if (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReference)
                || !isValidDate($dateReference)
                || $dateReference < $classe['date_debut']
                || $dateReference > $classe['date_fin']
            ) {
                throw new InvalidArgumentException('La date de prévisualisation doit être comprise dans l’année scolaire.');
            }
        } else {
            $dateReference = date('Y-m-d');
        }
        $dateLimiteCalcul = new DateTimeImmutable($dateReference);
        $month = $debutAnnee->modify('first day of this month');
        $dateRegistreInitial = $debutAnnee->format('Y-m-d');

        $elevesInitiaux = [];
        $nouveauxEleves = [];
        foreach ($eleves as $eleve) {
            if ($eleve['date_inscription'] <= $dateRegistreInitial) {
                $elevesInitiaux[] = $eleve;
            } else {
                $nouveauxEleves[] = $eleve;
            }
        }
        usort($nouveauxEleves, static function (array $a, array $b): int {
            $dateOrder = strcmp((string) $a['date_inscription'], (string) $b['date_inscription']);
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            return (int) $a['inscription_id'] <=> (int) $b['inscription_id'];
        });
        $eleves = array_merge($elevesInitiaux, $nouveauxEleves);

        $moisList = [];
        $cumulJoursClasse = 0;
        $eleveIds = array_map('intval', array_column($eleves, 'id'));
        $cumulPresences = array_fill_keys($eleveIds, 0);

        while ($month <= $finAnnee) {
            $monthEnd = $month->modify('last day of this month');
            $monthPeriodEnd = $monthEnd > $finAnnee ? $finAnnee : $monthEnd;
            $monthFinalized = $monthPeriodEnd <= $dateLimiteCalcul;
            $monthFuture = $month > $dateLimiteCalcul;
            $lastDate = $monthPeriodEnd < $dateLimiteCalcul ? $monthPeriodEnd : $dateLimiteCalcul;
            $days = [];
            $monthlyDays = 0;
            $monthlyPresences = 0;
            $monthlyAbsences = 0;
            $monthlyCredits = 0;
            $monthlyByEleve = array_fill_keys($eleveIds, 0);
            $monthlyAbsencesByEleve = array_fill_keys($eleveIds, 0);
            $daysWithCall = 0;

            for ($day = $month; $day <= $monthEnd; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $weekday = $joursSemaine[$day->format('l')] ?? null;
                $appel = $appelsParDate[$date] ?? null;
                $isInSchoolYear = $date >= $classe['date_debut'] && $date <= $classe['date_fin'];
                $isFuture = $isInSchoolYear && $day > $lastDate;
                $fermeture = $isInSchoolYear && $appel && $appel['statut'] === 'conge'
                    ? $appel['motif_conge']
                    : ($isInSchoolYear
                        ? $this->attendanceRegisterService->getClosureFromEvents(
                            $evenements,
                            $date,
                            $classeId,
                            $classe['nom']
                        )
                        : null);
                $estJourPlanifie = $weekday !== null && in_array($weekday, $joursPlanifies, true);
                $appelValide = $appel && $appel['statut'] === 'valide';
                $aMarques = false;
                foreach ($eleves as $eleve) {
                    if (
                        $eleve['date_inscription'] <= $date
                        && isset($marques[$date][(int) $eleve['id']])
                    ) {
                        $aMarques = true;
                        break;
                    }
                }
                $hasMarksWithoutSchedule = !$joursPlanifies && $aMarques;
                $estJourCours = $isInSchoolYear && !$isFuture && (
                    $estJourPlanifie || $appelValide || $hasMarksWithoutSchedule
                ) && !$fermeture;
                $jourEtudie = $estJourCours && ($appelValide || $aMarques);

                if ($jourEtudie) $monthlyDays++;
                if ($estJourCours && $appelValide) $daysWithCall++;

                $totalPresenceJour = 0;
                $totalAbsenceJour = 0;
                $marquesJour = [];
                foreach ($eleves as $eleve) {
                    $statut = $eleve['date_inscription'] <= $date
                        ? ($marques[$date][(int) $eleve['id']] ?? null)
                        : null;
                    if ($jourEtudie && $statut && $statut['type_absence'] === 'Présent') {
                        $totalPresenceJour++;
                        $monthlyByEleve[(int) $eleve['id']]++;
                    } elseif ($jourEtudie && $statut && $statut['type_absence'] === 'Absence') {
                        $totalAbsenceJour++;
                        $monthlyAbsencesByEleve[(int) $eleve['id']]++;
                        if ((int) $statut['est_justifiee'] === 1) $monthlyCredits++;
                    }
                    $marquesJour[(int) $eleve['id']] = $statut
                        ? [
                            'type' => $statut['type_absence'],
                            'justifiee' => (int) $statut['est_justifiee'] === 1
                        ]
                        : null;
                }
                $monthlyPresences += $totalPresenceJour;
                $monthlyAbsences += $totalAbsenceJour;
                $days[] = [
                    'date' => $date,
                    'day' => (int) $day->format('j'),
                    'working' => $estJourCours,
                    'studied' => $jourEtudie,
                    'off_day' => $isInSchoolYear && !$isFuture && !$estJourCours,
                    'in_school_year' => $isInSchoolYear,
                    'future' => $isFuture,
                    'closed' => (bool) $fermeture,
                    'closure' => $fermeture ?: null,
                    'validated' => (bool) $appelValide,
                    'present' => $totalPresenceJour,
                    'absent' => $totalAbsenceJour,
                    'marks' => $marquesJour
                ];
            }

            $monthCalculated = $monthFinalized && $daysWithCall > 0;
            if ($monthCalculated) {
                $cumulJoursClasse += $monthlyDays;
                foreach ($monthlyByEleve as $eleveId => $count) {
                    $cumulPresences[$eleveId] += $count;
                }
            }
            $elevesMois = array_values(array_filter(
                $eleves,
                static function (array $eleve) use ($monthPeriodEnd): bool {
                    return $eleve['date_inscription'] <= $monthPeriodEnd->format('Y-m-d');
                }
            ));
            $moisList[] = [
                'label' => moisEnFrancais((int) $month->format('n')) . ' ' . $month->format('Y'),
                'number' => (int) $month->format('n'),
                'days' => $days,
                'finalized' => $monthFinalized,
                'calculated' => $monthCalculated,
                'future' => $monthFuture,
                'eleves_mois' => $elevesMois,
                'effectif' => count($elevesMois),
                'jours_classe' => $monthCalculated ? $monthlyDays : null,
                'jours_cumules' => $monthCalculated ? $cumulJoursClasse : null,
                'presences' => $monthCalculated ? $monthlyPresences : null,
                'absences' => $monthCalculated ? $monthlyAbsences : null,
                'presences_cumulees' => $monthCalculated ? array_sum($cumulPresences) : null,
                'credits_justifies' => $monthCalculated ? $monthlyCredits : null,
                'moyenne' => $monthCalculated
                    ? round(($monthlyPresences + $monthlyCredits) / $monthlyDays, 2)
                    : null,
                'appels_valides' => $monthCalculated ? $daysWithCall : null,
                'presences_par_eleve' => $monthlyByEleve,
                'absences_par_eleve' => $monthlyAbsencesByEleve,
                'eleves' => [],
                'totaux_lignes' => null
            ];
            $month = $month->modify('first day of next month');
        }

        $cumulEleve = array_fill_keys($eleveIds, 0);
        foreach ($moisList as &$mois) {
            $totalPresenceMois = 0;
            $totalAbsenceMois = 0;
            $totalPresenceCumulee = 0;
            foreach ($mois['eleves_mois'] as $eleve) {
                $id = (int) $eleve['id'];
                $presenceMois = $mois['presences_par_eleve'][$id] ?? 0;
                $absenceMois = $mois['absences_par_eleve'][$id] ?? 0;
                if ($mois['calculated']) {
                    $cumulEleve[$id] += $presenceMois;
                    $totalPresenceMois += $presenceMois;
                    $totalAbsenceMois += $absenceMois;
                    $totalPresenceCumulee += $cumulEleve[$id];
                }
                $mois['eleves'][$id] = [
                    'presence_mois' => $mois['calculated'] ? $presenceMois : null,
                    'absence_mois' => $mois['calculated'] ? $absenceMois : null,
                    'presence_cumulee' => $mois['calculated'] ? $cumulEleve[$id] : null,
                    'jours_classe_cumules' => $mois['calculated'] ? $mois['jours_cumules'] : null
                ];
            }
            if ($mois['calculated']) {
                $mois['totaux_lignes'] = [
                    'presence_mois' => $totalPresenceMois,
                    'absence_mois' => $totalAbsenceMois,
                    'presence_cumulee' => $totalPresenceCumulee,
                    'jours_classe_cumules' => $mois['jours_cumules'] * $mois['effectif']
                ];
            }
            unset($mois['presences_par_eleve'], $mois['absences_par_eleve']);
        }
        unset($mois);

        return [
            'classe' => $classe,
            'eleves' => $eleves,
            'mois' => $moisList,
            'emploi_configure' => !empty($joursPlanifies)
        ];

    }

}
