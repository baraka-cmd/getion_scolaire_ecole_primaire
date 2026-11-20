<?php
/**
 * Fichier : src/controllers/StatistiqueController.php
 * Rôle    : Statistiques scolaires filtrées par année, classe et trimestre.
 */

defined('ACCESS') or die('Accès interdit');

class StatistiqueController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function handleAjax(): void
    {
        if (!can('rapports.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }
        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');
        if ($action === 'getFilterOptions') {
            $yearId = filter_var(post('annee_id', ''), FILTER_VALIDATE_INT);
            $year = $yearId ? $this->db->fetchOne(
                "SELECT id FROM annees_scolaires WHERE id = :id",
                ['id' => $yearId]
            ) : null;
            if (!$year) {
                jsonResponse(false, 'Année scolaire invalide.', [], 400);
            }
            jsonResponse(true, 'Filtres récupérés.', [
                'classes' => $this->getClasses((int) $year['id']),
                'trimestres' => $this->getTrimestres((int) $year['id']),
            ]);
        }

        $filters = $this->readFilters();
        switch ($action) {
            case 'getKPIs':
                $this->getKPIs($filters);
                break;
            case 'getEffectifsParClasse':
                $this->getResultatsParClasse($filters);
                break;
            case 'getResultatsParTrimestre':
                $this->getResultatsParTrimestre($filters);
                break;
            case 'getRecouvrementParClasse':
                $this->getRecouvrementParClasse($filters);
                break;
            case 'getPresencesParMois':
                $this->getPresencesParMois($filters);
                break;
            case 'getIncidentsParMois':
                $this->getIncidentsParMois($filters);
                break;
            case 'getTopClasses':
                $this->getTopClasses($filters);
                break;
            case 'getClassesARisque':
                $this->getClassesARisque($filters);
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    public function getFilterOptions(): array
    {
        $annees = $this->db->fetchAll(
            "SELECT id, libelle, date_debut, date_fin, est_active
             FROM annees_scolaires ORDER BY date_debut DESC"
        );
        $anneeId = $this->getAnneeActiveId();
        if ($annees && !$this->findYear($annees, $anneeId)) {
            $anneeId = (int) $annees[0]['id'];
        }
        $trimestreActif = $this->getTrimestreActifId($anneeId);

        return [
            'annees' => $annees,
            'annee_id' => $anneeId,
            'classes' => $anneeId > 0 ? $this->getClasses($anneeId) : [],
            'trimestres' => $anneeId > 0 ? $this->getTrimestres($anneeId) : [],
            'trimestre_id' => $trimestreActif,
        ];
    }

    private function getKPIs(array $filters): void
    {
        $enrollmentParams = ['aid' => $filters['annee_id']];
        $enrollmentClass = $this->classCondition($filters, 'i.classe_id', $enrollmentParams);
        $effectif = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT i.eleve_id) FROM inscriptions i
             WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$enrollmentClass}",
            $enrollmentParams
        );

        $classParams = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'c.id', $classParams);
        $classes = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes c
             WHERE c.annee_scolaire_id = :aid{$classCondition}",
            $classParams
        );

        $attendanceParams = [
            'aid' => $filters['annee_id'],
            'debut' => $filters['annee']['date_debut'],
            'fin' => $filters['annee']['date_fin'],
        ];
        $attendanceClass = $this->classCondition($filters, 'i.classe_id', $attendanceParams);
        $attendance = $this->db->fetchOne(
            "SELECT COUNT(*) AS marques,
                    SUM(a.type_absence = 'Présent') AS presents,
                    SUM(a.type_absence = 'Absence') AS absences
             FROM absences a
             INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
             WHERE i.annee_scolaire_id = :aid
               AND i.statut IN ('Actif', 'Redoublant')
               AND a.date_absence BETWEEN :debut AND :fin{$attendanceClass}",
            $attendanceParams
        ) ?: ['marques' => 0, 'presents' => 0, 'absences' => 0];

        $resultParams = ['aid' => $filters['annee_id']];
        $resultCondition = '';
        if ($filters['trimestre_id']) {
            $resultCondition .= ' AND rt.trimestre_id = :tid';
            $resultParams['tid'] = $filters['trimestre_id'];
        }
        if ($filters['classe_id']) {
            $resultCondition .= ' AND rt.classe_id = :cid';
            $resultParams['cid'] = $filters['classe_id'];
        }
        $resultats = $this->db->fetchOne(
            "SELECT COUNT(rt.moyenne_generale) AS nb,
                    SUM(rt.moyenne_generale >= 50) AS reussites,
                    AVG(rt.moyenne_generale) AS moyenne
             FROM resultats_trimestriels rt
             INNER JOIN inscriptions i ON i.eleve_id = rt.eleve_id
                 AND i.classe_id = rt.classe_id
                 AND i.statut IN ('Actif', 'Redoublant')
             INNER JOIN trimestres t ON t.id = rt.trimestre_id
                 AND t.annee_scolaire_id = i.annee_scolaire_id
             WHERE i.annee_scolaire_id = :aid{$resultCondition}",
            $resultParams
        ) ?: ['nb' => 0, 'reussites' => 0, 'moyenne' => null];

        $feeParams = ['aid' => $filters['annee_id']];
        $feeClass = $this->classCondition($filters, 'i.classe_id', $feeParams);
        $financial = $this->db->fetchOne(
            "SELECT COALESCE(SUM(f.montant), 0) AS attendu,
                    COALESCE(SUM(COALESCE(paye.montant_paye, 0)), 0) AS paye
             FROM inscriptions i
             INNER JOIN frais_scolaires f ON f.annee_scolaire_id = i.annee_scolaire_id
                 AND f.est_actif = 1 AND f.est_obligatoire = 1
                 AND (f.classe_id IS NULL OR f.classe_id = i.classe_id)
             LEFT JOIN (
                 SELECT p.eleve_id, p.frais_scolaire_id, SUM(p.montant_paye) AS montant_paye
                 FROM paiements p
                 INNER JOIN frais_scolaires fp ON fp.id = p.frais_scolaire_id
                 WHERE fp.annee_scolaire_id = :paid_aid
                 GROUP BY p.eleve_id, p.frais_scolaire_id
             ) paye ON paye.eleve_id = i.eleve_id AND paye.frais_scolaire_id = f.id
             WHERE i.annee_scolaire_id = :aid
               AND i.statut IN ('Actif', 'Redoublant'){$feeClass}",
            array_merge($feeParams, ['paid_aid' => $filters['annee_id']])
        ) ?: ['attendu' => 0, 'paye' => 0];
        $expected = (float) $financial['attendu'];
        $paid = (float) $financial['paye'];
        $marked = (int) $attendance['marques'];
        $resultCount = (int) $resultats['nb'];

        jsonResponse(true, 'Indicateurs récupérés.', [
            'kpis' => [
                'total_eleves' => $effectif,
                'total_classes' => $classes,
                'ratio_eleves_classe' => $classes > 0 ? round($effectif / $classes, 1) : null,
                'taux_presence' => $marked > 0 ? round(((int) $attendance['presents'] / $marked) * 100, 1) : null,
                'presences_saisies' => (int) $attendance['presents'],
                'absences_saisies' => (int) $attendance['absences'],
                'pointages_saisis' => $marked,
                'taux_reussite' => $resultCount > 0
                    ? round(((int) $resultats['reussites'] / $resultCount) * 100, 1)
                    : null,
                'resultats_saisis' => $resultCount,
                'moyenne_generale' => $resultats['moyenne'] !== null ? round((float) $resultats['moyenne'], 1) : null,
                'taux_recouvrement' => $expected > 0 ? round(($paid / $expected) * 100, 1) : null,
                'montant_attendu' => $expected,
                'montant_paye' => $paid,
                'annee' => $filters['annee']['libelle'],
                'trimestre' => $filters['trimestre_id']
                    ? $filters['trimestre']['libelle']
                    : 'Tous les trimestres',
            ],
        ]);
    }

    private function getResultatsParClasse(array $filters): void
    {
        $resultFilter = $this->resultFilter($filters, 'rt', true);
        $rows = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau,
                    COUNT(rt.moyenne_generale) AS resultats_saisis,
                    ROUND(AVG(rt.moyenne_generale), 1) AS moyenne,
                    SUM(rt.moyenne_generale >= 50) AS reussites,
                    ROUND(100 * SUM(rt.moyenne_generale >= 50) / NULLIF(COUNT(rt.moyenne_generale), 0), 1) AS taux_reussite
             FROM classes c
             LEFT JOIN inscriptions i ON i.classe_id = c.id
                 AND i.annee_scolaire_id = c.annee_scolaire_id
                 AND i.statut IN ('Actif', 'Redoublant')
             LEFT JOIN resultats_trimestriels rt ON rt.classe_id = c.id AND rt.eleve_id = i.eleve_id
                 {$resultFilter['result_join']}
             WHERE c.annee_scolaire_id = :aid{$resultFilter['class_sql']}
             GROUP BY c.id, c.nom, c.niveau
             ORDER BY c.niveau, c.nom",
            $resultFilter['params']
        );
        foreach ($rows as &$row) {
            $row['moyenne'] = $row['moyenne'] !== null ? (float) $row['moyenne'] : null;
            $row['taux_reussite'] = $row['taux_reussite'] !== null ? (float) $row['taux_reussite'] : null;
            $row['resultats_saisis'] = (int) $row['resultats_saisis'];
        }
        unset($row);
        jsonResponse(true, 'Résultats par classe récupérés.', ['resultats' => $rows]);
    }

    private function getResultatsParTrimestre(array $filters): void
    {
        $params = ['aid' => $filters['annee_id']];
        $classSql = $this->classCondition($filters, 'c.id', $params);
        $termSql = '';
        if ($filters['trimestre_id']) {
            $termSql = ' AND t.id = :tid';
            $params['tid'] = $filters['trimestre_id'];
        }
        $resultats = $this->db->fetchAll(
            "SELECT t.id AS trimestre_id, t.libelle AS trimestre, t.numero,
                    COUNT(DISTINCT i.eleve_id) AS effectif,
                    COUNT(rt.moyenne_generale) AS resultats_saisis,
                    SUM(rt.moyenne_generale >= 50) AS reussites,
                    SUM(rt.moyenne_generale < 50) AS echecs,
                    ROUND(AVG(rt.moyenne_generale), 1) AS moyenne,
                    ROUND(100 * SUM(rt.moyenne_generale >= 50) / NULLIF(COUNT(rt.moyenne_generale), 0), 1) AS taux_reussite,
                    ROUND(100 * COUNT(rt.moyenne_generale) / NULLIF(COUNT(DISTINCT i.eleve_id), 0), 1) AS couverture
             FROM trimestres t
             INNER JOIN classes c ON c.annee_scolaire_id = t.annee_scolaire_id
             LEFT JOIN inscriptions i ON i.classe_id = c.id
                 AND i.annee_scolaire_id = t.annee_scolaire_id
                 AND i.statut IN ('Actif', 'Redoublant')
             LEFT JOIN resultats_trimestriels rt ON rt.trimestre_id = t.id
                 AND rt.classe_id = c.id AND rt.eleve_id = i.eleve_id
             WHERE t.annee_scolaire_id = :aid{$classSql}{$termSql}
             GROUP BY t.id, t.libelle, t.numero
             ORDER BY t.numero",
            $params
        );
        foreach ($resultats as &$row) {
            foreach (['effectif', 'resultats_saisis', 'reussites', 'echecs'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            foreach (['moyenne', 'taux_reussite', 'couverture'] as $field) {
                $row[$field] = $row[$field] !== null ? (float) $row[$field] : null;
            }
        }
        unset($row);
        jsonResponse(true, 'Résultats par trimestre récupérés.', ['resultats' => $resultats]);
    }

    private function getRecouvrementParClasse(array $filters): void
    {
        $params = [
            'aid_expected' => $filters['annee_id'],
            'aid_paid' => $filters['annee_id'],
            'aid_classes' => $filters['annee_id'],
        ];
        $classSql = $this->classCondition($filters, 'c.id', $params);
        $rows = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau,
                    COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END) AS effectif,
                    COALESCE(attendu.montant_du, 0) AS montant_du,
                    COALESCE(paye.montant_paye, 0) AS montant_paye
             FROM classes c
             LEFT JOIN inscriptions i ON i.classe_id = c.id AND i.annee_scolaire_id = c.annee_scolaire_id
             LEFT JOIN (
                 SELECT ie.classe_id, SUM(f.montant) AS montant_du
                 FROM inscriptions ie
                 INNER JOIN frais_scolaires f ON f.annee_scolaire_id = ie.annee_scolaire_id
                     AND f.est_actif = 1 AND f.est_obligatoire = 1
                     AND (f.classe_id IS NULL OR f.classe_id = ie.classe_id)
                 WHERE ie.annee_scolaire_id = :aid_expected
                   AND ie.statut IN ('Actif', 'Redoublant')
                 GROUP BY ie.classe_id
             ) attendu ON attendu.classe_id = c.id
             LEFT JOIN (
                 SELECT ip.classe_id, SUM(p.montant_paye) AS montant_paye
                 FROM inscriptions ip
                 INNER JOIN frais_scolaires f ON f.annee_scolaire_id = ip.annee_scolaire_id
                     AND f.est_actif = 1 AND f.est_obligatoire = 1
                     AND (f.classe_id IS NULL OR f.classe_id = ip.classe_id)
                 INNER JOIN paiements p ON p.eleve_id = ip.eleve_id AND p.frais_scolaire_id = f.id
                 WHERE ip.annee_scolaire_id = :aid_paid
                   AND ip.statut IN ('Actif', 'Redoublant')
                 GROUP BY ip.classe_id
             ) paye ON paye.classe_id = c.id
             WHERE c.annee_scolaire_id = :aid_classes{$classSql}
             GROUP BY c.id, c.nom, c.niveau, attendu.montant_du, paye.montant_paye
             ORDER BY c.niveau, c.nom",
            $params
        );
        foreach ($rows as &$row) {
            $row['montant_du'] = (float) $row['montant_du'];
            $row['montant_paye'] = (float) $row['montant_paye'];
            $row['montant_impaye'] = max(0, $row['montant_du'] - $row['montant_paye']);
            $row['taux_recouvrement'] = $row['montant_du'] > 0
                ? round(($row['montant_paye'] / $row['montant_du']) * 100, 1)
                : null;
        }
        unset($row);
        jsonResponse(true, 'Recouvrement par classe récupéré.', ['recouvrement' => $rows]);
    }

    private function getPresencesParMois(array $filters): void
    {
        $presenceRows = $this->getMonthlyRows($filters, 'presence');
        jsonResponse(true, 'Présences récupérées.', ['presences' => $presenceRows]);
    }

    private function getIncidentsParMois(array $filters): void
    {
        $incidentRows = $this->getMonthlyRows($filters, 'incident');
        jsonResponse(true, 'Incidents récupérés.', ['incidents' => $incidentRows]);
    }

    private function getTopClasses(array $filters): void
    {
        $resultFilter = $this->resultFilter($filters, 'rt', true);
        $rows = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau,
                    COUNT(rt.moyenne_generale) AS resultats_saisis,
                    COUNT(DISTINCT rt.eleve_id) AS nb_eleves,
                    ROUND(AVG(rt.moyenne_generale), 1) AS moyenne,
                    SUM(rt.moyenne_generale >= 50) AS reussites,
                    ROUND(100 * SUM(rt.moyenne_generale >= 50) / NULLIF(COUNT(rt.moyenne_generale), 0), 1) AS taux_reussite
             FROM classes c
             INNER JOIN inscriptions i ON i.classe_id = c.id
                 AND i.annee_scolaire_id = c.annee_scolaire_id
                 AND i.statut IN ('Actif', 'Redoublant')
             INNER JOIN resultats_trimestriels rt ON rt.classe_id = c.id AND rt.eleve_id = i.eleve_id
                 {$resultFilter['result_join']}
             WHERE c.annee_scolaire_id = :aid{$resultFilter['class_sql']}
             GROUP BY c.id, c.nom, c.niveau
             HAVING COUNT(rt.moyenne_generale) > 0
             ORDER BY moyenne DESC LIMIT 5",
            $resultFilter['params']
        );
        foreach ($rows as &$row) {
            $row['moyenne'] = (float) $row['moyenne'];
            $row['taux_reussite'] = (float) $row['taux_reussite'];
        }
        unset($row);
        jsonResponse(true, 'Meilleures classes récupérées.', ['top_classes' => $rows]);
    }

    private function getClassesARisque(array $filters): void
    {
        $resultFilter = $this->resultFilter($filters, 'rt', true);
        $rows = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau,
                    COUNT(rt.moyenne_generale) AS resultats_saisis,
                    COUNT(DISTINCT rt.eleve_id) AS nb_eleves,
                    ROUND(AVG(rt.moyenne_generale), 1) AS moyenne,
                    SUM(rt.moyenne_generale >= 50) AS reussites,
                    SUM(rt.moyenne_generale < 50) AS echecs,
                    ROUND(100 * SUM(rt.moyenne_generale >= 50) / NULLIF(COUNT(rt.moyenne_generale), 0), 1) AS taux_reussite
             FROM classes c
             INNER JOIN inscriptions i ON i.classe_id = c.id
                 AND i.annee_scolaire_id = c.annee_scolaire_id
                 AND i.statut IN ('Actif', 'Redoublant')
             INNER JOIN resultats_trimestriels rt ON rt.classe_id = c.id AND rt.eleve_id = i.eleve_id
                 {$resultFilter['result_join']}
             WHERE c.annee_scolaire_id = :aid{$resultFilter['class_sql']}
             GROUP BY c.id, c.nom, c.niveau
             HAVING COUNT(rt.moyenne_generale) > 0 AND taux_reussite < 50
             ORDER BY taux_reussite, moyenne",
            $resultFilter['params']
        );
        foreach ($rows as &$row) {
            $row['moyenne'] = (float) $row['moyenne'];
            $row['taux_reussite'] = (float) $row['taux_reussite'];
        }
        unset($row);
        jsonResponse(true, 'Classes nécessitant un suivi récupérées.', ['classes_risque' => $rows]);
    }

    private function getMonthlyRows(array $filters, string $kind): array
    {
        $start = new DateTimeImmutable($filters['annee']['date_debut']);
        $end = new DateTimeImmutable(min($filters['annee']['date_fin'], date('Y-m-d')));
        $months = [];
        $cursor = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');
        while ($cursor <= $lastMonth) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'mois' => $key,
                'label' => moisEnFrancais((int) $cursor->format('n')) . ' ' . $cursor->format('Y'),
                'total' => 0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        $params = [
            'aid' => $filters['annee_id'],
            'debut' => $filters['annee']['date_debut'],
            'fin' => $filters['annee']['date_fin'],
        ];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        if ($kind === 'presence') {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(a.date_absence, '%Y-%m') AS mois,
                        COUNT(*) AS total,
                        SUM(a.type_absence = 'Présent') AS presents,
                        SUM(a.type_absence = 'Absence') AS absences
                 FROM absences a
                 INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
                 WHERE i.annee_scolaire_id = :aid
                   AND i.statut IN ('Actif', 'Redoublant')
                   AND a.date_absence BETWEEN :debut AND :fin{$classCondition}
                 GROUP BY mois ORDER BY mois",
                $params
            );
            foreach ($rows as $row) {
                if (!isset($months[$row['mois']])) {
                    continue;
                }
                $present = (int) $row['presents'];
                $absent = (int) $row['absences'];
                $total = (int) $row['total'];
                $months[$row['mois']]['total'] = $total;
                $months[$row['mois']]['presents'] = $present;
                $months[$row['mois']]['absences'] = $absent;
                $months[$row['mois']]['taux'] = $total > 0 ? round(($present / $total) * 100, 1) : null;
            }
            foreach ($months as &$month) {
                $month += ['presents' => 0, 'absences' => 0, 'taux' => null];
            }
            unset($month);
        } else {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(d.date_incident, '%Y-%m') AS mois,
                        COUNT(*) AS total,
                        SUM(s.gravite = 'légère') AS legeres,
                        SUM(s.gravite = 'moyenne') AS moyennes,
                        SUM(s.gravite = 'grave') AS graves
                 FROM discipline d
                 INNER JOIN inscriptions i ON i.eleve_id = d.eleve_id
                 INNER JOIN sanctions s ON s.id = d.sanction_id
                 WHERE i.annee_scolaire_id = :aid
                   AND i.statut IN ('Actif', 'Redoublant')
                   AND d.date_incident BETWEEN :debut AND :fin{$classCondition}
                 GROUP BY mois ORDER BY mois",
                $params
            );
            foreach ($rows as $row) {
                if (isset($months[$row['mois']])) {
                    $months[$row['mois']] = [
                        'mois' => $row['mois'],
                        'label' => $months[$row['mois']]['label'],
                        'total' => (int) $row['total'],
                        'legeres' => (int) $row['legeres'],
                        'moyennes' => (int) $row['moyennes'],
                        'graves' => (int) $row['graves'],
                    ];
                }
            }
            foreach ($months as &$month) {
                $month += ['legeres' => 0, 'moyennes' => 0, 'graves' => 0];
            }
            unset($month);
        }
        return array_values($months);
    }

    private function resultFilter(array $filters, string $alias, bool $includeTrimestre): array
    {
        $params = ['aid' => $filters['annee_id']];
        $resultJoin = '';
        if ($includeTrimestre && $filters['trimestre_id']) {
            $resultJoin = ' AND ' . $alias . '.trimestre_id = :tid';
            $params['tid'] = $filters['trimestre_id'];
        }
        $classSql = '';
        if ($filters['classe_id']) {
            $params['cid'] = $filters['classe_id'];
            $classSql = ' AND c.id = :cid';
        }
        return ['params' => $params, 'result_join' => $resultJoin, 'class_sql' => $classSql];
    }

    private function readFilters(): array
    {
        $yearRaw = post('annee_id', '');
        $yearId = $yearRaw === '' ? $this->getAnneeActiveId() : filter_var($yearRaw, FILTER_VALIDATE_INT);
        $year = $yearId ? $this->db->fetchOne(
            "SELECT id, libelle, date_debut, date_fin FROM annees_scolaires WHERE id = :id",
            ['id' => $yearId]
        ) : null;
        if (!$year) {
            jsonResponse(false, 'Année scolaire invalide.', [], 400);
        }

        $classRaw = post('classe_id', '');
        $classId = $classRaw === '' ? null : filter_var($classRaw, FILTER_VALIDATE_INT);
        if ($classRaw !== '' && (!$classId || !$this->db->fetchOne(
            "SELECT id FROM classes WHERE id = :cid AND annee_scolaire_id = :aid",
            ['cid' => $classId, 'aid' => $year['id']]
        ))) {
            jsonResponse(false, 'La classe sélectionnée ne correspond pas à cette année scolaire.', [], 400);
        }

        $termRaw = post('trimestre_id', '');
        $termId = $termRaw === '' ? null : filter_var($termRaw, FILTER_VALIDATE_INT);
        $term = null;
        if ($termRaw !== '') {
            $term = $termId ? $this->db->fetchOne(
                "SELECT id, libelle FROM trimestres WHERE id = :tid AND annee_scolaire_id = :aid",
                ['tid' => $termId, 'aid' => $year['id']]
            ) : null;
            if (!$term) {
                jsonResponse(false, 'Le trimestre sélectionné ne correspond pas à cette année scolaire.', [], 400);
            }
        }
        return [
            'annee_id' => (int) $year['id'],
            'annee' => $year,
            'classe_id' => $classId ? (int) $classId : null,
            'trimestre_id' => $termId ? (int) $termId : null,
            'trimestre' => $term,
        ];
    }

    private function classCondition(array $filters, string $column, array &$params): string
    {
        if (!$filters['classe_id']) {
            return '';
        }
        $params['cid'] = $filters['classe_id'];
        return " AND {$column} = :cid";
    }

    private function getAnneeActiveId(): int
    {
        $id = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        if (!$id) {
            $id = $this->db->fetchColumn("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
        }
        return (int) ($id ?? 0);
    }

    private function getTrimestreActifId(int $anneeId): ?int
    {
        if ($anneeId <= 0) {
            return null;
        }
        $id = $this->db->fetchColumn(
            "SELECT id FROM trimestres WHERE annee_scolaire_id = :aid AND est_actif = 1 LIMIT 1",
            ['aid' => $anneeId]
        );
        return $id ? (int) $id : null;
    }

    private function getClasses(int $anneeId): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom FROM classes WHERE annee_scolaire_id = :aid ORDER BY niveau, nom",
            ['aid' => $anneeId]
        );
    }

    private function getTrimestres(int $anneeId): array
    {
        return $this->db->fetchAll(
            "SELECT id, libelle, numero FROM trimestres
             WHERE annee_scolaire_id = :aid ORDER BY numero",
            ['aid' => $anneeId]
        );
    }

    private function findYear(array $years, int $id): bool
    {
        foreach ($years as $year) {
            if ((int) $year['id'] === $id) {
                return true;
            }
        }
        return false;
    }
}
