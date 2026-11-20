<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/FinanceService.php';

class FinanceReportService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function build(array $input): array
    {
        $years = $this->db->fetchAll(
            "SELECT id, libelle, date_debut, date_fin
             FROM annees_scolaires
             ORDER BY date_debut DESC"
        );
        $activeYearId = (int) $this->db->fetchColumn(
            "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
        );
        $yearId = (int) ($input['annee_id'] ?? 0);
        $year = $this->findById($years, $yearId);
        $invalidYear = $yearId > 0 && $year === null;
        if ($year === null) {
            $year = $this->findById($years, $activeYearId) ?? ($years[0] ?? null);
        }

        if ($year === null) {
            return [
                'years' => [],
                'year' => null,
                'classes' => [],
                'fees' => [],
                'categories' => [],
                'filters' => [],
                'errors' => [],
                'empty' => true,
            ];
        }

        $errors = [];
        if ($invalidYear) {
            $errors[] = 'L’année scolaire demandée est invalide; le rapport affiche l’année active.';
        }
        $classes = $this->db->fetchAll(
            "SELECT id, nom, niveau
             FROM classes
             WHERE annee_scolaire_id = :annee
             ORDER BY niveau, nom",
            ['annee' => (int) $year['id']]
        );
        $fees = $this->db->fetchAll(
            "SELECT f.id, f.libelle, f.classe_id, f.est_actif, f.est_obligatoire,
                    cfg.categorie, cfg.devise, cfg.periodicite,
                    c.nom AS classe_nom
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             LEFT JOIN classes c ON c.id = f.classe_id
             WHERE f.annee_scolaire_id = :annee
             ORDER BY cfg.categorie, f.libelle",
            ['annee' => (int) $year['id']]
        );
        $categories = array_values(array_unique(array_map(
            static function (array $fee): string {
                return (string) $fee['categorie'];
            },
            $fees
        )));

        $classId = (int) ($input['classe_id'] ?? 0);
        if ($classId > 0 && $this->findById($classes, $classId) === null) {
            $errors[] = 'La classe sélectionnée ne fait pas partie de cette année scolaire.';
            $classId = 0;
        }

        $feeId = (int) ($input['frais_id'] ?? 0);
        if ($feeId > 0 && $this->findById($fees, $feeId) === null) {
            $errors[] = 'Le frais sélectionné n’est pas configuré pour cette année scolaire.';
            $feeId = 0;
        }
        if ($feeId > 0 && $classId > 0) {
            $selectedFee = $this->findById($fees, $feeId);
            if ($selectedFee['classe_id'] !== null && (int) $selectedFee['classe_id'] !== $classId) {
                $errors[] = 'Le frais sélectionné ne concerne pas la classe choisie.';
                $feeId = 0;
            }
        }

        $dateDebut = $this->validatedDate(
            (string) ($input['date_debut'] ?? ''),
            (string) $year['date_debut'],
            'La date de début est invalide.',
            $errors
        );
        $dateFin = $this->validatedDate(
            (string) ($input['date_fin'] ?? ''),
            (string) $year['date_fin'],
            'La date de fin est invalide.',
            $errors
        );
        if ($dateDebut > $dateFin) {
            $errors[] = 'La période d’encaissement est incohérente : la date de début dépasse la date de fin.';
            $dateDebut = (string) $year['date_debut'];
            $dateFin = (string) $year['date_fin'];
        }

        $dateSituation = $this->validatedDate(
            (string) ($input['date_situation'] ?? ''),
            date('Y-m-d'),
            'La date de situation est invalide.',
            $errors
        );
        $categorie = (string) ($input['categorie'] ?? '');
        if ($categorie !== '' && !in_array($categorie, $categories, true)) {
            $errors[] = 'La catégorie sélectionnée est invalide.';
            $categorie = '';
        }
        $deviseCreance = (string) ($input['devise_creance'] ?? '');
        if (!in_array($deviseCreance, ['', 'USD', 'CDF'], true)) {
            $errors[] = 'La devise des créances sélectionnée est invalide.';
            $deviseCreance = '';
        }
        if ($feeId > 0) {
            $selectedFee = $this->findById($fees, $feeId);
            $feeCurrency = FinanceService::estFraisScolarite((string) $selectedFee['categorie'])
                ? 'USD'
                : (string) $selectedFee['devise'];
            if ($categorie !== '' && $selectedFee['categorie'] !== $categorie) {
                $errors[] = 'Le frais sélectionné n’appartient pas à la catégorie choisie.';
                $feeId = 0;
            } elseif ($deviseCreance !== '' && $feeCurrency !== $deviseCreance) {
                $errors[] = 'Le frais sélectionné n’utilise pas la devise de créance choisie.';
                $feeId = 0;
            }
        }
        $deviseRecue = (string) ($input['devise_recue'] ?? '');
        if (!in_array($deviseRecue, ['', 'USD', 'CDF'], true)) {
            $errors[] = 'La devise des versements sélectionnée est invalide.';
            $deviseRecue = '';
        }
        $mode = (string) ($input['mode'] ?? '');
        if ($mode !== '' && !in_array($mode, ['Espèces', 'Chèque', 'Virement bancaire', 'Mobile Money', 'Autre'], true)) {
            $errors[] = 'Le mode de paiement sélectionné est invalide.';
            $mode = '';
        }
        $etatCreance = (string) ($input['etat_creance'] ?? '');
        if (!in_array($etatCreance, ['', 'ouverte', 'retard', 'partielle', 'impayee', 'soldee'], true)) {
            $errors[] = 'L’état des créances sélectionné est invalide.';
            $etatCreance = '';
        }
        $recherche = trim((string) ($input['recherche'] ?? ''));
        if (strlen($recherche) > 100) {
            $recherche = substr($recherche, 0, 100);
        }

        $filters = [
            'annee_id' => (int) $year['id'],
            'classe_id' => $classId,
            'frais_id' => $feeId,
            'categorie' => $categorie,
            'devise_creance' => $deviseCreance,
            'devise_recue' => $deviseRecue,
            'mode' => $mode,
            'etat_creance' => $etatCreance,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'date_situation' => $dateSituation,
            'recherche' => $recherche,
        ];

        $base = $this->claimScope($filters);
        $summary = $this->db->fetchAll(
            "SELECT cr.devise,
                    COUNT(*) AS nombre_creances,
                    COUNT(DISTINCT cr.eleve_id) AS nombre_eleves,
                    COALESCE(SUM(cr.montant_du), 0) AS montant_du,
                    COALESCE(SUM(COALESCE(p.montant_paye, 0)), 0) AS montant_affecte,
                    COALESCE(SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)), 0) AS solde,
                    COALESCE(SUM(CASE WHEN cr.date_echeance < :retard_date
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_en_retard,
                    SUM(CASE WHEN cr.date_echeance < :retard_date_count
                        AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN 1 ELSE 0 END) AS creances_en_retard,
                    COALESCE(SUM(GREATEST(COALESCE(p.montant_paye, 0) - cr.montant_du, 0)), 0) AS montant_sureffectue,
                    SUM(CASE WHEN COALESCE(p.montant_paye, 0) > cr.montant_du THEN 1 ELSE 0 END) AS creances_sureffectuees
             {$base['from']}
             WHERE {$base['where']}
             GROUP BY cr.devise
             ORDER BY cr.devise",
            array_merge($base['params'], [
                'retard_date' => $dateSituation,
                'retard_date_count' => $dateSituation,
            ])
        );

        $studentStates = $this->db->fetchAll(
            "SELECT etat, COUNT(*) AS nombre_eleves
             FROM (
                 SELECT cr.eleve_id,
                        CASE
                            WHEN SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)) <= 0 THEN 'soldee'
                            WHEN SUM(COALESCE(p.montant_paye, 0)) > 0 THEN 'partielle'
                            ELSE 'impayee'
                        END AS etat
                 {$base['from']}
                 WHERE {$base['where']}
                 GROUP BY cr.eleve_id
             ) situations
             GROUP BY etat",
            $base['params']
        );

        $feesSummary = $this->db->fetchAll(
            "SELECT cr.frais_scolaire_id, fc.categorie, cr.libelle_snapshot, cr.devise,
                    COUNT(*) AS nombre_creances,
                    COUNT(DISTINCT cr.eleve_id) AS nombre_eleves,
                    COALESCE(SUM(cr.montant_du), 0) AS montant_du,
                    COALESCE(SUM(COALESCE(p.montant_paye, 0)), 0) AS montant_affecte,
                    COALESCE(SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)), 0) AS solde,
                    COALESCE(SUM(CASE WHEN cr.date_echeance < :retard_date
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_en_retard,
                    SUM(CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN 1 ELSE 0 END) AS creances_ouvertes
             {$base['from']}
             WHERE {$base['where']}
             GROUP BY cr.frais_scolaire_id, fc.categorie, cr.libelle_snapshot, cr.devise
             ORDER BY fc.categorie, cr.libelle_snapshot, cr.devise",
            array_merge($base['params'], ['retard_date' => $dateSituation])
        );

        $classesSummary = $this->db->fetchAll(
            "SELECT c.id AS classe_id, c.nom AS classe_nom, c.niveau, cr.devise,
                    COUNT(*) AS nombre_creances,
                    COUNT(DISTINCT cr.eleve_id) AS nombre_eleves,
                    COALESCE(SUM(cr.montant_du), 0) AS montant_du,
                    COALESCE(SUM(COALESCE(p.montant_paye, 0)), 0) AS montant_affecte,
                    COALESCE(SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)), 0) AS solde,
                    COALESCE(SUM(CASE WHEN cr.date_echeance < :retard_date
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS solde_en_retard,
                    COUNT(DISTINCT CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                        THEN cr.eleve_id END) AS eleves_debiteurs
             {$base['from']}
             WHERE {$base['where']}
             GROUP BY c.id, c.nom, c.niveau, cr.devise
             ORDER BY c.niveau, c.nom, cr.devise",
            array_merge($base['params'], ['retard_date' => $dateSituation])
        );

        $aging = $this->db->fetchAll(
            "SELECT cr.devise,
                    COALESCE(SUM(CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                        AND cr.date_echeance >= :aging_today
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS a_echeance,
                    COALESCE(SUM(CASE WHEN cr.date_echeance < :aging_30
                        AND DATEDIFF(:aging_30_b, cr.date_echeance) BETWEEN 1 AND 30
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS jours_1_30,
                    COALESCE(SUM(CASE WHEN DATEDIFF(:aging_60, cr.date_echeance) BETWEEN 31 AND 60
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS jours_31_60,
                    COALESCE(SUM(CASE WHEN DATEDIFF(:aging_90, cr.date_echeance) BETWEEN 61 AND 90
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS jours_61_90,
                    COALESCE(SUM(CASE WHEN DATEDIFF(:aging_older, cr.date_echeance) > 90
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS plus_90_jours,
                    COALESCE(SUM(CASE WHEN cr.date_echeance IS NULL
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS sans_echeance_montant,
                    SUM(CASE WHEN cr.date_echeance IS NULL
                        AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN 1 ELSE 0 END) AS nombre_sans_echeance
             {$base['from']}
             WHERE {$base['where']}
             GROUP BY cr.devise
             ORDER BY cr.devise",
            array_merge($base['params'], [
                'aging_today' => $dateSituation,
                'aging_30' => $dateSituation,
                'aging_30_b' => $dateSituation,
                'aging_60' => $dateSituation,
                'aging_90' => $dateSituation,
                'aging_older' => $dateSituation,
            ])
        );

        $page = max(1, (int) ($input['page'] ?? 1));
        $debtTotal = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) {$base['from']} WHERE {$base['where']}",
            $base['params']
        );
        $pageSize = 50;
        $pages = max(1, (int) ceil($debtTotal / $pageSize));
        $page = min($page, $pages);
        $debtParams = array_merge($base['params'], [
            'relance_year' => (int) $year['id'],
            'limit' => $pageSize,
            'offset' => ($page - 1) * $pageSize,
        ]);
        $debts = $this->db->fetchAll(
            "SELECT cr.id AS creance_id, cr.eleve_id, e.matricule, e.nom, e.prenom,
                    c.nom AS classe_nom, cr.frais_scolaire_id, fc.categorie, cr.libelle_snapshot,
                    cr.periode_code, cr.date_echeance, cr.devise, cr.montant_du,
                    COALESCE(p.montant_paye, 0) AS montant_affecte,
                    GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) AS solde,
                    (SELECT MIN(s.date_relance) FROM finance_suivis s
                     WHERE s.eleve_id = cr.eleve_id AND s.annee_scolaire_id = :relance_year
                       AND s.statut = 'à_faire' AND s.date_relance >= :relance_date) AS prochaine_relance
             {$base['from']}
             WHERE {$base['where']}
             ORDER BY cr.date_echeance IS NULL, cr.date_echeance, e.nom, e.prenom, cr.libelle_snapshot
             LIMIT :limit OFFSET :offset",
            array_merge($debtParams, ['relance_date' => $dateSituation])
        );

        $cashParams = [
            'cash_start' => $dateDebut,
            'cash_end' => $dateFin,
        ];
        $cashWhere = [
            'v.date_versement >= :cash_start',
            'v.date_versement < DATE_ADD(:cash_end, INTERVAL 1 DAY)',
        ];
        if ($classId > 0) {
            $cashWhere[] = "EXISTS (SELECT 1 FROM inscriptions cc
                            WHERE cc.eleve_id = v.eleve_id AND cc.annee_scolaire_id = :cash_class_year
                              AND cc.classe_id = :cash_class)";
            $cashParams['cash_class_year'] = (int) $year['id'];
            $cashParams['cash_class'] = $classId;
        }
        if ($mode !== '') {
            $cashWhere[] = 'v.mode_paiement = :cash_mode';
            $cashParams['cash_mode'] = $mode;
        }
        if ($deviseRecue !== '') {
            $cashWhere[] = 'v.devise_recue = :cash_currency';
            $cashParams['cash_currency'] = $deviseRecue;
        }
        if ($recherche !== '') {
            $cashWhere[] = '(e.nom LIKE :cash_r1 OR e.prenom LIKE :cash_r2 OR e.matricule LIKE :cash_r3 OR v.numero_recu LIKE :cash_r4)';
            $term = '%' . $recherche . '%';
            $cashParams['cash_r1'] = $term;
            $cashParams['cash_r2'] = $term;
            $cashParams['cash_r3'] = $term;
            $cashParams['cash_r4'] = $term;
        }
        $cashFrom = "FROM finance_versements v
                     INNER JOIN eleves e ON e.id = v.eleve_id
                     LEFT JOIN finance_annulations an ON an.versement_id = v.id";
        $cashFilter = implode(' AND ', $cashWhere);

        $cashSummary = $this->db->fetchAll(
            "SELECT v.devise_recue,
                    COALESCE(SUM(CASE WHEN an.id IS NULL THEN v.montant_recu ELSE 0 END), 0) AS montant_actif,
                    COALESCE(SUM(CASE WHEN an.type = 'annulation' THEN v.montant_recu ELSE 0 END), 0) AS montant_annule,
                    COALESCE(SUM(CASE WHEN an.type = 'remboursement' THEN v.montant_recu ELSE 0 END), 0) AS montant_rembourse,
                    COUNT(DISTINCT CASE WHEN an.id IS NULL THEN v.id END) AS versements_actifs,
                    COUNT(DISTINCT CASE WHEN an.type = 'annulation' THEN v.id END) AS nombre_annule,
                    COUNT(DISTINCT CASE WHEN an.type = 'remboursement' THEN v.id END) AS nombre_rembourse,
                    COALESCE(SUM(CASE WHEN an.id IS NULL AND v.devise_recue = 'USD'
                        THEN v.montant_recu
                        WHEN an.id IS NULL AND v.devise_recue = 'CDF'
                        THEN v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0)
                        ELSE 0 END), 0) AS equivalent_usd
             {$cashFrom}
             WHERE {$cashFilter}
             GROUP BY v.devise_recue
             ORDER BY v.devise_recue",
            $cashParams
        );

        $cashReconciliation = $this->db->fetchAll(
            "SELECT devise_recue,
                    COALESCE(SUM(GREATEST(montant_recu - montant_affecte_converti, 0)), 0) AS non_affecte,
                    COALESCE(SUM(GREATEST(montant_affecte_converti - montant_recu, 0)), 0) AS affecte_en_exces,
                    SUM(CASE WHEN ABS(montant_recu - montant_affecte_converti) > 0.01 THEN 1 ELSE 0 END) AS versements_avec_ecart
             FROM (
                 SELECT v.id, v.devise_recue, v.montant_recu,
                        COALESCE(SUM(CASE
                            WHEN cr.devise = v.devise_recue THEN a.montant_affecte
                            WHEN v.devise_recue = 'CDF' AND cr.devise = 'USD'
                                THEN a.montant_affecte * v.taux_cdf_par_usd
                            WHEN v.devise_recue = 'USD' AND cr.devise = 'CDF'
                                THEN a.montant_affecte / NULLIF(v.taux_cdf_par_usd, 0)
                            ELSE 0
                        END), 0) AS montant_affecte_converti
                 {$cashFrom}
                 LEFT JOIN finance_affectations a ON a.versement_id = v.id
                 LEFT JOIN finance_creances cr ON cr.id = a.creance_id
                 WHERE {$cashFilter} AND an.id IS NULL
                 GROUP BY v.id, v.devise_recue, v.montant_recu
             ) reconciliation
             GROUP BY devise_recue
             ORDER BY devise_recue",
            $cashParams
        );

        $cashByMode = $this->db->fetchAll(
            "SELECT v.mode_paiement, v.devise_recue,
                    SUM(CASE WHEN an.id IS NULL THEN v.montant_recu ELSE 0 END) AS montant_actif,
                    COUNT(DISTINCT CASE WHEN an.id IS NULL THEN v.id END) AS nombre_actif,
                    COUNT(DISTINCT CASE WHEN an.id IS NOT NULL THEN v.id END) AS nombre_corrige
             {$cashFrom}
             WHERE {$cashFilter}
             GROUP BY v.mode_paiement, v.devise_recue
             ORDER BY v.mode_paiement, v.devise_recue",
            $cashParams
        );

        $cashMonthly = $this->db->fetchAll(
            "SELECT DATE_FORMAT(v.date_versement, '%Y-%m') AS mois, v.devise_recue,
                    SUM(CASE WHEN an.id IS NULL THEN v.montant_recu ELSE 0 END) AS montant_actif,
                    COUNT(DISTINCT CASE WHEN an.id IS NULL THEN v.id END) AS nombre_actif,
                    COALESCE(SUM(CASE WHEN an.id IS NULL AND v.devise_recue = 'USD'
                        THEN v.montant_recu
                        WHEN an.id IS NULL AND v.devise_recue = 'CDF'
                        THEN v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0)
                        ELSE 0 END), 0) AS equivalent_usd
             {$cashFrom}
             WHERE {$cashFilter}
             GROUP BY DATE_FORMAT(v.date_versement, '%Y-%m'), v.devise_recue
             ORDER BY mois, v.devise_recue",
            $cashParams
        );

        $cashEntries = $this->db->fetchAll(
            "SELECT v.id, v.numero_recu, v.montant_recu, v.devise_recue, v.taux_cdf_par_usd,
                    v.mode_paiement, v.reference_paiement, v.date_versement,
                    e.id AS eleve_id, e.matricule, e.nom, e.prenom,
                    c.nom AS classe_nom, an.type AS type_correction, an.numero_contre_ecriture
             {$cashFrom}
             LEFT JOIN inscriptions i ON i.eleve_id = e.id AND i.annee_scolaire_id = :entry_year
             LEFT JOIN classes c ON c.id = i.classe_id
             WHERE {$cashFilter}
             ORDER BY v.date_versement DESC, v.id DESC
             LIMIT 30",
            array_merge($cashParams, ['entry_year' => (int) $year['id']])
        );

        $allocParams = [
            'alloc_year' => (int) $year['id'],
            'alloc_start' => $dateDebut,
            'alloc_end' => $dateFin,
        ];
        $allocWhere = [
            'cr.annee_scolaire_id = :alloc_year',
            'v.date_versement >= :alloc_start',
            'v.date_versement < DATE_ADD(:alloc_end, INTERVAL 1 DAY)',
            'an.id IS NULL',
        ];
        if ($classId > 0) {
            $allocWhere[] = 'i.classe_id = :alloc_class';
            $allocParams['alloc_class'] = $classId;
        }
        if ($feeId > 0) {
            $allocWhere[] = 'cr.frais_scolaire_id = :alloc_fee';
            $allocParams['alloc_fee'] = $feeId;
        }
        if ($categorie !== '') {
            $allocWhere[] = 'fc.categorie = :alloc_category';
            $allocParams['alloc_category'] = $categorie;
        }
        if ($deviseCreance !== '') {
            $allocWhere[] = 'cr.devise = :alloc_currency';
            $allocParams['alloc_currency'] = $deviseCreance;
        }
        if ($recherche !== '') {
            $allocWhere[] = '(e.nom LIKE :alloc_r1 OR e.prenom LIKE :alloc_r2 OR e.matricule LIKE :alloc_r3)';
            $term = '%' . $recherche . '%';
            $allocParams['alloc_r1'] = $term;
            $allocParams['alloc_r2'] = $term;
            $allocParams['alloc_r3'] = $term;
        }
        $allocations = $this->db->fetchAll(
            "SELECT cr.frais_scolaire_id, fc.categorie, cr.libelle_snapshot, cr.devise,
                    SUM(a.montant_affecte) AS montant_affecte,
                    COUNT(DISTINCT v.id) AS nombre_versements
             FROM finance_affectations a
             INNER JOIN finance_versements v ON v.id = a.versement_id
             INNER JOIN finance_creances cr ON cr.id = a.creance_id
             INNER JOIN finance_frais_config fc ON fc.frais_scolaire_id = cr.frais_scolaire_id
             INNER JOIN eleves e ON e.id = cr.eleve_id
             INNER JOIN inscriptions i ON i.eleve_id = cr.eleve_id AND i.annee_scolaire_id = cr.annee_scolaire_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             WHERE " . implode(' AND ', $allocWhere) . "
             GROUP BY cr.frais_scolaire_id, fc.categorie, cr.libelle_snapshot, cr.devise
             ORDER BY fc.categorie, cr.libelle_snapshot, cr.devise",
            $allocParams
        );

        $followups = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN s.date_relance < :followup_late THEN 1 ELSE 0 END) AS en_retard,
                    SUM(CASE WHEN s.date_relance >= :followup_today THEN 1 ELSE 0 END) AS a_venir
             FROM finance_suivis s
             WHERE s.annee_scolaire_id = :followup_year
               AND s.statut = 'à_faire'
               AND (s.date_relance IS NULL OR s.date_relance <= :followup_end)
               AND (:followup_class = 0 OR EXISTS (
                   SELECT 1 FROM inscriptions fi
                   WHERE fi.eleve_id = s.eleve_id AND fi.annee_scolaire_id = :followup_class_year
                     AND fi.classe_id = :followup_class_match
               ))",
            [
                'followup_late' => $dateSituation,
                'followup_today' => $dateSituation,
                'followup_year' => (int) $year['id'],
                'followup_end' => $dateFin,
                'followup_class' => $classId,
                'followup_class_year' => (int) $year['id'],
                'followup_class_match' => $classId,
            ]
        );

        $coverage = $this->getCoverage($filters, $year);

        return [
            'years' => $years,
            'year' => $year,
            'classes' => $classes,
            'fees' => $fees,
            'categories' => $categories,
            'filters' => $filters,
            'errors' => $errors,
            'empty' => false,
            'summary' => $this->fillCurrencies($summary),
            'student_states' => $studentStates,
            'fees_summary' => $feesSummary,
            'classes_summary' => $classesSummary,
            'aging' => $this->fillCurrencies($aging),
            'debts' => $debts,
            'debt_total' => $debtTotal,
            'debt_page' => $page,
            'debt_pages' => $pages,
            'cash_summary' => $this->fillCurrencies($cashSummary, 'devise_recue'),
            'cash_reconciliation' => $this->fillCurrencies($cashReconciliation, 'devise_recue'),
            'cash_by_mode' => $cashByMode,
            'cash_monthly' => $cashMonthly,
            'cash_entries' => $cashEntries,
            'allocations' => $allocations,
            'followups' => $followups ?? ['total' => 0, 'en_retard' => 0, 'a_venir' => 0],
            'coverage' => $coverage,
            'cash_filter_note' => 'Le filtre de frais concerne les créances et leurs affectations. Les encaissements de caisse restent calculés par date, classe, mode et devise, sans répartir artificiellement un reçu entre plusieurs frais.',
        ];
    }

    public function streamClaimsCsv(array $report): void
    {
        if ($report['empty']) {
            return;
        }
        $filters = $report['filters'];
        $base = $this->claimScope($filters);
        $sql = "SELECT cr.id, e.matricule, e.nom, e.prenom, c.nom AS classe_nom,
                       fc.categorie, cr.libelle_snapshot, cr.periode_code, cr.date_echeance,
                       cr.devise, cr.montant_du, COALESCE(p.montant_paye, 0) AS montant_affecte,
                       GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) AS solde
                {$base['from']}
                WHERE {$base['where']}
                ORDER BY c.niveau, c.nom, e.nom, e.prenom, cr.date_echeance";
        $statement = $this->db->query($sql, $base['params']);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rapport-financier-creances-' . $filters['annee_id'] . '.csv"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException('Impossible de créer le fichier CSV du rapport.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Année scolaire', 'Date de situation', 'Matricule', 'Prénom', 'Nom', 'Classe',
            'Catégorie', 'Frais', 'Période', 'Échéance', 'Devise', 'Dû', 'Affecté', 'Solde'
        ], ';', '"', '\\');
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $this->csvSafeCell($report['year']['libelle']),
                $filters['date_situation'],
                $this->csvSafeCell($row['matricule']),
                $this->csvSafeCell($row['prenom']),
                $this->csvSafeCell($row['nom']),
                $this->csvSafeCell($row['classe_nom']),
                $this->csvSafeCell($row['categorie']),
                $this->csvSafeCell($row['libelle_snapshot']),
                $this->csvSafeCell($row['periode_code']),
                $row['date_echeance'],
                $row['devise'],
                $row['montant_du'],
                $row['montant_affecte'],
                $row['solde'],
            ], ';', '"', '\\');
        }
        fclose($output);
    }

    private function claimScope(array $filters, bool $includeState = true): array
    {
        $params = [
            'claim_year' => $filters['annee_id'],
            'claim_created_before' => $filters['date_situation'],
            'paid_before' => $filters['date_situation'],
            'reversed_after' => $filters['date_situation'] . ' 23:59:59',
        ];
        $where = [
            'cr.annee_scolaire_id = :claim_year',
            'cr.created_at < DATE_ADD(:claim_created_before, INTERVAL 1 DAY)',
        ];
        if ($filters['classe_id'] > 0) {
            $where[] = 'i.classe_id = :claim_class';
            $params['claim_class'] = $filters['classe_id'];
        }
        if ($filters['frais_id'] > 0) {
            $where[] = 'cr.frais_scolaire_id = :claim_fee';
            $params['claim_fee'] = $filters['frais_id'];
        }
        if ($filters['categorie'] !== '') {
            $where[] = 'fc.categorie = :claim_category';
            $params['claim_category'] = $filters['categorie'];
        }
        if ($filters['devise_creance'] !== '') {
            $where[] = 'cr.devise = :claim_currency';
            $params['claim_currency'] = $filters['devise_creance'];
        }
        if ($filters['recherche'] !== '') {
            $where[] = '(e.nom LIKE :claim_r1 OR e.prenom LIKE :claim_r2 OR e.matricule LIKE :claim_r3)';
            $term = '%' . $filters['recherche'] . '%';
            $params['claim_r1'] = $term;
            $params['claim_r2'] = $term;
            $params['claim_r3'] = $term;
        }
        if ($includeState) {
            $balance = 'GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)';
            if ($filters['etat_creance'] === 'ouverte') {
                $where[] = $balance . ' > 0';
            } elseif ($filters['etat_creance'] === 'retard') {
                $where[] = 'cr.date_echeance < :claim_late_date AND ' . $balance . ' > 0';
                $params['claim_late_date'] = $filters['date_situation'];
            } elseif ($filters['etat_creance'] === 'partielle') {
                $where[] = 'COALESCE(p.montant_paye, 0) > 0 AND ' . $balance . ' > 0';
            } elseif ($filters['etat_creance'] === 'impayee') {
                $where[] = 'COALESCE(p.montant_paye, 0) <= 0 AND ' . $balance . ' > 0';
            } elseif ($filters['etat_creance'] === 'soldee') {
                $where[] = $balance . ' = 0';
            }
        }

        return [
            'from' => "FROM finance_creances cr
                       INNER JOIN finance_frais_config fc ON fc.frais_scolaire_id = cr.frais_scolaire_id
                       INNER JOIN eleves e ON e.id = cr.eleve_id
                       INNER JOIN inscriptions i ON i.eleve_id = cr.eleve_id
                           AND i.annee_scolaire_id = cr.annee_scolaire_id
                       INNER JOIN classes c ON c.id = i.classe_id
                       LEFT JOIN (
                           SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
                           FROM finance_affectations a
                           INNER JOIN finance_versements v ON v.id = a.versement_id
                           LEFT JOIN finance_annulations an ON an.versement_id = v.id
                           WHERE v.date_versement < DATE_ADD(:paid_before, INTERVAL 1 DAY)
                             AND (an.id IS NULL OR an.date_annulation > :reversed_after)
                           GROUP BY a.creance_id
                       ) p ON p.creance_id = cr.id",
            'where' => implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function getCoverage(array $filters, array $year): array
    {
        $params = ['coverage_year' => $filters['annee_id']];
        $enrollmentWhere = [
            'i.annee_scolaire_id = :coverage_year',
            "i.statut IN ('Actif', 'Redoublant')",
            'e.est_actif = 1',
        ];
        if ($filters['classe_id'] > 0) {
            $enrollmentWhere[] = 'i.classe_id = :coverage_class';
            $params['coverage_class'] = $filters['classe_id'];
        }
        $enrollments = $this->db->fetchAll(
            "SELECT i.eleve_id, i.classe_id FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE " . implode(' AND ', $enrollmentWhere),
            $params
        );

        $feeParams = ['coverage_fee_year' => $filters['annee_id']];
        $feeWhere = ['f.annee_scolaire_id = :coverage_fee_year', 'f.est_actif = 1'];
        if ($filters['frais_id'] > 0) {
            $feeWhere[] = 'f.id = :coverage_fee';
            $feeParams['coverage_fee'] = $filters['frais_id'];
        }
        if ($filters['categorie'] !== '') {
            $feeWhere[] = 'cfg.categorie = :coverage_category';
            $feeParams['coverage_category'] = $filters['categorie'];
        }
        $feeConfigs = $this->db->fetchAll(
            "SELECT f.id, f.libelle, f.montant, f.classe_id,
                    cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             WHERE " . implode(' AND ', $feeWhere) . "
             ORDER BY cfg.categorie, f.libelle",
            $feeParams
        );
        $existing = $this->db->fetchAll(
            "SELECT eleve_id, frais_scolaire_id, periode_code, devise
             FROM finance_creances
             WHERE annee_scolaire_id = :existing_year",
            ['existing_year' => $filters['annee_id']]
        );
        $existingKeys = [];
        foreach ($existing as $claim) {
            $existingKeys[$claim['frais_scolaire_id'] . ':' . $claim['eleve_id'] . ':' . $claim['periode_code']] = $claim['devise'];
        }

        $expected = 0;
        $generated = 0;
        $currencyMismatches = 0;
        $missingByFee = [];
        foreach ($feeConfigs as $fee) {
            $feeCurrency = FinanceService::estFraisScolarite((string) $fee['categorie'])
                ? 'USD'
                : (string) $fee['devise'];
            if ($filters['devise_creance'] !== '' && $filters['devise_creance'] !== $feeCurrency) {
                continue;
            }
            $periods = $this->periodCodes(
                (string) $fee['periodicite'],
                (string) $year['date_debut'],
                (string) $year['date_fin']
            );
            $missing = 0;
            $feeExpected = 0;
            foreach ($enrollments as $enrollment) {
                if ($fee['classe_id'] !== null && (int) $fee['classe_id'] !== (int) $enrollment['classe_id']) {
                    continue;
                }
                foreach ($periods as $period) {
                    $feeExpected++;
                    $expected++;
                    $key = $fee['id'] . ':' . $enrollment['eleve_id'] . ':' . $period;
                    if (isset($existingKeys[$key])) {
                        $generated++;
                        if ($existingKeys[$key] !== $feeCurrency) {
                            $currencyMismatches++;
                        }
                    } else {
                        $missing++;
                    }
                }
            }
            if ($missing > 0) {
                $missingByFee[] = [
                    'frais_id' => (int) $fee['id'],
                    'libelle' => (string) $fee['libelle'],
                    'categorie' => (string) $fee['categorie'],
                    'attendues' => $feeExpected,
                    'manquantes' => $missing,
                ];
            }
        }

        return [
            'attendues' => $expected,
            'generees' => $generated,
            'manquantes' => max(0, $expected - $generated),
            'ecarts_devise' => $currencyMismatches,
            'par_frais' => $missingByFee,
        ];
    }

    private function periodCodes(string $periodicity, string $start, string $end): array
    {
        $begin = new DateTimeImmutable($start);
        $finish = new DateTimeImmutable($end);
        if ($periodicity === 'trimestriel') {
            $periods = [];
            foreach ([0, 4, 8] as $index => $months) {
                if (($date = $begin->modify('+' . $months . ' months')) <= $finish) {
                    $periods[] = 'T' . ($index + 1);
                }
            }
            return $periods;
        }
        if ($periodicity === 'mensuel') {
            $periods = [];
            $month = $begin->modify('first day of this month');
            $lastMonth = $finish->modify('first day of this month');
            while ($month <= $lastMonth) {
                $periods[] = $month->format('Y-m');
                $month = $month->modify('+1 month');
            }
            return $periods;
        }
        return [$periodicity === 'unique' ? 'UNIQUE' : 'ANNUEL'];
    }

    private function fillCurrencies(array $rows, string $key = 'devise'): array
    {
        $byCurrency = ['USD' => null, 'CDF' => null];
        foreach ($rows as $row) {
            if (array_key_exists($row[$key], $byCurrency)) {
                $byCurrency[$row[$key]] = $row;
            }
        }
        foreach ($byCurrency as $currency => &$row) {
            if ($row === null) {
                $row = ['devise' => $currency, 'devise_recue' => $currency];
            }
        }
        unset($row);
        return $byCurrency;
    }

    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    private function validatedDate(string $value, string $fallback, string $error, array &$errors): string
    {
        if ($value === '') {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = $error;
            return $fallback;
        }
        return $value;
    }

    private function csvSafeCell($value): mixed
    {
        if (is_string($value) && preg_match('/^[\t\r\n ]*[=+\-@]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
