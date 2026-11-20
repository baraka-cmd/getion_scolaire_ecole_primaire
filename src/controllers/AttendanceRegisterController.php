<?php

defined('ACCESS') or die('Accès interdit');

class AttendanceRegisterController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function handleAjax(): void
    {
        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        switch (post('action', '')) {
            case 'validerAppel':
                $this->validerAppel();
                break;
            case 'marquerConge':
                $this->marquerConge();
                break;
            case 'annulerConge':
                $this->annulerConge();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    public function getAnnees(): array
    {
        return $this->db->fetchAll(
            'SELECT id, libelle, date_debut, date_fin
             FROM annees_scolaires
             ORDER BY date_debut DESC'
        );
    }

    public function getAnneeActiveId(): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1'
        );
    }

    public function getClasses(int $anneeId): array
    {
        $params = ['annee' => $anneeId];
        $where = 'c.annee_scolaire_id = :annee';

        if (hasRole('Enseignant')) {
            $where .= ' AND c.id = (
                SELECT e.classe_id FROM enseignants e
                WHERE e.utilisateur_id = :utilisateur AND e.est_actif = 1
                LIMIT 1
            )';
            $params['utilisateur'] = (int) ($_SESSION['utilisateur_id'] ?? 0);
        }

        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id
             FROM classes c
             WHERE {$where}
             ORDER BY c.niveau, c.nom",
            $params
        );
    }

    public function getPointage(int $classeId, string $date): array
    {
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);

        $eleves = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
             FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.classe_id = :classe
               AND i.annee_scolaire_id = :annee
               AND i.statut IN ('Actif', 'Redoublant')
               AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['classe' => $classeId, 'annee' => $annee['annee_scolaire_id']]
        );

        $statuts = $this->db->fetchAll(
            'SELECT eleve_id, type_absence, est_justifiee, motif
             FROM absences
             WHERE date_absence = :date
               AND eleve_id IN (
                   SELECT eleve_id FROM inscriptions
                   WHERE classe_id = :classe AND annee_scolaire_id = :annee
               )',
            [
                'date' => $date,
                'classe' => $classeId,
                'annee' => $annee['annee_scolaire_id']
            ]
        );
        $parEleve = [];
        foreach ($statuts as $statut) {
            $parEleve[(int) $statut['eleve_id']] = $statut;
        }

        $appel = $this->db->fetchOne(
            'SELECT statut, motif_conge FROM appels_presence
             WHERE classe_id = :classe AND date_appel = :date',
            ['classe' => $classeId, 'date' => $date]
        );
        $fermeture = $appel && $appel['statut'] === 'conge'
            ? $appel['motif_conge']
            : $this->getCalendarClosure(
                (int) $annee['annee_scolaire_id'],
                $date,
                $classeId,
                $annee['nom']
            );
        $jourPlanifie = $this->getJourPlanifie(
            $classeId,
            (int) $annee['annee_scolaire_id'],
            $date
        );
        if (
            $fermeture === null
            && $jourPlanifie === false
            && (!$appel || $appel['statut'] !== 'valide')
        ) {
            $fermeture = 'Jour non étudié selon l’emploi du temps.';
        }

        foreach ($eleves as &$eleve) {
            $eleve['pointage'] = $parEleve[(int) $eleve['id']] ?? null;
        }
        unset($eleve);

        return [
            'annee' => $annee,
            'eleves' => $eleves,
            'appel' => $appel,
            'fermeture' => $fermeture,
            'jour_planifie' => $jourPlanifie
        ];
    }

    public function getRegistre(int $classeId, int $anneeId, ?string $dateReference = null): array
    {
        $classe = $this->db->fetchOne(
            "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id,
                    a.libelle AS annee_libelle, a.date_debut, a.date_fin,
                    p.nom_ecole, p.logo,
                    CONCAT(COALESCE(en.prenom, ''), ' ', COALESCE(en.nom, '')) AS titulaire
             FROM classes c
             INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             LEFT JOIN parametres_ecole p ON p.id = 1
             LEFT JOIN enseignants en ON en.classe_id = c.id AND en.est_actif = 1
             WHERE c.id = :classe AND c.annee_scolaire_id = :annee
             LIMIT 1",
            ['classe' => $classeId, 'annee' => $anneeId]
        );
        if (!$classe) {
            throw new RuntimeException('La classe ne correspond pas à cette année scolaire.');
        }
        $this->requireClassAccess($classeId, $anneeId);

        $eleves = $this->db->fetchAll(
            "SELECT i.id AS inscription_id, i.statut AS statut_inscription,
                    i.date_inscription, i.date_changement_statut,
                    e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance,
                    e.lieu_naissance,
                    (SELECT GROUP_CONCAT(CONCAT(pt.prenom, ' ', pt.nom) ORDER BY pt.lien SEPARATOR ' / ')
                     FROM eleve_parent ep
                     INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                     WHERE ep.eleve_id = e.id AND pt.lien = 'Père') AS pere,
                    (SELECT GROUP_CONCAT(CONCAT(pt.prenom, ' ', pt.nom) ORDER BY pt.lien SEPARATOR ' / ')
                     FROM eleve_parent ep
                     INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                     WHERE ep.eleve_id = e.id AND pt.lien = 'Mère') AS mere
             FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
             ORDER BY e.nom, e.prenom, i.date_inscription",
            ['classe' => $classeId, 'annee' => $anneeId]
        );

        $appels = $this->db->fetchAll(
            'SELECT date_appel, statut, motif_conge
             FROM appels_presence
             WHERE classe_id = :classe AND annee_scolaire_id = :annee',
            ['classe' => $classeId, 'annee' => $anneeId]
        );
        $appelsParDate = [];
        foreach ($appels as $appel) {
            $appelsParDate[$appel['date_appel']] = $appel;
        }

        $evenements = $this->db->fetchAll(
            "SELECT titre, type_evenement, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :annee
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :fin AND date_fin >= :debut",
            ['annee' => $anneeId, 'debut' => $classe['date_debut'], 'fin' => $classe['date_fin']]
        );

        $absences = $this->db->fetchAll(
            "SELECT a.eleve_id, a.date_absence, a.type_absence, a.est_justifiee
             FROM absences a
             INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
             WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
               AND a.date_absence BETWEEN :debut AND :fin",
            [
                'classe' => $classeId,
                'annee' => $anneeId,
                'debut' => $classe['date_debut'],
                'fin' => $classe['date_fin']
            ]
        );
        $marques = [];
        foreach ($absences as $absence) {
            $marques[$absence['date_absence']][(int) $absence['eleve_id']] = $absence;
        }

        $joursCours = $this->db->fetchAll(
            "SELECT DISTINCT jour FROM emploi_du_temps
             WHERE classe_id = :classe AND annee_scolaire_id = :annee
               AND COALESCE(type_creneau, 'cours') <> 'recréation'",
            ['classe' => $classeId, 'annee' => $anneeId]
        );
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
                        ? $this->getFermetureEvenement($evenements, $date, $classeId, $classe['nom'])
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

    private function validerAppel(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();

        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        if ($date !== date('Y-m-d')) {
            jsonResponse(false, 'La date de l’appel est automatiquement fixée à la date du jour.', [], 422);
        }
        $presentIds = $_POST['eleves_presents'] ?? [];
        if (!is_array($presentIds)) {
            jsonResponse(false, 'Liste de présences invalide.', [], 422);
        }
        $normalizedPresentIds = [];
        foreach ($presentIds as $presentId) {
            if (
                (!is_int($presentId) && !is_string($presentId))
                || filter_var($presentId, FILTER_VALIDATE_INT) === false
                || (int) $presentId <= 0
            ) {
                jsonResponse(false, 'La liste contient un identifiant d’élève invalide.', [], 422);
            }
            $normalizedPresentIds[] = (int) $presentId;
        }
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        if ($this->getCalendarClosure((int) $annee['annee_scolaire_id'], $date, $classeId, $annee['nom']) !== null) {
            jsonResponse(false, 'Cette date est indiquée comme congé dans le calendrier scolaire.', [], 409);
        }
        $jourPlanifie = $this->getJourPlanifie($classeId, (int) $annee['annee_scolaire_id'], $date);

        $eleves = $this->getElevesAppel($classeId, (int) $annee['annee_scolaire_id']);
        if (!$eleves) {
            jsonResponse(false, 'Aucun élève actif n’est inscrit dans cette classe pour cette année.', [], 409);
        }
        $idsAutorises = array_map('intval', array_column($eleves, 'id'));
        $presentIds = array_values(array_unique($normalizedPresentIds));
        if (array_diff($presentIds, $idsAutorises)) {
            jsonResponse(false, 'La liste contient un élève qui ne fait pas partie de cette classe.', [], 422);
        }

        try {
            $this->db->beginTransaction();
            $appel = $this->db->fetchOne(
                'SELECT statut FROM appels_presence
                 WHERE classe_id = :classe AND date_appel = :date FOR UPDATE',
                ['classe' => $classeId, 'date' => $date]
            );
            if ($appel && $appel['statut'] === 'conge') {
                $this->db->rollback();
                jsonResponse(false, 'Cette journée est déjà enregistrée comme congé.', [], 409);
            }
            if ($jourPlanifie === false && (!$appel || $appel['statut'] !== 'valide')) {
                $this->db->rollback();
                jsonResponse(false, 'Cette date n’est pas un jour de classe selon l’emploi du temps.', [], 409);
            }

            foreach ($eleves as $eleve) {
                $isPresent = in_array((int) $eleve['id'], $presentIds, true);
                $this->db->query(
                    "INSERT INTO absences
                        (eleve_id, date_absence, type_absence, est_justifiee, saisie_par, created_at)
                     VALUES (:eleve, :date, :type, 0, :utilisateur, NOW())
                     ON DUPLICATE KEY UPDATE
                        type_absence = VALUES(type_absence),
                        est_justifiee = IF(VALUES(type_absence) = 'Présent', 0, est_justifiee),
                        motif = IF(VALUES(type_absence) = 'Présent', NULL, motif),
                        date_justification = IF(VALUES(type_absence) = 'Présent', NULL, date_justification),
                        saisie_par = VALUES(saisie_par)",
                    [
                        'eleve' => $eleve['id'],
                        'date' => $date,
                        'type' => $isPresent ? 'Présent' : 'Absence',
                        'utilisateur' => (int) $_SESSION['utilisateur_id']
                    ]
                );
            }
            $this->db->query(
                "INSERT INTO appels_presence
                    (classe_id, annee_scolaire_id, date_appel, statut, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'valide', :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE
                    statut = 'valide', motif_conge = NULL,
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                [
                    'classe' => $classeId,
                    'annee' => $annee['annee_scolaire_id'],
                    'date' => $date,
                    'utilisateur' => (int) $_SESSION['utilisateur_id']
                ]
            );
            $this->db->commit();
            logActivity('Pointage', 'appels_presence', null, "Appel validé — classe {$classeId} — {$date}");
            jsonResponse(true, 'Appel validé. Les élèves non cochés sont enregistrés absents.');
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Erreur validation appel: ' . $e->getMessage());
            jsonResponse(false, 'Impossible de valider l’appel.', [], 500);
        }
    }

    private function marquerConge(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();

        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        $motif = trim((string) post('motif_conge', ''));
        if ($motif === '' || mb_strlen($motif) > 255) {
            jsonResponse(false, 'Indiquez un motif de congé de 1 à 255 caractères.', [], 422);
        }
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);
        $this->db->beginTransaction();
        try {
            $appel = $this->db->fetchOne(
                'SELECT statut FROM appels_presence
                 WHERE classe_id = :classe AND date_appel = :date FOR UPDATE',
                ['classe' => $classeId, 'date' => $date]
            );
            $marquesExistantes = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM absences a
                 INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                   AND a.date_absence = :date",
                ['classe' => $classeId, 'annee' => $annee['annee_scolaire_id'], 'date' => $date]
            );
            if (($appel && $appel['statut'] === 'valide') || $marquesExistantes > 0) {
                $this->db->rollback();
                jsonResponse(false, 'Des pointages existent déjà pour cette date. Corrigez-les avant de déclarer un congé.', [], 409);
            }

            $this->db->query(
                "INSERT INTO appels_presence
                    (classe_id, annee_scolaire_id, date_appel, statut, motif_conge, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'conge', :motif, :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE
                    statut = 'conge', motif_conge = VALUES(motif_conge),
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                [
                    'classe' => $classeId,
                    'annee' => $annee['annee_scolaire_id'],
                    'date' => $date,
                    'motif' => $motif,
                    'utilisateur' => (int) $_SESSION['utilisateur_id']
                ]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        logActivity('Congé scolaire', 'appels_presence', null, "Classe {$classeId} — {$date} — {$motif}");
        jsonResponse(true, 'Le jour de congé est enregistré; il sera barré en rouge dans le registre.');
    }

    private function annulerConge(): void
    {
        requirePermission('absences.marquer');
        Security::getInstance()->validateCsrf();

        $classeId = (int) post('classe_id', 0);
        $date = post('date_appel', '');
        $annee = $this->getClassePourDate($classeId, $date);
        $this->requireClassAccess($classeId, (int) $annee['annee_scolaire_id']);

        $deleted = $this->db->delete(
            "DELETE FROM appels_presence
             WHERE classe_id = :classe AND annee_scolaire_id = :annee
               AND date_appel = :date AND statut = 'conge'",
            ['classe' => $classeId, 'annee' => $annee['annee_scolaire_id'], 'date' => $date]
        );
        if ($deleted === 0) {
            jsonResponse(false, 'Aucun congé manuel à annuler pour cette date.', [], 409);
        }
        logActivity('Réouverture', 'appels_presence', null, "Classe {$classeId} — {$date}");
        jsonResponse(true, 'Le congé manuel a été annulé. Vous pouvez maintenant faire l’appel.');
    }

    private function getElevesAppel(int $classeId, int $anneeId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
               AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1",
            ['classe' => $classeId, 'annee' => $anneeId]
        );
    }

    private function getClassePourDate(int $classeId, string $date): array
    {
        if ($classeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !isValidDate($date)) {
            jsonResponse(false, 'Classe ou date invalide.', [], 422);
        }
        $classe = $this->db->fetchOne(
            'SELECT c.id, c.nom, c.annee_scolaire_id, a.date_debut, a.date_fin
             FROM classes c
             INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             WHERE c.id = :id',
            ['id' => $classeId]
        );
        if (!$classe || $date < $classe['date_debut'] || $date > $classe['date_fin']) {
            jsonResponse(false, 'La date doit appartenir à l’année scolaire de cette classe.', [], 422);
        }
        return $classe;
    }

    private function requireClassAccess(int $classeId, int $anneeId): void
    {
        requirePermission('absences.consulter');
        if (hasRole('Enseignant')) {
            $ownedClass = $this->db->fetchColumn(
                'SELECT id FROM enseignants
                 WHERE utilisateur_id = :utilisateur AND classe_id = :classe AND est_actif = 1
                 LIMIT 1',
                ['utilisateur' => (int) ($_SESSION['utilisateur_id'] ?? 0), 'classe' => $classeId]
            );
            if (!$ownedClass) jsonResponse(false, 'Vous ne pouvez consulter que votre classe.', [], 403);
        }
    }

    private function getCalendarClosure(int $anneeId, string $date, int $classeId, string $classeNom): ?string
    {
        $events = $this->db->fetchAll(
            "SELECT titre, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :annee
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :date_debut AND date_fin >= :date_fin
             ORDER BY date_debut",
            ['annee' => $anneeId, 'date_debut' => $date, 'date_fin' => $date]
        );
        return $this->getFermetureEvenement($events, $date, $classeId, $classeNom);
    }

    private function getJourPlanifie(int $classeId, int $anneeId, string $date): ?bool
    {
        $joursPlanifies = $this->db->fetchAll(
            "SELECT DISTINCT jour FROM emploi_du_temps
             WHERE classe_id = :classe AND annee_scolaire_id = :annee
               AND COALESCE(type_creneau, 'cours') <> 'recréation'",
            ['classe' => $classeId, 'annee' => $anneeId]
        );
        if (!$joursPlanifies) return null;

        $joursSemaine = [
            'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi'
        ];
        $jour = $joursSemaine[date('l', strtotime($date))] ?? null;
        return $jour !== null && in_array($jour, array_column($joursPlanifies, 'jour'), true);
    }

    private function getFermetureEvenement(array $events, string $date, int $classeId, string $classeNom): ?string
    {
        foreach ($events as $event) {
            if ($date < $event['date_debut'] || $date > $event['date_fin']) continue;
            $classes = trim((string) ($event['concerne_classes'] ?? ''));
            if ($classes !== '') {
                $cibles = array_map('trim', explode(',', $classes));
                if (!in_array((string) $classeId, $cibles, true) && !in_array($classeNom, $cibles, true)) continue;
            }
            return $event['titre'];
        }
        return null;
    }
}
