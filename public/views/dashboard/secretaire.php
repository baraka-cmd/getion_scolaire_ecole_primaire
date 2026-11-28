<?php
/**
 * Fichier : public/views/dashboard/secretaire.php
 * Rôle    : Tableau de bord — Secrétaire
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../src/config/app.php';
requireLogin();

if (!hasRole(['Secrétaire', 'Admin', 'Directeur'])) {
    redirect('/gestion_scolaire/dashboard');
}

require_once __DIR__ . '/../../../src/controllers/SecretaryDashboardController.php';
$dashboardController = new SecretaryDashboardController();
extract($dashboardController->getViewData((int) ($_SESSION['utilisateur_id'] ?? 0)), EXTR_OVERWRITE);

// ============================================================
// MÉTADONNÉES
// ============================================================

$pageTitle = 'Tableau de bord — Secrétaire';
$pageIcon  = 'fa-gauge-high';
$pageDescription = 'Gestion administrative';
$extraCSS = ['dashboard/dashboard.css'];
$extraJS  = ['dashboard/dashboard.js'];

// Préparer les données pour les graphiques
$labelsClasseJSON = json_encode(array_column($elevesParClasse, 'nom'));
$dataClasseJSON = json_encode(array_map('intval', array_column($elevesParClasse, 'total')));

// Script inline pour les graphiques (exécuté APRÈS Chart.js dans footer.php)
$inlineJS = "
document.addEventListener('DOMContentLoaded', function() {
    var ctx1 = document.getElementById('chartElevesClasse');
    if (ctx1) {
        new Chart(ctx1.getContext('2d'), {
            type: 'bar',
            data: {
                labels: {$labelsClasseJSON},
                datasets: [{
                    label: 'Nombre d\'élèves',
                    data: {$dataClasseJSON},
                    backgroundColor: ['#2c3e90', '#3498db', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                    borderRadius: 8,
                    maxBarThickness: 50,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    var ctx2 = document.getElementById('chartSexeGlobal');
    if (ctx2) {
        new Chart(ctx2.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Garçons', 'Filles'],
                datasets: [{
                    data: [{$totalGarcons}, {$totalFilles}],
                    backgroundColor: ['#3b82f6', '#ec4899'],
                    borderWidth: 3,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
                }
            }
        });
    }
});
";

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- En-tête -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 style="font-weight:600;color:var(--color-dark);margin-bottom:4px;">
            <i class="fa-solid fa-clipboard-list text-primary me-2"></i>Tableau de bord — Secrétariat
        </h4>
        <p style="font-size:0.82rem;color:var(--color-gray-500);margin:0;">
            <i class="fa-solid fa-calendar me-1"></i> Année scolaire en cours
            <span class="mx-2">|</span>
            <i class="fa-solid fa-users me-1"></i> <?= $totalEleves ?> élèves inscrits
        </p>
    </div>
    <div>
        <a href="/gestion_scolaire/inscriptions/nouvelle" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nouvelle inscription
        </a>
        <a href="/gestion_scolaire/eleves/ajouter" class="btn btn-success btn-sm ms-2">
            <i class="fa-solid fa-user-plus"></i> Ajouter un élève
        </a>
        <a href="/gestion_scolaire/eleves/import" class="btn btn-outline-primary btn-sm ms-2">
            <i class="fa-solid fa-file-import"></i> Importer
        </a>
    </div>
</div>

<!-- Cartes statistiques -->
<div class="stats-grid">
    <div class="stat-card students">
        <div class="stat-icon blue"><i class="fa-solid fa-graduation-cap"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalEleves, 0, ',', ' ') ?></div>
            <div class="stat-label">Élèves inscrits</div>
            <span class="stat-change up"><i class="fa-solid fa-arrow-up"></i> +<?= $nouvellesInscriptions ?> ce mois</span>
        </div>
    </div>
    <div class="stat-card teachers">
        <div class="stat-icon green"><i class="fa-solid fa-chalkboard-user"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $totalEnseignants ?></div>
            <div class="stat-label">Enseignants</div>
            <span class="stat-change neutral"><i class="fa-solid fa-school"></i> Corps professoral</span>
        </div>
    </div>
    <div class="stat-card classes">
        <div class="stat-icon orange"><i class="fa-solid fa-chalkboard"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $totalClasses ?></div>
            <div class="stat-label">Classes actives</div>
        </div>
    </div>
    <div class="stat-card attendance">
        <div class="stat-icon cyan"><i class="fa-solid fa-user-clock"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $inscriptionsAttente ?></div>
            <div class="stat-label">Inscriptions récentes</div>
            <span class="stat-change neutral"><i class="fa-solid fa-calendar"></i> 7 derniers jours</span>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="charts-grid">
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon blue"><i class="fa-solid fa-chart-bar"></i></div>
                <div>
                    <div class="card-header-title">Élèves par classe</div>
                    <div class="card-header-subtitle">Répartition des effectifs</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container large"><canvas id="chartElevesClasse"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon purple"><i class="fa-solid fa-venus-mars"></i></div>
                <div>
                    <div class="card-header-title">Répartition par sexe</div>
                    <div class="card-header-subtitle">Tous les élèves</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container large"><canvas id="chartSexeGlobal"></canvas></div>
        </div>
    </div>
</div>

<!-- Absences du jour + Derniers inscrits -->
<div class="bottom-grid">
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon red"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div>
                    <div class="card-header-title">Absences du jour</div>
                    <div class="card-header-subtitle"><?= formatDate(date('Y-m-d'), 'l j F Y') ?></div>
                </div>
            </div>
            <div class="card-header-actions">
                <span class="badge badge-danger"><?= count($absencesJour) ?> absent(s)</span>
            </div>
        </div>
        <div class="card-body no-padding">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Élève</th><th>Classe</th><th>Justifiée</th><th>Motif</th></tr></thead>
                    <tbody>
                        <?php if (empty($absencesJour)): ?>
                            <tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-check-circle"></i><p>Aucune absence aujourd'hui</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($absencesJour as $abs): ?>
                                <tr>
                                    <td><strong><?= e($abs['prenom'] . ' ' . $abs['nom']) ?></strong><br><small class="text-muted"><?= e($abs['matricule']) ?></small></td>
                                    <td><?= e($abs['classe_nom']) ?></td>
                                    <td><?= $abs['est_justifiee'] ? '<span class="badge badge-success">Oui</span>' : '<span class="badge badge-danger">Non</span>' ?></td>
                                    <td><?= e($abs['motif'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon green"><i class="fa-solid fa-user-plus"></i></div>
                <div>
                    <div class="card-header-title">Derniers élèves inscrits</div>
                    <div class="card-header-subtitle">10 dernières inscriptions</div>
                </div>
            </div>
        </div>
        <div class="card-body no-padding">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Matricule</th><th>Élève</th><th>Classe</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php if (empty($derniersInscrits)): ?>
                            <tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Aucune inscription récente</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($derniersInscrits as $eleve): ?>
                                <tr>
                                    <td><span class="badge badge-primary"><?= e($eleve['matricule']) ?></span></td>
                                    <td><strong><?= e($eleve['prenom'] . ' ' . $eleve['nom']) ?></strong></td>
                                    <td><?= e($eleve['classe_nom']) ?></td>
                                    <td><?= e($eleve['date_inscription']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Prochains événements -->
<div class="card">
    <div class="card-header">
        <div class="card-header-left">
            <div class="card-header-icon orange"><i class="fa-solid fa-calendar"></i></div>
            <div>
                <div class="card-header-title">Prochains événements</div>
                <div class="card-header-subtitle">Calendrier scolaire</div>
            </div>
        </div>
        <div class="card-header-actions">
            <a href="/gestion_scolaire/calendrier" class="btn-card-action"><i class="fa-solid fa-list"></i> Voir tout</a>
        </div>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Événement</th><th>Type</th><th>Lieu</th></tr></thead>
                <tbody>
                    <?php if (empty($prochainsEvenements)): ?>
                        <tr><td colspan="4"><div class="empty-state"><i class="fa-solid fa-calendar"></i><p>Aucun événement à venir</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($prochainsEvenements as $event): ?>
                            <tr>
                                <td><strong><?= formatDate($event['date_debut'], 'd/m/Y') ?></strong></td>
                                <td><?= e($event['titre']) ?></td>
                                <td><span class="badge badge-info"><?= e($event['type_evenement']) ?></span></td>
                                <td><?= e($event['lieu'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>