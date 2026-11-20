<?php
defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('rapports.consulter');
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'StatistiqueController.php';

$statistiqueController = new StatistiqueController();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $statistiqueController->handleAjax();
}
$filters = $statistiqueController->getFilterOptions();
$pageTitle = 'Statistiques générales';
$pageIcon = 'fa-chart-pie';
$pageDescription = 'Résultats scolaires, présence, discipline et recouvrement';
$extraCSS = ['modules/statistiques.css?v=2'];
$extraJS = ['common/chart.umd.min.js', 'modules/statistiques.js?v=2'];
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/dashboard', ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <span class="current">Statistiques générales</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-chart-pie"></i> Statistiques générales</h4>
        <p>Indicateurs scolaires calculés sur la période et le périmètre sélectionnés.</p>
    </div>
    <div class="resultats-header-right">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer</button>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnActualiser" onclick="chargerToutesLesDonnees()"><i class="fa-solid fa-rotate"></i> Actualiser</button>
    </div>
</div>

<div id="flashMessage" role="status" aria-live="polite"></div>
<section class="report-filters" aria-label="Filtres des statistiques">
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
    <div class="filter-group">
        <label for="filtreTrimestre"><i class="fa-solid fa-list-ol"></i> Trimestre des résultats</label>
        <select id="filtreTrimestre" class="form-control">
            <option value="">Tous les trimestres</option>
            <?php foreach ($filters['trimestres'] as $trimestre): ?>
                <option value="<?= (int) $trimestre['id'] ?>">
                    <?= htmlspecialchars($trimestre['libelle'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="btnAppliquerFiltres"><i class="fa-solid fa-filter"></i> Appliquer</button>
    <span class="report-filter-status" id="reportPeriode" aria-live="polite"></span>
</section>

<div class="kpi-grid mb-4">
    <div class="kpi-card blue"><div class="kpi-card-header"><div class="kpi-card-icon blue-bg"><i class="fa-solid fa-users"></i></div><div class="kpi-card-title">Élèves inscrits</div></div><div class="kpi-card-value" id="kpiEleves">—</div><div class="kpi-card-subtitle neutral" id="kpiEvolution">Année sélectionnée</div></div>
    <div class="kpi-card green"><div class="kpi-card-header"><div class="kpi-card-icon green-bg"><i class="fa-solid fa-chalkboard"></i></div><div class="kpi-card-title">Classes</div></div><div class="kpi-card-value" id="kpiClasses">—</div><div class="kpi-card-subtitle neutral" id="kpiRatio">—</div></div>
    <div class="kpi-card purple"><div class="kpi-card-header"><div class="kpi-card-icon purple-bg"><i class="fa-solid fa-chart-line"></i></div><div class="kpi-card-title">Moyenne générale</div></div><div class="kpi-card-value" id="kpiMoyenne">—</div><div class="kpi-card-subtitle neutral" id="kpiMoyenneDetail">Sur 100 · résultats saisis</div></div>
    <div class="kpi-card cyan"><div class="kpi-card-header"><div class="kpi-card-icon cyan-bg"><i class="fa-solid fa-calendar-check"></i></div><div class="kpi-card-title">Présence pointée</div></div><div class="kpi-card-value" id="kpiPresence">—</div><div class="kpi-card-subtitle neutral" id="kpiPresenceDetail">—</div></div>
    <div class="kpi-card orange"><div class="kpi-card-header"><div class="kpi-card-icon orange-bg"><i class="fa-solid fa-trophy"></i></div><div class="kpi-card-title">Réussite scolaire</div></div><div class="kpi-card-value" id="kpiReussite">—</div><div class="kpi-card-subtitle neutral" id="kpiReussiteDetail">Seuil : 50/100</div></div>
    <div class="kpi-card red"><div class="kpi-card-header"><div class="kpi-card-icon red-bg"><i class="fa-solid fa-hand-holding-dollar"></i></div><div class="kpi-card-title">Recouvrement obligatoire</div></div><div class="kpi-card-value" id="kpiRecouvrement">—</div><div class="kpi-card-subtitle neutral" id="kpiRecouvrementDetail">—</div></div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-chart-line"></i> Résultats par trimestre</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartResultatsTrimestre"></canvas></div></div>
        <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Trimestre</th><th>Effectif</th><th>Résultats saisis</th><th>Moyenne /100</th><th>Réussite</th><th>Couverture</th></tr></thead><tbody id="tbodyResultatsTrimestre"></tbody></table></div>
    </div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-ranking-star"></i> Moyenne et réussite par classe</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartResultatsClasse"></canvas></div></div></div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-money-bill-wave"></i> Recouvrement des frais obligatoires par classe</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartRecouvrement"></canvas></div></div>
        <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Classe</th><th>Effectif</th><th>Attendu</th><th>Payé</th><th>Reste</th><th>Recouvrement</th></tr></thead><tbody id="tbodyRecouvrement"></tbody></table></div>
    </div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-calendar-check"></i> Présences et absences par mois</h5><small>Calculé uniquement sur les pointages saisis</small></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartPresencesMois"></canvas></div></div>
        <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Mois</th><th>Présences</th><th>Absences</th><th>Pointages</th><th>Taux présence</th></tr></thead><tbody id="tbodyPresences"></tbody></table></div>
    </div>
</div>

<div class="charts-grid-2 mb-4">
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-gavel"></i> Incidents disciplinaires par mois</h5></div><div class="card-body"><div class="chart-wrapper-md"><canvas id="chartIncidents"></canvas></div></div>
        <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Mois</th><th>Légers</th><th>Moyens</th><th>Graves</th><th>Total</th></tr></thead><tbody id="tbodyIncidents"></tbody></table></div>
    </div>
    <div class="card"><div class="card-header"><h5><i class="fa-solid fa-medal"></i> Classes nécessitant un suivi</h5><small>Moins de 50 % de réussite parmi les résultats saisis</small></div>
        <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Classe</th><th>Résultats</th><th>Moyenne /100</th><th>Réussite</th><th>Échecs</th></tr></thead><tbody id="tbodyClassesRisque"></tbody></table></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h5><i class="fa-solid fa-award"></i> Meilleures classes selon les résultats disponibles</h5></div>
    <div class="table-responsive"><table class="table table-finance"><thead><tr><th>Rang</th><th>Classe</th><th>Résultats</th><th>Moyenne /100</th><th>Réussite</th></tr></thead><tbody id="tbodyTopClasses"></tbody></table></div>
</div>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
