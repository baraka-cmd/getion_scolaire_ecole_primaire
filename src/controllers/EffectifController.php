<?php
/**
 * Fichier : src/controllers/EffectifController.php
 * Rôle    : Rapports filtrés des effectifs scolaires.
 */

defined('ACCESS') or die('Accès interdit');

class EffectifController
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
            $year = $yearId ? $this->getYear((int) $yearId) : null;
            if (!$year) {
                jsonResponse(false, 'Année scolaire invalide.', [], 400);
            }
            jsonResponse(true, 'Filtres récupérés.', ['classes' => $this->getClasses((int) $year['id'])]);
        }
        $filters = $this->readFilters();

        switch ($action) {
            case 'getStatistiques':
                $this->getStatistiques($filters);
                break;
            case 'getEffectifsParClasse':
                $this->getEffectifsParClasse($filters);
                break;
            case 'getRepartitionSexe':
                $this->getRepartitionSexe($filters);
                break;
            case 'getEvolutionEffectifs':
                $this->getEvolutionEffectifs($filters);
                break;
            case 'getPyramideAges':
                $this->getPyramideAges($filters);
                break;
            case 'getRepartitionParAge':
                $this->getRepartitionParAge($filters);
                break;
            case 'getTopClasses':
                $this->getTopClasses($filters);
                break;
            case 'getNouvellesInscriptions':
                $this->getNouvellesInscriptions($filters);
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

        return [
            'annees' => $annees,
            'annee_id' => $anneeId,
            'classes' => $anneeId > 0 ? $this->getClasses($anneeId) : [],
        ];
    }

    private function getStatistiques(array $filters): void
    {
        $params = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        $effectif = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT i.eleve_id) AS total,
                    COALESCE(SUM(e.sexe = 'M'), 0) AS garcons,
                    COALESCE(SUM(e.sexe = 'F'), 0) AS filles
             FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :aid
               AND i.statut IN ('Actif', 'Redoublant'){$classCondition}",
            $params
        ) ?: ['total' => 0, 'garcons' => 0, 'filles' => 0];

        $statutsParams = ['aid' => $filters['annee_id']];
        $statutClassCondition = $this->classCondition($filters, 'classe_id', $statutsParams);
        $statuts = $this->db->fetchAll(
            "SELECT statut, COUNT(*) AS nb FROM inscriptions
             WHERE annee_scolaire_id = :aid{$statutClassCondition}
             GROUP BY statut ORDER BY nb DESC",
            $statutsParams
        );

        $annee = $this->getYear($filters['annee_id']);
        $dateFinEffective = min($annee['date_fin'], date('Y-m-d'));
        $nouveaux = 0;
        if ($dateFinEffective >= $annee['date_debut']) {
            $newParams = [
                'aid' => $filters['annee_id'],
                'debut' => $annee['date_debut'],
                'fin' => $dateFinEffective,
            ];
            $newClassCondition = $this->classCondition($filters, 'classe_id', $newParams);
            $nouveaux = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions
                 WHERE annee_scolaire_id = :aid AND date_inscription BETWEEN :debut AND :fin{$newClassCondition}",
                $newParams
            );
        }

        $classParams = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'id', $classParams);
        $nbClasses = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = :aid{$classCondition}",
            $classParams
        );
        $total = (int) $effectif['total'];

        jsonResponse(true, 'Statistiques récupérées.', [
            'stats' => [
                'total_eleves' => $total,
                'total_garcons' => (int) $effectif['garcons'],
                'total_filles' => (int) $effectif['filles'],
                'pct_garcons' => $total > 0 ? round(((int) $effectif['garcons'] / $total) * 100, 1) : 0,
                'pct_filles' => $total > 0 ? round(((int) $effectif['filles'] / $total) * 100, 1) : 0,
                'ratio_gf' => (int) $effectif['filles'] > 0
                    ? round((int) $effectif['garcons'] / (int) $effectif['filles'], 2)
                    : null,
                'nouvelles_inscriptions' => $nouveaux,
                'nb_classes' => $nbClasses,
                'moyenne_par_classe' => $nbClasses > 0 ? round($total / $nbClasses, 1) : 0,
                'par_statut' => $statuts,
                'annee' => $annee['libelle'],
                'date_reference_age' => $annee['date_debut'],
            ],
        ]);
    }

    private function getEffectifsParClasse(array $filters): void
    {
        $params = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'c.id', $params);
        $classes = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau, c.capacite_max,
                    COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END) AS total_eleves,
                    COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') AND e.sexe = 'M' THEN i.eleve_id END) AS garcons,
                    COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') AND e.sexe = 'F' THEN i.eleve_id END) AS filles,
                    CASE WHEN c.capacite_max > 0
                         THEN ROUND(COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END) / c.capacite_max * 100, 1)
                         ELSE NULL END AS taux_remplissage,
                    (SELECT CONCAT(en.prenom, ' ', en.nom)
                     FROM enseignants en WHERE en.classe_id = c.id AND en.est_actif = 1 LIMIT 1) AS titulaire
             FROM classes c
             LEFT JOIN inscriptions i ON i.classe_id = c.id AND i.annee_scolaire_id = c.annee_scolaire_id
             LEFT JOIN eleves e ON e.id = i.eleve_id
             WHERE c.annee_scolaire_id = :aid{$classCondition}
             GROUP BY c.id, c.nom, c.niveau, c.capacite_max
             ORDER BY c.niveau, c.nom",
            $params
        );
        foreach ($classes as &$classe) {
            $classe['titulaire'] = $classe['titulaire'] ?: 'Non assigné';
            $classe['taux_remplissage'] = $classe['taux_remplissage'] === null
                ? null
                : (float) $classe['taux_remplissage'];
            $classe['places_disponibles'] = max(0, (int) $classe['capacite_max'] - (int) $classe['total_eleves']);
        }
        unset($classe);

        jsonResponse(true, 'Effectifs par classe récupérés.', ['effectifs' => $classes]);
    }

    private function getRepartitionSexe(array $filters): void
    {
        $params = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        $global = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT i.eleve_id) AS total,
                    COUNT(DISTINCT CASE WHEN e.sexe = 'M' THEN i.eleve_id END) AS garcons,
                    COUNT(DISTINCT CASE WHEN e.sexe = 'F' THEN i.eleve_id END) AS filles
             FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$classCondition}",
            $params
        ) ?: ['total' => 0, 'garcons' => 0, 'filles' => 0];

        jsonResponse(true, 'Répartition par sexe récupérée.', ['global' => $global]);
    }

    private function getEvolutionEffectifs(array $filters): void
    {
        $selectedClass = $filters['classe_id']
            ? $this->db->fetchOne(
                "SELECT nom FROM classes WHERE id = :cid AND annee_scolaire_id = :aid",
                ['cid' => $filters['classe_id'], 'aid' => $filters['annee_id']]
            )
            : null;
        $annees = $this->db->fetchAll(
            "SELECT id, libelle FROM annees_scolaires
             WHERE date_debut <= (SELECT date_debut FROM annees_scolaires WHERE id = :aid)
             ORDER BY date_debut DESC LIMIT 5",
            ['aid' => $filters['annee_id']]
        );

        $evolution = [];
        foreach (array_reverse($annees) as $annee) {
            $params = ['aid' => $annee['id']];
            $classJoin = '';
            $classFilter = '';
            if ($selectedClass) {
                $classJoin = ' INNER JOIN classes c ON c.id = i.classe_id';
                $classFilter = ' AND c.nom = :nom';
                $params['nom'] = $selectedClass['nom'];
            }
            $result = $this->db->fetchOne(
                "SELECT COUNT(DISTINCT i.eleve_id) AS total,
                        COUNT(DISTINCT CASE WHEN e.sexe = 'M' THEN i.eleve_id END) AS garcons,
                        COUNT(DISTINCT CASE WHEN e.sexe = 'F' THEN i.eleve_id END) AS filles
                 FROM inscriptions i{$classJoin}
                 INNER JOIN eleves e ON e.id = i.eleve_id
                 WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$classFilter}",
                $params
            ) ?: ['total' => 0, 'garcons' => 0, 'filles' => 0];
            $evolution[] = [
                'annee' => $annee['libelle'],
                'total' => (int) $result['total'],
                'garcons' => (int) $result['garcons'],
                'filles' => (int) $result['filles'],
            ];
        }

        jsonResponse(true, 'Évolution récupérée.', ['evolution' => $evolution]);
    }

    private function getPyramideAges(array $filters): void
    {
        $annee = $this->getYear($filters['annee_id']);
        $params = ['aid' => $filters['annee_id'], 'reference' => $annee['date_debut']];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        $rows = $this->db->fetchAll(
            "SELECT TIMESTAMPDIFF(YEAR, e.date_naissance, :reference) AS age, e.sexe, COUNT(*) AS nb
             FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$classCondition}
             GROUP BY age, e.sexe ORDER BY age",
            $params
        );
        $ages = [];
        foreach ($rows as $row) {
            $age = max(0, (int) $row['age']);
            if (!isset($ages[$age])) {
                $ages[$age] = ['age' => $age, 'garcons' => 0, 'filles' => 0];
            }
            if ($row['sexe'] === 'M') {
                $ages[$age]['garcons'] = (int) $row['nb'];
            } elseif ($row['sexe'] === 'F') {
                $ages[$age]['filles'] = (int) $row['nb'];
            }
        }
        $pyramide = array_values($ages);
        foreach ($pyramide as &$age) {
            $age['total'] = $age['garcons'] + $age['filles'];
        }
        unset($age);
        $ageStatsParams = [
            'aid' => $filters['annee_id'],
            'reference_min' => $annee['date_debut'],
            'reference_max' => $annee['date_debut'],
        ];
        $ageStatsClassCondition = $this->classCondition($filters, 'i.classe_id', $ageStatsParams);
        $ageStats = $this->db->fetchOne(
            "SELECT ROUND(AVG(TIMESTAMPDIFF(YEAR, e.date_naissance, :reference)), 1) AS age_moyen,
                    MIN(TIMESTAMPDIFF(YEAR, e.date_naissance, :reference_min)) AS age_min,
                    MAX(TIMESTAMPDIFF(YEAR, e.date_naissance, :reference_max)) AS age_max
             FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$ageStatsClassCondition}",
            array_merge($ageStatsParams, ['reference' => $annee['date_debut']])
        ) ?: [];

        jsonResponse(true, 'Pyramide des âges récupérée.', [
            'pyramide' => $pyramide,
            'age_moyen' => $ageStats['age_moyen'] !== null ? (float) $ageStats['age_moyen'] : null,
            'age_min' => $ageStats['age_min'] !== null ? (int) $ageStats['age_min'] : null,
            'age_max' => $ageStats['age_max'] !== null ? (int) $ageStats['age_max'] : null,
            'date_reference' => $annee['date_debut'],
        ]);
    }

    private function getRepartitionParAge(array $filters): void
    {
        $annee = $this->getYear($filters['annee_id']);
        $params = [
            'aid' => $filters['annee_id'],
            'reference1' => $annee['date_debut'],
            'reference2' => $annee['date_debut'],
            'reference3' => $annee['date_debut'],
            'reference4' => $annee['date_debut'],
            'reference5' => $annee['date_debut'],
        ];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        $repartition = $this->db->fetchAll(
            "SELECT CASE
                        WHEN TIMESTAMPDIFF(YEAR, e.date_naissance, :reference1) < 6 THEN 'Moins de 6 ans'
                        WHEN TIMESTAMPDIFF(YEAR, e.date_naissance, :reference2) BETWEEN 6 AND 8 THEN '6-8 ans'
                        WHEN TIMESTAMPDIFF(YEAR, e.date_naissance, :reference3) BETWEEN 9 AND 11 THEN '9-11 ans'
                        WHEN TIMESTAMPDIFF(YEAR, e.date_naissance, :reference4) BETWEEN 12 AND 14 THEN '12-14 ans'
                        ELSE '15 ans et plus'
                    END AS tranche_age,
                    COUNT(*) AS nb,
                    SUM(e.sexe = 'M') AS garcons,
                    SUM(e.sexe = 'F') AS filles
             FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant'){$classCondition}
             GROUP BY tranche_age
             ORDER BY MIN(TIMESTAMPDIFF(YEAR, e.date_naissance, :reference5))",
            $params
        );
        jsonResponse(true, 'Répartition par âge récupérée.', ['repartition' => $repartition]);
    }

    private function getTopClasses(array $filters): void
    {
        $params = ['aid' => $filters['annee_id']];
        $classCondition = $this->classCondition($filters, 'c.id', $params);
        $classes = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.capacite_max,
                    COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END) AS nb_eleves,
                    CASE WHEN c.capacite_max > 0
                         THEN ROUND(COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END) / c.capacite_max * 100, 1)
                         ELSE NULL END AS taux_remplissage,
                    GREATEST(c.capacite_max - COUNT(DISTINCT CASE WHEN i.statut IN ('Actif', 'Redoublant') THEN i.eleve_id END), 0) AS places_disponibles
             FROM classes c
             LEFT JOIN inscriptions i ON i.classe_id = c.id AND i.annee_scolaire_id = c.annee_scolaire_id
             WHERE c.annee_scolaire_id = :aid{$classCondition}
             GROUP BY c.id, c.nom, c.capacite_max
             ORDER BY taux_remplissage DESC, nb_eleves DESC LIMIT 5",
            $params
        );
        jsonResponse(true, 'Classes les plus remplies récupérées.', ['top_classes' => $classes]);
    }

    private function getNouvellesInscriptions(array $filters): void
    {
        $annee = $this->getYear($filters['annee_id']);
        $start = new DateTimeImmutable($annee['date_debut']);
        $end = new DateTimeImmutable(min($annee['date_fin'], date('Y-m-d')));
        $monthly = [];
        if ($end >= $start) {
            $cursor = $start->modify('first day of this month');
            $lastMonth = $end->modify('first day of this month');
            while ($cursor <= $lastMonth) {
                $key = $cursor->format('Y-m');
                $monthly[$key] = [
                    'mois' => $key,
                    'label' => moisEnFrancais((int) $cursor->format('n')) . ' ' . $cursor->format('Y'),
                    'nb' => 0,
                ];
                $cursor = $cursor->modify('+1 month');
            }
        }
        $params = [
            'aid' => $filters['annee_id'],
            'debut' => $annee['date_debut'],
            'fin' => $end->format('Y-m-d'),
        ];
        $classCondition = $this->classCondition($filters, 'i.classe_id', $params);
        if ($end >= $start) {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(i.date_inscription, '%Y-%m') AS mois, COUNT(*) AS nb
                 FROM inscriptions i
                 WHERE i.annee_scolaire_id = :aid AND i.date_inscription BETWEEN :debut AND :fin{$classCondition}
                 GROUP BY mois ORDER BY mois",
                $params
            );
            foreach ($rows as $row) {
                if (isset($monthly[$row['mois']])) {
                    $monthly[$row['mois']]['nb'] = (int) $row['nb'];
                }
            }
        }
        jsonResponse(true, 'Inscriptions récupérées.', ['inscriptions' => array_values($monthly)]);
    }

    private function readFilters(): array
    {
        $yearRaw = post('annee_id', '');
        $yearId = $yearRaw === '' ? $this->getAnneeActiveId() : filter_var($yearRaw, FILTER_VALIDATE_INT);
        $year = $yearId ? $this->getYear((int) $yearId) : null;
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
        return ['annee_id' => (int) $year['id'], 'classe_id' => $classId ? (int) $classId : null];
    }

    private function classCondition(array $filters, string $column, array &$params): string
    {
        if (!$filters['classe_id']) {
            return '';
        }
        $params['cid'] = $filters['classe_id'];
        return " AND {$column} = :cid";
    }

    private function getYear(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, libelle, date_debut, date_fin FROM annees_scolaires WHERE id = :id",
            ['id' => $id]
        );
    }

    private function getAnneeActiveId(): int
    {
        $id = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        if (!$id) {
            $id = $this->db->fetchColumn("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
        }
        return (int) ($id ?? 0);
    }

    private function getClasses(int $anneeId): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom FROM classes WHERE annee_scolaire_id = :aid ORDER BY niveau, nom",
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
