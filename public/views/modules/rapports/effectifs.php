<?php
defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('rapports.consulter');
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'EffectifController.php';

$effectifController = new EffectifController();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $effectifController->handleAjax();
}
$filters = $effectifController->getFilterOptions();
$pageTitle = 'Rapport des effectifs';
$pageIcon = 'fa-chart-bar';
$pageDescription = 'Inscriptions, capacités et démographie scolaire';
$extraCSS = ['modules/effectifs.css?v=2'];
$extraJS = ['common/chart.umd.min.js', 'modules/effectifs.js?v=2'];
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/dashboard', ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <span class="current">Rapport des effectifs</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-chart-bar"></i> Rapport des effectifs</h4>
        <p>Inscriptions, répartition des élèves et occupation des classes.</p>
    </div>
    <div class="resultats-header-right">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer</button>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnActualiser" onclick="chargerToutesLesDonnees()"><i class="fa-solid fa-rotate"></i> Actualiser</button>
    </div>
</div>

<div id="flashMessage" role="status" aria-live="polite"></div>
<section class="effectifs-filters report-filters" aria-label="Filtres du rapport">
    <div class="filter-group">
        <label for="filtreAnnee"><i class="fa-solid fa-calendar-days"></i> Année scolaire</label>
        <select id="filtreAnnee" class="form-control">
            <?php foreach ($filters['annees'] as $annee): ?>
                <option value="<?= (int) $annee['id'] ?>" <?= (int) $annee['id'] === $filters['annee_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($annee['libelle'], ENT_QUOTES, 'UTF-8') ?><?= (int) $annee['est_active'] === 1 ? ' (active)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label for="filtreClasse"><i class="fa-solid fa-chalkboard"></i> Classe</label>
        <select id="filtreClasse" class="form-control">
            <option value="">Toutes les classes</option>
            <?php foreach ($filters['classes'] as $classe): ?>
                <option value="<?= (int) $classe['id'] ?>"><?= htmlspecialchars($classe['nom'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="btnAppliquerFiltres"><i class="fa-solid fa-filter"></i> Appliquer</button>
    <span class="report-filter-status" id="reportPeriode" aria-live="polite"></span>
</section>

<div class="kpi-grid mb-4" id="kpiContainer">
    <div class="kpi-card blue"><div class="kpi-card-header"><div class="kpi-card-icon blue-bg"><i class="fa-solid fa-users"></i></div><div class="kpi-card-title">Élèves inscrits</div></div><div class="kpi-card-value" id="kpiTotalEleves">—</div><div class="kpi-card-subtitle neutral">Actifs et redoublants</div></div>
    <div class="kpi-card green"><div class="kpi-card-header"><div class="kpi-card-icon green-bg"><i class="fa-solid fa-mars"></i></div><div class="kpi-card-title">Garçons</div></div><div class="kpi-card-value" id="kpiGarcons">—</div><div class="kpi-card-subtitle neutral" id="kpiPctGarcons">—</div></div>
    <div class="kpi-card purple"><div class="kpi-card-header"><div class="kpi-card-icon purple-bg"><i class="fa-solid fa-venus"></i></div><div class="kpi-card-title">Filles</div></div><div class="kpi-card-value" id="kpiFilles">—</div><div class="kpi-card-subtitle neutral" id="kpiPctFilles">—</div></div>
    <div class="kpi-card orange"><div class="kpi-card-header"><div class="kpi-card-icon orange-bg"><i class="fa-solid fa-scale-balanced"></i></div><div class="kpi-card-title">Ratio G/F</div></div><div class="kpi-card-value" id="kpiRatio">—</div><div class="kpi-card-subtitle neutral">Garçons par fille</div></div>
    <div class="kpi-card red"><div class="kpi-card-header"><div class="kpi-card-icon red-bg"><i class="fa-solid fa-user-plus"></i></div><div class="kpi-card-title">Nouvelles inscriptions</div></div><div class="kpi-card-value" id="kpiNouveaux">—</div><div class="kpi-card-subtitle neutral">Depuis le début de l’année scolaire</div></div>
    <div class="kpi-card cyan"><div class="kpi-card-header"><div class="kpi-card-icon cyan-bg"><i class="fa-solid fa-chalkboard"></i></div><div class="kpi-card-title" id="kpiClassesTitre">Classes</div></div><div class="kpi-card-value" id="kpiClasses">—</div><div class="kpi-card-subtitle neutral" id="kpiMoyenneClasse">—</div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><h5><i class="fa-solid fa-chart-bar"></i> Effectif par classe et par sexe</h5></div>
    <div class="card-body"><div class="chart-wrapper-lg"><canvas id="chartEffectifsClasse" aria-label="Effectifs par classe"></canvas></div></div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-chart-pie"></i> Répartition par sexe</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartRepartitionSexe"></canvas></div></div></div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-chart-line"></i> Évolution annuelle des effectifs</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartEvolution"></canvas></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header"><div><h5><i class="fa-solid fa-table-list"></i> Détail par classe</h5><small>Capacité et titulaires de l’année sélectionnée</small></div></div>
    <div class="card-body no-padding"><div class="table-responsive">
        <table class="table table-finance"><thead><tr><th>Classe</th><th>Niveau</th><th>Titulaire</th><th>Garçons</th><th>Filles</th><th>Total</th><th>Capacité</th><th>Remplissage</th></tr></thead>
            <tbody id="tbodyEffectifs"><tr><td colspan="8">Chargement…</td></tr></tbody>
        </table>
    </div></div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><div><h5><i class="fa-solid fa-chart-simple"></i> Pyramide des âges</h5><small id="pyramideStats">Âge à la rentrée scolaire</small></div></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartPyramideAges"></canvas></div></div></div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-calendar"></i> Répartition par tranche d’âge</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartTranchesAges"></canvas></div></div></div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-ranking-star"></i> Classes les plus remplies</h5></div><div class="card-body no-padding"><div class="table-responsive">
        <table class="table table-finance"><thead><tr><th>Classe</th><th>Élèves</th><th>Capacité</th><th>Remplissage</th><th>Places</th></tr></thead><tbody id="tbodyTopClasses"></tbody></table>
    </div></div></div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-user-plus"></i> Inscriptions par mois scolaire</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartNouvellesInscriptions"></canvas></div></div></div>
</div>

<script>
window.EFFECTIFS_REPORT = { yearId: <?= (int) $filters['annee_id'] ?>, basePath: <?= json_encode(rtrim(parse_url(BASE_URL, PHP_URL_PATH), '/'), JSON_UNESCAPED_SLASHES) ?> };
</script>
<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
