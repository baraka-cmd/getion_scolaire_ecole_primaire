<?php
require_once SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'database.php';
global $db;

$anneeActive = (int) $db->fetchColumn(
    "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
);
if ($anneeActive <= 0) {
    $anneeActive = (int) $db->fetchColumn(
        "SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1"
    );
}
$anneeLibelle = (string) $db->fetchColumn(
    "SELECT libelle FROM annees_scolaires WHERE id = :id",
    ['id' => $anneeActive]
);

$soldes = $db->fetchOne(
    "SELECT
        COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN cr.montant_du ELSE 0 END), 0) AS du_usd,
        COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN COALESCE(p.montant_paye, 0) ELSE 0 END), 0) AS paye_usd,
        COALESCE(SUM(CASE WHEN cr.devise = 'USD' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS reste_usd,
        COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN cr.montant_du ELSE 0 END), 0) AS du_cdf,
        COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN COALESCE(p.montant_paye, 0) ELSE 0 END), 0) AS paye_cdf,
        COALESCE(SUM(CASE WHEN cr.devise = 'CDF' THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END), 0) AS reste_cdf
     FROM finance_creances cr
     LEFT JOIN (
         SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
         FROM finance_affectations a
         INNER JOIN finance_versements v ON v.id = a.versement_id
         LEFT JOIN finance_annulations an ON an.versement_id = v.id
         WHERE an.id IS NULL
         GROUP BY a.creance_id
     ) p ON p.creance_id = cr.id
     WHERE cr.annee_scolaire_id = :annee",
    ['annee' => $anneeActive]
);

$receiptsMonth = $db->fetchOne(
    "SELECT
        COALESCE(SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END), 0) AS usd,
        COALESCE(SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END), 0) AS cdf,
        COUNT(*) AS nombre
     FROM finance_versements v
     LEFT JOIN finance_annulations an ON an.versement_id = v.id
     WHERE an.id IS NULL
       AND YEAR(v.date_versement) = YEAR(CURDATE()) AND MONTH(v.date_versement) = MONTH(CURDATE())
       AND EXISTS (
           SELECT 1
           FROM finance_affectations a
           INNER JOIN finance_creances cr ON cr.id = a.creance_id
           WHERE a.versement_id = v.id AND cr.annee_scolaire_id = :annee_exists
       )",
    ['annee_exists' => $anneeActive]
);

$nbEcheancesRetard = (int) $db->fetchColumn(
    "SELECT COUNT(*)
     FROM finance_creances cr
     LEFT JOIN (
         SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
         FROM finance_affectations a
         INNER JOIN finance_versements v ON v.id = a.versement_id
         LEFT JOIN finance_annulations an ON an.versement_id = v.id
         WHERE an.id IS NULL GROUP BY a.creance_id
     ) p ON p.creance_id = cr.id
     WHERE cr.annee_scolaire_id = :annee AND cr.date_echeance < CURDATE()
       AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0",
    ['annee' => $anneeActive]
);

$suivisAVenir = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM finance_suivis
     WHERE annee_scolaire_id = :annee AND statut = 'à_faire'
       AND date_relance >= CURDATE()",
    ['annee' => $anneeActive]
);

$monthlyReceipts = $db->fetchAll(
    "SELECT DATE_FORMAT(v.date_versement, '%Y-%m') AS mois,
            COALESCE(SUM(CASE
                WHEN v.devise_recue = 'USD' THEN v.montant_recu
                WHEN v.devise_recue = 'CDF' THEN v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0)
                ELSE 0
            END), 0) AS equivalent_usd
     FROM finance_versements v
     LEFT JOIN finance_annulations an ON an.versement_id = v.id
     WHERE an.id IS NULL
       AND v.date_versement >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
       AND v.date_versement < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
       AND EXISTS (
           SELECT 1
           FROM finance_affectations a
           INNER JOIN finance_creances cr ON cr.id = a.creance_id
           WHERE a.versement_id = v.id AND cr.annee_scolaire_id = :annee
       )
     GROUP BY DATE_FORMAT(v.date_versement, '%Y-%m')
     ORDER BY mois",
    ['annee' => $anneeActive]
);

$classBalances = $db->fetchAll(
    "SELECT c.id, c.nom,
            COUNT(DISTINCT CASE
                WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                THEN cr.eleve_id
            END) AS eleves_debiteurs,
            COALESCE(SUM(CASE
                WHEN cr.devise = 'USD'
                THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)
                ELSE 0
            END), 0) AS solde_usd,
            COALESCE(SUM(CASE
                WHEN cr.devise = 'CDF'
                THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)
                ELSE 0
            END), 0) AS solde_cdf
     FROM classes c
     INNER JOIN inscriptions i ON i.classe_id = c.id AND i.annee_scolaire_id = :inscription_year
     INNER JOIN finance_creances cr ON cr.eleve_id = i.eleve_id AND cr.annee_scolaire_id = i.annee_scolaire_id
     LEFT JOIN (
         SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
         FROM finance_affectations a
         INNER JOIN finance_versements v ON v.id = a.versement_id
         LEFT JOIN finance_annulations an ON an.versement_id = v.id
         WHERE an.id IS NULL
         GROUP BY a.creance_id
     ) p ON p.creance_id = cr.id
     GROUP BY c.id, c.nom
     HAVING eleves_debiteurs > 0
     ORDER BY eleves_debiteurs DESC, solde_usd DESC, solde_cdf DESC
     LIMIT 8",
    ['inscription_year' => $anneeActive]
);

$studentStates = $db->fetchAll(
    "SELECT etat, COUNT(*) AS nombre
     FROM (
         SELECT cr.eleve_id,
                CASE
                    WHEN SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)) <= 0 THEN 'soldee'
                    WHEN SUM(COALESCE(p.montant_paye, 0)) > 0 THEN 'partielle'
                    ELSE 'impayee'
                END AS etat
         FROM finance_creances cr
         LEFT JOIN (
             SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
             FROM finance_affectations a
             INNER JOIN finance_versements v ON v.id = a.versement_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             WHERE an.id IS NULL
             GROUP BY a.creance_id
         ) p ON p.creance_id = cr.id
         WHERE cr.annee_scolaire_id = :annee
         GROUP BY cr.eleve_id
     ) situations
     GROUP BY etat",
    ['annee' => $anneeActive]
);

$monthlyChartRows = [];
$monthlyRowsByKey = [];
foreach ($monthlyReceipts as $row) {
    $monthlyRowsByKey[$row['mois']] = (float) $row['equivalent_usd'];
}
$monthCursor = new DateTimeImmutable('first day of this month');
for ($offset = 5; $offset >= 0; $offset--) {
    $month = $monthCursor->modify('-' . $offset . ' months');
    $monthKey = $month->format('Y-m');
    $monthlyChartRows[] = [
        'label' => $month->format('m/Y'),
        'amount' => round($monthlyRowsByKey[$monthKey] ?? 0, 2),
    ];
}
$studentStateCounts = ['soldee' => 0, 'partielle' => 0, 'impayee' => 0];
foreach ($studentStates as $state) {
    if (isset($studentStateCounts[$state['etat']])) {
        $studentStateCounts[$state['etat']] = (int) $state['nombre'];
    }
}

$derniersVersements = $db->fetchAll(
    "SELECT v.id, v.numero_recu, v.montant_recu, v.devise_recue, v.mode_paiement,
            DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
            e.matricule, e.nom, e.prenom,
            COALESCE(affectations.frais, '') AS frais,
            COALESCE(affectations.affecte_usd, 0) AS affecte_usd,
            COALESCE(affectations.affecte_cdf, 0) AS affecte_cdf
     FROM finance_versements v
     INNER JOIN eleves e ON e.id = v.eleve_id
     LEFT JOIN finance_annulations an ON an.versement_id = v.id
     INNER JOIN (
         SELECT a.versement_id,
                GROUP_CONCAT(DISTINCT CONCAT(cr.libelle_snapshot, ' · ', cr.periode_code)
                    ORDER BY cr.libelle_snapshot, cr.periode_code SEPARATOR ', ') AS frais,
                SUM(CASE WHEN cr.devise = 'USD' THEN a.montant_affecte ELSE 0 END) AS affecte_usd,
                SUM(CASE WHEN cr.devise = 'CDF' THEN a.montant_affecte ELSE 0 END) AS affecte_cdf
         FROM finance_affectations a
         INNER JOIN finance_creances cr ON cr.id = a.creance_id
         WHERE cr.annee_scolaire_id = :allocation_year
         GROUP BY a.versement_id
     ) affectations ON affectations.versement_id = v.id
     WHERE an.id IS NULL
     ORDER BY v.date_versement DESC, v.id DESC
     LIMIT 8",
    ['allocation_year' => $anneeActive]
);

$pageTitle = 'Tableau de bord financier';
$pageIcon = 'fa-chart-line';
$pageDescription = 'Soldes et encaissements de l’année scolaire active';
$extraCSS = ['modules/paiements.css', 'modules/dashboard_financier.css'];
$extraJS = [];
$inlineJS = "
document.addEventListener('DOMContentLoaded', function () {
    var receiptsCanvas = document.getElementById('financeMonthlyChart');
    if (receiptsCanvas && typeof Chart !== 'undefined') {
        new Chart(receiptsCanvas, {
            type: 'bar',
            data: {
                labels: " . json_encode(array_column($monthlyChartRows, 'label'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ",
                datasets: [{
                    label: 'Encaissements (USD indicatif)',
                    data: " . json_encode(array_column($monthlyChartRows, 'amount')) . ",
                    backgroundColor: '#3157a4',
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'USD indicatif' } },
                    x: { title: { display: true, text: 'Mois' } }
                }
            }
        });
    }

    var statesCanvas = document.getElementById('financeStudentStatesChart');
    if (statesCanvas && typeof Chart !== 'undefined') {
        new Chart(statesCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Soldés', 'Partiels', 'Sans versement'],
                datasets: [{
                    data: " . json_encode(array_values($studentStateCounts)) . ",
                    backgroundColor: ['#17835d', '#d38a12', '#b54242'],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 18 } } }
            }
        });
    }
});";

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<main class="finance-dashboard">
<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <span class="current">Tableau de bord financier</span>
</nav>

<header class="finance-dashboard-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-chart-line"></i> Tableau de bord financier</h4>
        <p><?= e($anneeLibelle) ?> · situation des créances, encaissements et retards.</p>
    </div>

    <div class="resultats-header-right">
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/enregistrer" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Enregistrer un versement</a>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/impayes" class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-list-check"></i> Suivi des impayés</a>
        <a href="<?= e(APP_BASE_PATH) ?>/comptable/rapport-financier" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Rapport</a>
    </div>
</header>

<div class="retards-stats mb-4">
    <article class="retard-stat-card">
        <div class="retard-stat-icon red"><i class="fa-solid fa-dollar-sign"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">$ <?= number_format((float) $soldes['reste_usd'], 2, ',', ' ') ?></div>
            <div class="retard-stat-label">Reste à recouvrer · USD</div>
            <span class="stat-change neutral">$ <?= number_format((float) $soldes['paye_usd'], 2, ',', ' ') ?> affectés sur $ <?= number_format((float) $soldes['du_usd'], 2, ',', ' ') ?></span>
        </div>
    </article>
    <article class="retard-stat-card">
        <div class="retard-stat-icon orange"><i class="fa-solid fa-coins"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">FC <?= number_format((float) $soldes['reste_cdf'], 0, ',', ' ') ?></div>
            <div class="retard-stat-label">Reste à recouvrer · CDF</div>
            <span class="stat-change neutral">FC <?= number_format((float) $soldes['paye_cdf'], 0, ',', ' ') ?> affectés sur FC <?= number_format((float) $soldes['du_cdf'], 0, ',', ' ') ?></span>
        </div>
    </article>
    <article class="retard-stat-card">
        <div class="retard-stat-icon blue"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= (int) $receiptsMonth['nombre'] ?></div>
            <div class="retard-stat-label">Reçus actifs · ce mois</div>
            <span class="stat-change neutral">$ <?= number_format((float) $receiptsMonth['usd'], 2, ',', ' ') ?> · FC <?= number_format((float) $receiptsMonth['cdf'], 0, ',', ' ') ?></span>
        </div>
    </article>
    <article class="retard-stat-card">
        <div class="retard-stat-icon purple"><i class="fa-solid fa-bell"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= $nbEcheancesRetard ?></div>
            <div class="retard-stat-label">Créances en retard</div>
            <span class="stat-change neutral"><?= $suivisAVenir ?> relance(s) planifiée(s)</span>
        </div>
    </article>
</div>

<section class="finance-dashboard-charts" aria-label="Visualisations financières">
    <article class="card finance-dashboard-chart-card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon blue"><i class="fa-solid fa-chart-column"></i></div>
                <div>
                    <div class="card-header-title">Évolution des encaissements</div>
                    <div class="card-header-subtitle">Six derniers mois · équivalent USD indicatif</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="finance-dashboard-chart" role="img" aria-label="Histogramme mensuel des encaissements convertis en équivalent USD indicatif">
                <canvas id="financeMonthlyChart"></canvas>
            </div>
            <p class="finance-dashboard-chart-note">Les versements en CDF sont convertis avec le taux conservé sur chaque reçu. Les encaissements USD et CDF d’origine restent visibles ci-dessus.</p>
        </div>
    </article>

    <article class="card finance-dashboard-chart-card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon green"><i class="fa-solid fa-chart-pie"></i></div>
                <div>
                    <div class="card-header-title">Situation des élèves</div>
                    <div class="card-header-subtitle">Selon les créances de l’année scolaire</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (array_sum($studentStateCounts) > 0): ?>
                <div class="finance-dashboard-chart finance-dashboard-chart-doughnut" role="img" aria-label="Répartition des élèves entre créances soldées, partielles et sans versement">
                    <canvas id="financeStudentStatesChart"></canvas>
                </div>
                <div class="finance-dashboard-state-summary">
                    <span><strong><?= $studentStateCounts['soldee'] ?></strong> soldé(s)</span>
                    <span><strong><?= $studentStateCounts['partielle'] ?></strong> paiement(s) partiel(s)</span>
                    <span><strong><?= $studentStateCounts['impayee'] ?></strong> sans versement</span>
                </div>
            <?php else: ?>
                <div class="empty-state"><p>Aucune créance n’est encore enregistrée pour cette année scolaire.</p></div>
            <?php endif; ?>
        </div>
    </article>
</section>

<section class="card finance-dashboard-table-card">
    <div class="card-header">
        <div class="card-header-left">
            <div class="card-header-icon orange"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="card-header-title">Classes à suivre en priorité</div>
                <div class="card-header-subtitle">Nombre d’élèves débiteurs et soldes ouverts, par devise</div>
            </div>
        </div>
        <a href="<?= e(APP_BASE_PATH) ?>/comptable/rapport-financier" class="btn btn-outline-primary btn-sm">Analyse détaillée</a>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive finance-dashboard-table-wrap" role="region" aria-label="Soldes ouverts par classe" tabindex="0">
            <table class="table finance-dashboard-table">
                <thead><tr><th>Classe</th><th>Élèves débiteurs</th><th>Solde USD</th><th>Solde CDF</th></tr></thead>
                <tbody>
                <?php if (!$classBalances): ?>
                    <tr><td colspan="4"><div class="empty-state"><p>Aucun solde ouvert par classe pour cette année scolaire.</p></div></td></tr>
                <?php else: foreach ($classBalances as $classBalance): ?>
                    <tr>
                        <td><strong><?= e($classBalance['nom']) ?></strong></td>
                        <td><?= (int) $classBalance['eleves_debiteurs'] ?></td>
                        <td><?= '$ ' . number_format((float) $classBalance['solde_usd'], 2, ',', ' ') ?></td>
                        <td><?= 'FC ' . number_format((float) $classBalance['solde_cdf'], 0, ',', ' ') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card finance-dashboard-table-card">
    <div class="card-header">
        <div class="card-header-left">
            <div class="card-header-icon green"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="card-header-title">Derniers versements</div>
                <div class="card-header-subtitle"><?= count($derniersVersements) ?> opération(s) récente(s)</div>
            </div>
        </div>

        <a href="<?= e(APP_BASE_PATH) ?>/paiements" class="btn btn-outline-primary btn-sm">Voir tous les versements</a>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive finance-dashboard-table-wrap" role="region" aria-label="Derniers versements reçus" tabindex="0">
            <table class="table finance-dashboard-table finance-dashboard-receipts">
                <thead><tr><th>Date</th><th>N° reçu</th><th>Élève</th><th>Frais / période</th><th>Reçu</th><th>Affecté USD</th><th>Affecté CDF</th><th>Mode</th><th>Document</th></tr></thead>
                <tbody>
                <?php if (!$derniersVersements): ?>
                    <tr><td colspan="9"><div class="empty-state"><p>Aucun versement actif pour cette année scolaire.</p></div></td></tr>
                <?php else: foreach ($derniersVersements as $versement): ?>
                    <tr>
                        <td><?= e($versement['date_format']) ?></td>
                        <td><?= e($versement['numero_recu']) ?></td>
                        <td><?= e($versement['prenom'] . ' ' . $versement['nom']) ?><br><small><?= e($versement['matricule']) ?></small></td>
                        <td class="finance-dashboard-fees"><?= e($versement['frais']) ?></td>
                        <td><?= e($versement['devise_recue']) ?> <?= number_format((float) $versement['montant_recu'], 2, ',', ' ') ?></td>
                        <td><?= '$ ' . number_format((float) $versement['affecte_usd'], 2, ',', ' ') ?></td>
                        <td><?= 'FC ' . number_format((float) $versement['affecte_cdf'], 0, ',', ' ') ?></td>
                        <td><?= e($versement['mode_paiement']) ?></td>
                        <td><a href="<?= e(APP_BASE_PATH) ?>/paiements/recu?versement_id=<?= (int) $versement['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fa-solid fa-receipt"></i> Reçu</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</main>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
