<?php
defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
require_once __DIR__ . '/../../../../src/services/FinanceReportService.php';
requireLogin();
requirePermission('rapports.consulter');

global $db;

$input = [];
foreach ($_GET as $key => $value) {
    if (is_scalar($value)) {
        $input[$key] = (string) $value;
    }
}

$reportService = new FinanceReportService($db);
$report = $reportService->build($input);
if (($input['export'] ?? '') === 'creances_csv' && !$report['empty']) {
    $reportService->streamClaimsCsv($report);
    exit;
}

$pageTitle = 'Rapport financier';
$pageIcon = 'fa-chart-pie';
$pageDescription = 'Analyse des créances, encaissements, retards et relances';
$extraCSS = ['modules/rapport_financier.css'];
$extraJS = [];

$filters = $report['filters'];
$year = $report['year'];
$formatAmount = static function (float $amount, string $currency): string {
    return ($currency === 'USD' ? '$ ' : 'FC ') . number_format($amount, $currency === 'USD' ? 2 : 0, ',', ' ');
};
$queryForPage = static function (int $page) use ($filters): string {
    $query = $filters;
    $query['page'] = $page;
    return http_build_query($query);
};
$states = ['soldee' => 0, 'partielle' => 0, 'impayee' => 0];
if (!$report['empty']) {
    foreach ($report['student_states'] as $state) {
        if (array_key_exists($state['etat'], $states)) {
            $states[$state['etat']] = (int) $state['nombre_eleves'];
        }
    }
}

$monthlyRows = [];
if (!$report['empty']) {
    foreach ($report['cash_monthly'] as $month) {
        $key = $month['mois'];
        if (!isset($monthlyRows[$key])) {
            $monthlyRows[$key] = ['USD' => 0.0, 'CDF' => 0.0, 'equivalent_usd' => 0.0, 'nombre' => 0];
        }
        $currency = $month['devise_recue'];
        $monthlyRows[$key][$currency] = (float) $month['montant_actif'];
        $monthlyRows[$key]['equivalent_usd'] += (float) $month['equivalent_usd'];
        $monthlyRows[$key]['nombre'] += (int) $month['nombre_actif'];
    }
}
$maxMonthlyEquivalent = 0.0;
foreach ($monthlyRows as $month) {
    $maxMonthlyEquivalent = max($maxMonthlyEquivalent, $month['equivalent_usd']);
}
$monthlyChartLabels = [];
$monthlyChartValues = [];
foreach ($monthlyRows as $month => $row) {
    $monthlyChartLabels[] = $month;
    $monthlyChartValues[] = round($row['equivalent_usd'], 2);
}
$studentStateTotal = array_sum($states);
$inlineJS = "
document.addEventListener('DOMContentLoaded', function () {
    var monthlyCanvas = document.getElementById('reportMonthlyChart');
    if (monthlyCanvas && typeof Chart !== 'undefined') {
        new Chart(monthlyCanvas, {
            type: 'bar',
            data: {
                labels: " . json_encode($monthlyChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ",
                datasets: [{
                    label: 'Encaissements (USD indicatif)',
                    data: " . json_encode($monthlyChartValues) . ",
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

    var statesCanvas = document.getElementById('reportStudentStatesChart');
    if (statesCanvas && typeof Chart !== 'undefined') {
        new Chart(statesCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Soldés', 'Partiels', 'Sans versement'],
                datasets: [{
                    data: " . json_encode(array_values($states)) . ",
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

<div class="finance-report">
    <nav class="breadcrumb no-print">
        <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
        <span class="separator">/</span>
        <a href="<?= e(APP_BASE_PATH) ?>/comptable/paiements">Finances</a>
        <span class="separator">/</span>
        <span class="current">Rapport financier</span>
    </nav>

    <header class="rapport-header finance-report-header">
        <div class="rapport-header-left">
            <div class="rapport-header-icon"><i class="fa-solid fa-chart-pie"></i></div>
            <div class="rapport-header-info">
                <h3>Rapport financier</h3>
                <p>
                    <?php if ($year): ?>
                        <?= e($year['libelle']) ?> · situation au <?= e(date('d/m/Y', strtotime($filters['date_situation']))) ?>
                    <?php else: ?>
                        Situation des créances, des encaissements et des retards
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php if (!$report['empty']): ?>
            <div class="rapport-header-right no-print">
                <a class="btn" href="?<?= e(http_build_query(array_merge($filters, ['export' => 'creances_csv']))) ?>">
                    <i class="fa-solid fa-file-csv"></i> Exporter les créances
                </a>
                <button type="button" class="btn" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Imprimer / PDF
                </button>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($report['empty']): ?>
        <div class="alert alert-info">Aucune année scolaire n’est configurée. Le rapport financier sera disponible après la création d’une année scolaire.</div>
    <?php else: ?>

        <?php foreach ($report['errors'] as $error): ?>
            <div class="alert alert-warning no-print" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>

        <section class="card finance-report-filters no-print" aria-label="Filtres du rapport">
            <div class="card-header">
                <div class="card-header-left">
                    <div class="card-header-icon blue"><i class="fa-solid fa-sliders"></i></div>
                    <div>
                        <div class="card-header-title">Paramètres du rapport</div>
                        <div class="card-header-subtitle">La situation des créances et les encaissements de caisse sont calculés séparément.</div>
                    </div>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_BASE_PATH) ?>/comptable/rapport-financier">Réinitialiser</a>
            </div>
            <div class="card-body">
                <form method="get" action="<?= e(APP_BASE_PATH) ?>/comptable/rapport-financier" class="finance-report-filter-grid">
                    <div class="form-group">
                        <label for="anneeRapport">Année scolaire</label>
                        <select id="anneeRapport" name="annee_id" class="form-control">
                            <?php foreach ($report['years'] as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $filters['annee_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                    <?= e($option['libelle']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="classeRapport">Classe</label>
                        <select id="classeRapport" name="classe_id" class="form-control">
                            <option value="0">Toutes les classes</option>
                            <?php foreach ($report['classes'] as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $filters['classe_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                    <?= e($option['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="categorieRapport">Catégorie de frais</label>
                        <select id="categorieRapport" name="categorie" class="form-control">
                            <option value="">Toutes les catégories</option>
                            <?php foreach ($report['categories'] as $category): ?>
                                <option value="<?= e($category) ?>" <?= $filters['categorie'] === $category ? 'selected' : '' ?>>
                                    <?= e($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="fraisRapport">Frais précis</label>
                        <select id="fraisRapport" name="frais_id" class="form-control">
                            <option value="0">Tous les frais</option>
                            <?php foreach ($report['fees'] as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $filters['frais_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                                    <?= e($option['libelle']) ?> · <?= e($option['categorie']) ?>
                                    <?= $option['classe_nom'] ? ' · ' . e($option['classe_nom']) : '' ?>
                                    <?= (int) $option['est_actif'] === 1 ? '' : ' · inactif' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="etatCreance">État des créances</label>
                        <select id="etatCreance" name="etat_creance" class="form-control">
                            <option value="">Tous les états</option>
                            <option value="ouverte" <?= $filters['etat_creance'] === 'ouverte' ? 'selected' : '' ?>>Avec un solde</option>
                            <option value="retard" <?= $filters['etat_creance'] === 'retard' ? 'selected' : '' ?>>En retard</option>
                            <option value="partielle" <?= $filters['etat_creance'] === 'partielle' ? 'selected' : '' ?>>Partiellement payée</option>
                            <option value="impayee" <?= $filters['etat_creance'] === 'impayee' ? 'selected' : '' ?>>Non payée</option>
                            <option value="soldee" <?= $filters['etat_creance'] === 'soldee' ? 'selected' : '' ?>>Soldée</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="deviseCreance">Devise de la créance</label>
                        <select id="deviseCreance" name="devise_creance" class="form-control">
                            <option value="">Toutes les devises</option>
                            <option value="USD" <?= $filters['devise_creance'] === 'USD' ? 'selected' : '' ?>>USD</option>
                            <option value="CDF" <?= $filters['devise_creance'] === 'CDF' ? 'selected' : '' ?>>CDF</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="dateDebutRapport">Encaissements du</label>
                        <input id="dateDebutRapport" type="date" name="date_debut" class="form-control" value="<?= e($filters['date_debut']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="dateFinRapport">Au</label>
                        <input id="dateFinRapport" type="date" name="date_fin" class="form-control" value="<?= e($filters['date_fin']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="dateSituationRapport">Situation des soldes au</label>
                        <input id="dateSituationRapport" type="date" name="date_situation" class="form-control" value="<?= e($filters['date_situation']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="modeRapport">Mode d’encaissement</label>
                        <select id="modeRapport" name="mode" class="form-control">
                            <option value="">Tous les modes</option>
                            <?php foreach (['Espèces', 'Chèque', 'Virement bancaire', 'Mobile Money', 'Autre'] as $paymentMode): ?>
                                <option value="<?= e($paymentMode) ?>" <?= $filters['mode'] === $paymentMode ? 'selected' : '' ?>><?= e($paymentMode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="deviseRecueRapport">Devise reçue</label>
                        <select id="deviseRecueRapport" name="devise_recue" class="form-control">
                            <option value="">USD et CDF</option>
                            <option value="USD" <?= $filters['devise_recue'] === 'USD' ? 'selected' : '' ?>>USD reçu</option>
                            <option value="CDF" <?= $filters['devise_recue'] === 'CDF' ? 'selected' : '' ?>>CDF reçu</option>
                        </select>
                    </div>
                    <div class="form-group finance-report-search">
                        <label for="rechercheRapport">Élève ou matricule</label>
                        <input id="rechercheRapport" type="search" name="recherche" class="form-control"
                               value="<?= e($filters['recherche']) ?>" maxlength="100" placeholder="Nom, prénom, matricule ou reçu">
                    </div>
                    <div class="finance-report-filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Appliquer les filtres</button>
                    </div>
                </form>
                <p class="finance-report-filter-note">
                    <?= e($report['cash_filter_note']) ?>
                    Les créances sont arrêtées à la date de situation; les encaissements correspondent aux dates choisies.
                </p>
            </div>
        </section>

        <?php
        $summary = $report['summary'];
        $cashSummary = $report['cash_summary'];
        $usd = $summary['USD'];
        $cdf = $summary['CDF'];
        $overdueTotalUsd = (float) ($usd['solde_en_retard'] ?? 0);
        $overdueTotalCdf = (float) ($cdf['solde_en_retard'] ?? 0);
        ?>

        <section class="finance-report-section" aria-labelledby="claimsHeading">
            <div class="finance-report-section-heading">
                <div>
                    <h2 id="claimsHeading">Situation des créances</h2>
                    <p>Soldes au <?= e(date('d/m/Y', strtotime($filters['date_situation']))) ?> · par devise d’origine</p>
                </div>
            </div>
            <div class="report-currency-grid">
                <?php foreach (['USD' => $usd, 'CDF' => $cdf] as $currency => $row): ?>
                    <?php
                    $due = (float) ($row['montant_du'] ?? 0);
                    $paid = (float) ($row['montant_affecte'] ?? 0);
                    $balance = (float) ($row['solde'] ?? 0);
                    $recovery = $due > 0 ? min(100, max(0, $paid / $due * 100)) : 0;
                    ?>
                    <article class="report-currency-card">
                        <div class="report-currency-card-header">
                            <span><?= $currency === 'USD' ? 'Dollars américains' : 'Francs congolais' ?></span>
                            <strong><?= e($currency) ?></strong>
                        </div>
                        <div class="report-currency-main">
                            <span>Reste à recouvrer</span>
                            <strong><?= e($formatAmount($balance, $currency)) ?></strong>
                        </div>
                        <div class="report-currency-stats">
                            <div><span>Créances dues</span><strong><?= e($formatAmount($due, $currency)) ?></strong></div>
                            <div><span>Affecté</span><strong><?= e($formatAmount($paid, $currency)) ?></strong></div>
                            <div><span>En retard</span><strong class="text-danger"><?= e($formatAmount((float) ($row['solde_en_retard'] ?? 0), $currency)) ?></strong></div>
                            <div><span>Recouvrement</span><strong><?= number_format($recovery, 1, ',', ' ') ?> %</strong></div>
                        </div>
                        <div class="report-progress" role="img" aria-label="Taux de recouvrement <?= number_format($recovery, 1, ',', ' ') ?> %">
                            <span style="width: <?= number_format($recovery, 2, '.', '') ?>%"></span>
                        </div>
                        <small><?= (int) ($row['nombre_eleves'] ?? 0) ?> élève(s) · <?= (int) ($row['nombre_creances'] ?? 0) ?> créance(s)</small>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
            $overallocated = [];
            foreach (['USD' => $usd, 'CDF' => $cdf] as $currency => $row) {
                if ((float) ($row['montant_sureffectue'] ?? 0) > 0) {
                    $overallocated[$currency] = (float) $row['montant_sureffectue'];
                }
            }
            ?>
            <?php if ($overallocated): ?>
                <div class="alert alert-warning">
                    Contrôle requis : des affectations dépassent le montant de certaines créances
                    (<?= implode(' · ', array_map(static function (string $currency, float $amount) use ($formatAmount): string {
                        return $formatAmount($amount, $currency);
                    }, array_keys($overallocated), array_values($overallocated))) ?>).
                    Les soldes sont plafonnés à zéro pour ne pas afficher de dette négative.
                </div>
            <?php endif; ?>

            <div class="report-kpi-grid">
                <article class="report-kpi">
                    <span class="report-kpi-label">Élèves soldés</span>
                    <strong><?= $states['soldee'] ?></strong>
                    <small>Sur les créances correspondant aux filtres</small>
                </article>
                <article class="report-kpi">
                    <span class="report-kpi-label">Paiements partiels</span>
                    <strong><?= $states['partielle'] ?></strong>
                    <small>Au moins une créance encore ouverte</small>
                </article>
                <article class="report-kpi">
                    <span class="report-kpi-label">Élèves sans versement</span>
                    <strong><?= $states['impayee'] ?></strong>
                    <small>Créances ouvertes non réglées</small>
                </article>
                <article class="report-kpi <?= ($overdueTotalUsd + $overdueTotalCdf) > 0 ? 'is-warning' : '' ?>">
                    <span class="report-kpi-label">Créances en retard</span>
                    <strong><?= e($formatAmount($overdueTotalUsd, 'USD')) ?> <span>·</span> <?= e($formatAmount($overdueTotalCdf, 'CDF')) ?></strong>
                    <small>Échéance antérieure à la date de situation</small>
                </article>
            </div>
        </section>

        <section class="finance-report-section">
            <div class="finance-report-section-heading">
                <div>
                    <h2>Encaissements réellement reçus</h2>
                    <p>Du <?= e(date('d/m/Y', strtotime($filters['date_debut']))) ?> au <?= e(date('d/m/Y', strtotime($filters['date_fin']))) ?> · reçus actifs séparés des corrections</p>
                </div>
            </div>
            <div class="report-cash-grid">
                <?php foreach (['USD' => $cashSummary['USD'], 'CDF' => $cashSummary['CDF']] as $currency => $row): ?>
                    <article class="report-cash-card">
                        <div class="report-cash-icon"><i class="fa-solid fa-cash-register"></i></div>
                        <div>
                            <span>Reçu actif en <?= e($currency) ?></span>
                            <strong><?= e($formatAmount((float) ($row['montant_actif'] ?? 0), $currency)) ?></strong>
                            <small>
                                <?= (int) ($row['versements_actifs'] ?? 0) ?> versement(s) actif(s) ·
                                <?= (int) ($row['nombre_annule'] ?? 0) ?> annulation(s) (<?= e($formatAmount((float) ($row['montant_annule'] ?? 0), $currency)) ?>) ·
                                <?= (int) ($row['nombre_rembourse'] ?? 0) ?> remboursement(s) (<?= e($formatAmount((float) ($row['montant_rembourse'] ?? 0), $currency)) ?>)
                            </small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="finance-report-conversion-note">
                Équivalent indicatif reçu : <strong>$ <?= number_format((float) ($cashSummary['USD']['equivalent_usd'] ?? 0) + (float) ($cashSummary['CDF']['equivalent_usd'] ?? 0), 2, ',', ' ') ?></strong>.
                Il utilise le taux conservé sur chaque reçu; les montants USD et CDF d’origine restent affichés séparément.
            </p>
            <?php
            $reconciliation = $report['cash_reconciliation'];
            $cashDifferenceFound = false;
            foreach (['USD', 'CDF'] as $currency) {
                if ((float) ($reconciliation[$currency]['non_affecte'] ?? 0) > 0.01
                    || (float) ($reconciliation[$currency]['affecte_en_exces'] ?? 0) > 0.01) {
                    $cashDifferenceFound = true;
                }
            }
            ?>
            <?php if ($cashDifferenceFound): ?>
                <div class="alert alert-warning">
                    <strong>Écart de rapprochement à examiner.</strong>
                    <?php foreach (['USD', 'CDF'] as $currency): ?>
                        <?php
                        $notAssigned = (float) ($reconciliation[$currency]['non_affecte'] ?? 0);
                        $overAssigned = (float) ($reconciliation[$currency]['affecte_en_exces'] ?? 0);
                        ?>
                        <?php if ($notAssigned > 0.01 || $overAssigned > 0.01): ?>
                            <span><?= e($currency) ?> : <?= e($formatAmount($notAssigned, $currency)) ?> non affecté(s), <?= e($formatAmount($overAssigned, $currency)) ?> affecté(s) au-delà des reçus. </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    Les écarts sont calculés dans la devise réellement reçue avec le taux conservé sur chaque reçu.
                </div>
            <?php else: ?>
                <p class="report-inline-note">Rapprochement des reçus actifs et de leurs affectations : aucun écart supérieur à 0,01 n’a été détecté dans cette sélection.</p>
            <?php endif; ?>

            <div class="report-two-columns report-chart-grid">
                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div>
                            <h3>Encaissements par mois</h3>
                            <p>Équivalent indicatif en USD calculé avec le taux de chaque reçu</p>
                        </div>
                    </div>
                    <?php if (!$monthlyRows): ?>
                        <div class="report-empty">Aucun versement actif sur la période sélectionnée.</div>
                    <?php else: ?>
                        <div class="report-chart-wrap" role="img" aria-label="Histogramme mensuel des encaissements, en équivalent USD indicatif">
                            <canvas id="reportMonthlyChart"></canvas>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div>
                            <h3>Situation des élèves</h3>
                            <p>État des créances selon les filtres sélectionnés</p>
                        </div>
                    </div>
                    <?php if ($studentStateTotal === 0): ?>
                        <div class="report-empty">Aucun élève ne correspond aux filtres.</div>
                    <?php else: ?>
                        <div class="report-chart-wrap report-chart-wrap-doughnut" role="img" aria-label="Répartition des élèves entre créances soldées, partielles et sans versement">
                            <canvas id="reportStudentStatesChart"></canvas>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="report-two-columns">
                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div><h3>Évolution mensuelle</h3><p>Montant réellement encaissé, converti à titre indicatif pour comparer les mois</p></div>
                    </div>
                    <?php if (!$monthlyRows): ?>
                        <div class="report-empty">Aucun versement sur la période sélectionnée.</div>
                    <?php else: ?>
                        <div class="table-responsive" role="region" aria-label="Tableau des encaissements mensuels, défilement horizontal possible" tabindex="0">
                            <table class="table report-table">
                                <thead><tr><th>Mois</th><th>Tendance équiv. USD</th><th>USD reçu</th><th>CDF reçu</th><th>Versements</th></tr></thead>
                                <tbody>
                                <?php foreach ($monthlyRows as $month => $row): ?>
                                    <?php $width = $maxMonthlyEquivalent > 0 ? min(100, $row['equivalent_usd'] / $maxMonthlyEquivalent * 100) : 0; ?>
                                    <tr>
                                        <td><?= e($month) ?></td>
                                        <td>
                                            <div class="report-bar-track"><span style="width: <?= number_format($width, 2, '.', '') ?>%"></span></div>
                                            <small>$ <?= number_format($row['equivalent_usd'], 2, ',', ' ') ?> indicatif</small>
                                        </td>
                                        <td><?= e($formatAmount($row['USD'], 'USD')) ?></td>
                                        <td><?= e($formatAmount($row['CDF'], 'CDF')) ?></td>
                                        <td><?= (int) $row['nombre'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div><h3>Encaissements par mode</h3><p>Versements actifs, ventilés par devise reçue</p></div>
                    </div>
                    <?php if (!$report['cash_by_mode']): ?>
                        <div class="report-empty">Aucun versement sur la période sélectionnée.</div>
                    <?php else: ?>
                        <div class="table-responsive" role="region" aria-label="Tableau des encaissements par mode, défilement horizontal possible" tabindex="0">
                            <table class="table report-table">
                                <thead><tr><th>Mode</th><th>Devise</th><th>Reçu</th><th>Versements</th><th>Corrigés</th></tr></thead>
                                <tbody>
                                <?php foreach ($report['cash_by_mode'] as $row): ?>
                                    <tr>
                                        <td><?= e($row['mode_paiement']) ?></td>
                                        <td><?= e($row['devise_recue']) ?></td>
                                        <td><strong><?= e($formatAmount((float) $row['montant_actif'], $row['devise_recue'])) ?></strong></td>
                                        <td><?= (int) $row['nombre_actif'] ?></td>
                                        <td><?= (int) $row['nombre_corrige'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="finance-report-section">
            <div class="finance-report-section-heading">
                <div>
                    <h2>Analyse par frais et par classe</h2>
                    <p>Les montants dûs et affectés sont exprimés dans la devise de chaque créance.</p>
                </div>
            </div>
            <article class="report-panel mb-4">
                <div class="report-panel-heading">
                    <div><h3>Performance par frais</h3><p>Solde et échéances à la date de situation</p></div>
                </div>
                <div class="table-responsive" role="region" aria-label="Tableau de performance par frais, défilement horizontal possible" tabindex="0">
                    <table class="table report-table">
                        <thead><tr><th>Catégorie / frais</th><th>Devise</th><th>Élèves</th><th>Dû</th><th>Affecté</th><th>Solde</th><th>En retard</th><th>Recouvrement</th></tr></thead>
                        <tbody>
                        <?php if (!$report['fees_summary']): ?>
                            <tr><td colspan="8"><div class="report-empty">Aucune créance ne correspond aux filtres.</div></td></tr>
                        <?php else: foreach ($report['fees_summary'] as $row): ?>
                            <?php $ratio = (float) $row['montant_du'] > 0 ? min(100, (float) $row['montant_affecte'] / (float) $row['montant_du'] * 100) : 0; ?>
                            <tr>
                                <td><strong><?= e($row['libelle_snapshot']) ?></strong><small><?= e($row['categorie']) ?> · <?= (int) $row['nombre_creances'] ?> créance(s)</small></td>
                                <td><?= e($row['devise']) ?></td>
                                <td><?= (int) $row['nombre_eleves'] ?></td>
                                <td><?= e($formatAmount((float) $row['montant_du'], $row['devise'])) ?></td>
                                <td><?= e($formatAmount((float) $row['montant_affecte'], $row['devise'])) ?></td>
                                <td><strong><?= e($formatAmount((float) $row['solde'], $row['devise'])) ?></strong></td>
                                <td class="<?= (float) $row['solde_en_retard'] > 0 ? 'text-danger' : '' ?>"><?= e($formatAmount((float) $row['solde_en_retard'], $row['devise'])) ?></td>
                                <td><?= number_format($ratio, 1, ',', ' ') ?> %</td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="report-panel">
                <div class="report-panel-heading">
                    <div><h3>Situation par classe</h3><p>Chaque devise est présentée séparément pour éviter les additions sans conversion</p></div>
                </div>
                <div class="table-responsive" role="region" aria-label="Tableau de situation par classe, défilement horizontal possible" tabindex="0">
                    <table class="table report-table">
                        <thead><tr><th>Classe</th><th>Devise</th><th>Créances</th><th>Élèves concernés</th><th>Débiteurs</th><th>Dû</th><th>Affecté</th><th>Solde</th><th>Retard</th></tr></thead>
                        <tbody>
                        <?php if (!$report['classes_summary']): ?>
                            <tr><td colspan="9"><div class="report-empty">Aucune créance ne correspond aux filtres.</div></td></tr>
                        <?php else: foreach ($report['classes_summary'] as $row): ?>
                            <tr>
                                <td><strong><?= e($row['classe_nom']) ?></strong><small><?= e($row['niveau']) ?></small></td>
                                <td><?= e($row['devise']) ?></td>
                                <td><?= (int) $row['nombre_creances'] ?></td>
                                <td><?= (int) $row['nombre_eleves'] ?></td>
                                <td><?= (int) $row['eleves_debiteurs'] ?></td>
                                <td><?= e($formatAmount((float) $row['montant_du'], $row['devise'])) ?></td>
                                <td><?= e($formatAmount((float) $row['montant_affecte'], $row['devise'])) ?></td>
                                <td><strong><?= e($formatAmount((float) $row['solde'], $row['devise'])) ?></strong></td>
                                <td class="<?= (float) $row['solde_en_retard'] > 0 ? 'text-danger' : '' ?>"><?= e($formatAmount((float) $row['solde_en_retard'], $row['devise'])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="finance-report-section">
            <div class="report-two-columns">
                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div><h3>Ancienneté des soldes</h3><p>Retard calculé depuis l’échéance jusqu’à la date de situation</p></div>
                    </div>
                    <div class="table-responsive" role="region" aria-label="Tableau d’ancienneté des soldes, défilement horizontal possible" tabindex="0">
                        <table class="table report-table">
                            <thead><tr><th>Devise</th><th>À échoir</th><th>1–30 j</th><th>31–60 j</th><th>61–90 j</th><th>+90 j</th><th>Sans échéance</th></tr></thead>
                            <tbody>
                            <?php foreach (['USD' => $report['aging']['USD'], 'CDF' => $report['aging']['CDF']] as $currency => $row): ?>
                                <tr>
                                    <td><strong><?= e($currency) ?></strong></td>
                                    <td><?= e($formatAmount((float) ($row['a_echeance'] ?? 0), $currency)) ?></td>
                                    <td><?= e($formatAmount((float) ($row['jours_1_30'] ?? 0), $currency)) ?></td>
                                    <td><?= e($formatAmount((float) ($row['jours_31_60'] ?? 0), $currency)) ?></td>
                                    <td><?= e($formatAmount((float) ($row['jours_61_90'] ?? 0), $currency)) ?></td>
                                    <td class="text-danger"><strong><?= e($formatAmount((float) ($row['plus_90_jours'] ?? 0), $currency)) ?></strong></td>
                                    <td><?= e($formatAmount((float) ($row['sans_echeance_montant'] ?? 0), $currency)) ?><small><?= (int) ($row['nombre_sans_echeance'] ?? 0) ?> créance(s)</small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div><h3>Créances affectées pendant la période</h3><p>Affectations aux frais, en devise de la créance · versements actifs</p></div>
                    </div>
                    <div class="table-responsive" role="region" aria-label="Tableau des affectations aux créances, défilement horizontal possible" tabindex="0">
                        <table class="table report-table">
                            <thead><tr><th>Frais</th><th>Devise</th><th>Montant affecté</th><th>Versements</th></tr></thead>
                            <tbody>
                            <?php if (!$report['allocations']): ?>
                                <tr><td colspan="4"><div class="report-empty">Aucune affectation pendant cette période.</div></td></tr>
                            <?php else: foreach ($report['allocations'] as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['libelle_snapshot']) ?></strong><small><?= e($row['categorie']) ?></small></td>
                                    <td><?= e($row['devise']) ?></td>
                                    <td><?= e($formatAmount((float) $row['montant_affecte'], $row['devise'])) ?></td>
                                    <td><?= (int) $row['nombre_versements'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="finance-report-section">
            <div class="finance-report-section-heading">
                <div>
                    <h2>Actions et contrôles</h2>
                    <p>À vérifier avant de conclure que les montants attendus sont entièrement comptabilisés.</p>
                </div>
            </div>
            <div class="report-two-columns">
                <article class="report-panel report-coverage-panel">
                    <div class="report-panel-heading">
                        <div><h3>Couverture des créances attendues</h3><p>Frais actifs × inscriptions actives/ redoublantes × périodes prévues</p></div>
                    </div>
                    <?php $coverageRate = $report['coverage']['attendues'] > 0 ? $report['coverage']['generees'] / $report['coverage']['attendues'] * 100 : 0; ?>
                    <div class="report-coverage-number">
                        <strong><?= (int) $report['coverage']['generees'] ?> / <?= (int) $report['coverage']['attendues'] ?></strong>
                        <span><?= number_format($coverageRate, 1, ',', ' ') ?> % généré</span>
                    </div>
                    <div class="report-progress" role="img" aria-label="Couverture des créances <?= number_format($coverageRate, 1, ',', ' ') ?> %">
                        <span style="width: <?= number_format(min(100, $coverageRate), 2, '.', '') ?>%"></span>
                    </div>
                    <?php if ($report['coverage']['attendues'] === 0): ?>
                        <p class="report-inline-note">Aucun frais actif applicable n’a été trouvé pour ces critères. Vérifiez la configuration avant de conclure que les créances sont complètes.</p>
                    <?php elseif ($report['coverage']['manquantes'] > 0 || $report['coverage']['ecarts_devise'] > 0): ?>
                        <?php if ($report['coverage']['manquantes'] > 0): ?>
                            <p class="report-warning-text"><?= (int) $report['coverage']['manquantes'] ?> créance(s) prévue(s) n’ont pas été générées. Le total « dû » ne couvre que les créances effectivement présentes.</p>
                        <?php endif; ?>
                        <?php if ($report['coverage']['ecarts_devise'] > 0): ?>
                            <p class="report-warning-text"><?= (int) $report['coverage']['ecarts_devise'] ?> créance(s) existante(s) sont dans une devise différente de celle attendue par la configuration actuelle. Vérifiez les créances historiques sans modifier leur journal comptable.</p>
                        <?php endif; ?>
                        <ul class="report-missing-list">
                            <?php foreach (array_slice($report['coverage']['par_frais'], 0, 8) as $missing): ?>
                                <li><span><?= e($missing['libelle']) ?> · <?= e($missing['categorie']) ?></span><strong><?= (int) $missing['manquantes'] ?> manquante(s)</strong></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="report-success-text">Toutes les créances prévues par les frais actifs ont été générées pour les inscriptions éligibles.</p>
                    <?php endif; ?>
                </article>

                <article class="report-panel">
                    <div class="report-panel-heading">
                        <div><h3>Relances à traiter</h3><p>Actions ouvertes, sans suite enregistrée</p></div>
                        <a class="btn btn-outline-warning btn-sm no-print" href="<?= e(APP_BASE_PATH) ?>/paiements/impayes?annee_id=<?= (int) $filters['annee_id'] ?>">Suivi des impayés</a>
                    </div>
                    <div class="report-followup-grid">
                        <div><span>Relances ouvertes</span><strong><?= (int) ($report['followups']['total'] ?? 0) ?></strong></div>
                        <div><span>En retard</span><strong class="text-danger"><?= (int) ($report['followups']['en_retard'] ?? 0) ?></strong></div>
                        <div><span>À venir dans la période</span><strong><?= (int) ($report['followups']['a_venir'] ?? 0) ?></strong></div>
                    </div>
                    <p class="report-inline-note">Les relances sont suivies par élève et par année scolaire; elles ne sont pas imputées à un frais précis dans les données actuelles.</p>
                </article>
            </div>
        </section>

        <section class="finance-report-section">
            <div class="finance-report-section-heading">
                <div>
                    <h2>
                        <?php
                        $debtTitles = [
                            '' => 'Détail des créances correspondantes',
                            'ouverte' => 'Créances avec un solde',
                            'retard' => 'Créances en retard',
                            'partielle' => 'Créances partiellement payées',
                            'impayee' => 'Créances non payées',
                            'soldee' => 'Créances soldées',
                        ];
                        echo e($debtTitles[$filters['etat_creance']] ?? 'Créances correspondantes');
                        ?>
                    </h2>
                    <p><?= number_format((int) $report['debt_total'], 0, ',', ' ') ?> ligne(s) · page <?= (int) $report['debt_page'] ?> sur <?= (int) $report['debt_pages'] ?> · 50 lignes par page</p>
                </div>
                <a class="btn btn-outline-warning btn-sm no-print" href="<?= e(APP_BASE_PATH) ?>/paiements/impayes?annee_id=<?= (int) $filters['annee_id'] ?>">Ouvrir les impayés</a>
            </div>
            <article class="report-panel">
                <div class="table-responsive" role="region" aria-label="Tableau des créances manquantes, défilement horizontal possible" tabindex="0">
                    <table class="table report-table">
                        <thead><tr><th>Matricule</th><th>Élève</th><th>Classe</th><th>Frais / période</th><th>Échéance</th><th>État</th><th>Dû</th><th>Affecté</th><th>Solde</th><th>Relance</th></tr></thead>
                        <tbody>
                        <?php if (!$report['debts']): ?>
                            <tr><td colspan="10"><div class="report-empty">Aucune créance ne correspond à ces critères.</div></td></tr>
                        <?php else: foreach ($report['debts'] as $debt): ?>
                            <?php
                            $remaining = (float) $debt['solde'];
                            $settled = $remaining <= 0;
                            $partial = !$settled && (float) $debt['montant_affecte'] > 0;
                            $overdue = !$settled && $debt['date_echeance'] && $debt['date_echeance'] < $filters['date_situation'];
                            $statusLabel = $settled ? 'Soldée' : ($overdue ? 'En retard' : ($partial ? 'Partielle' : 'Impayée'));
                            $profileUrl = APP_BASE_PATH . '/eleves/fiche/' . (int) $debt['eleve_id'] . '?onglet=paiements';
                            ?>
                            <tr>
                                <td><?= e($debt['matricule']) ?></td>
                                <td><a href="<?= e($profileUrl) ?>"><?= e($debt['prenom'] . ' ' . $debt['nom']) ?></a></td>
                                <td><?= e($debt['classe_nom']) ?></td>
                                <td><?= e($debt['libelle_snapshot']) ?><small><?= e($debt['categorie']) ?> · <?= e($debt['periode_code']) ?></small></td>
                                <td><?= $debt['date_echeance'] ? e(date('d/m/Y', strtotime($debt['date_echeance']))) : '—' ?></td>
                                <td><span class="report-status <?= $settled ? 'is-settled' : ($overdue ? 'is-overdue' : ($partial ? 'is-partial' : 'is-unpaid')) ?>"><?= e($statusLabel) ?></span></td>
                                <td><?= e($formatAmount((float) $debt['montant_du'], $debt['devise'])) ?></td>
                                <td><?= e($formatAmount((float) $debt['montant_affecte'], $debt['devise'])) ?></td>
                                <td><strong><?= e($formatAmount($remaining, $debt['devise'])) ?></strong></td>
                                <td><?= $debt['prochaine_relance'] ? e(date('d/m/Y', strtotime($debt['prochaine_relance']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($report['debt_pages'] > 1): ?>
                    <nav class="report-pagination no-print" aria-label="Pagination des créances">
                        <?php if ($report['debt_page'] > 1): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="?<?= e($queryForPage($report['debt_page'] - 1)) ?>">Précédent</a>
                        <?php endif; ?>
                        <span>Page <?= (int) $report['debt_page'] ?> / <?= (int) $report['debt_pages'] ?></span>
                        <?php if ($report['debt_page'] < $report['debt_pages']): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="?<?= e($queryForPage($report['debt_page'] + 1)) ?>">Suivant</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </article>
        </section>

        <section class="finance-report-section">
            <div class="finance-report-section-heading">
                <div>
                    <h2>Journal des derniers encaissements</h2>
                    <p>30 dernières lignes de la période; les reçus corrigés restent identifiables.</p>
                </div>
            </div>
            <article class="report-panel">
                <div class="table-responsive" role="region" aria-label="Tableau des créances détaillées, défilement horizontal possible" tabindex="0">
                    <table class="table report-table">
                        <thead><tr><th>Date</th><th>Reçu</th><th>Élève / classe</th><th>Mode</th><th>Montant remis</th><th>Taux au reçu</th><th>État</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$report['cash_entries']): ?>
                            <tr><td colspan="8"><div class="report-empty">Aucun versement sur la période sélectionnée.</div></td></tr>
                        <?php else: foreach ($report['cash_entries'] as $entry): ?>
                            <?php $corrected = $entry['type_correction'] !== null; ?>
                            <tr>
                                <td><?= e(date('d/m/Y H:i', strtotime($entry['date_versement']))) ?></td>
                                <td><strong><?= e($entry['numero_recu']) ?></strong></td>
                                <td><?= e($entry['prenom'] . ' ' . $entry['nom']) ?><small><?= e($entry['matricule']) ?> · <?= e($entry['classe_nom'] ?? 'Classe non renseignée') ?></small></td>
                                <td><?= e($entry['mode_paiement']) ?></td>
                                <td><?= e($formatAmount((float) $entry['montant_recu'], $entry['devise_recue'])) ?></td>
                                <td>1 USD = <?= number_format((float) $entry['taux_cdf_par_usd'], 2, ',', ' ') ?> CDF</td>
                                <td>
                                    <?php if (!$corrected): ?>
                                        <span class="report-status is-settled">Actif</span>
                                    <?php else: ?>
                                        <span class="report-status is-overdue"><?= $entry['type_correction'] === 'remboursement' ? 'Remboursé' : 'Annulé' ?></span>
                                        <small><?= e($entry['numero_contre_ecriture']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><a class="btn btn-outline-primary btn-sm no-print" href="<?= e(APP_BASE_PATH) ?>/paiements/factures?eleve_id=<?= (int) $entry['eleve_id'] ?>&amp;annee_id=<?= (int) $filters['annee_id'] ?>">Historique</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <footer class="finance-report-footer">
            Rapport généré le <?= e(date('d/m/Y à H:i')) ?> · Année <?= e($year['libelle']) ?> · Soldes établis à la date indiquée en haut du rapport.
        </footer>
    <?php endif; ?>
</div>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
