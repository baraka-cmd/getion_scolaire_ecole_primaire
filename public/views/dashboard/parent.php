<?php
/**
 * Fichier : public/views/dashboard/parent.php
 * Rôle    : Tableau de bord — Parent
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../src/config/app.php';
requireLogin();

if (!hasRole(['Parent', 'Admin', 'Directeur'])) {
    redirect('/gestion_scolaire/dashboard');
}

$parentUserId = (int) ($_SESSION['utilisateur_id'] ?? 0);

require_once __DIR__ . '/../../../src/controllers/ParentDashboardController.php';
$dashboardController = new ParentDashboardController();
extract($dashboardController->getViewData($parentUserId), EXTR_OVERWRITE);

$pageTitle = 'Tableau de bord — Parent';
$pageIcon  = 'fa-gauge-high';
$pageDescription = 'Suivi de mes enfants';
$extraCSS = ['dashboard/dashboard.css'];
$extraJS  = ['dashboard/dashboard.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 style="font-weight:600;color:var(--color-dark);margin-bottom:4px;">
            <i class="fa-solid fa-people-roof text-primary me-2"></i>Bienvenue, <?= e($_SESSION['utilisateur_prenom'] ?? 'Parent') ?>
        </h4>
        <p style="font-size:0.82rem;color:var(--color-gray-500);margin:0;">
            <i class="fa-solid fa-children me-1"></i> <?= count($mesEnfants) ?> enfant(s) suivi(s)
        </p>
    </div>
    <div>
        <a href="/gestion_scolaire/messages" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-envelope"></i> Messages
            <?php if ($messagesNonLus > 0): ?>
                <span class="badge badge-danger ms-1"><?= $messagesNonLus ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card students">
        <div class="stat-icon blue"><i class="fa-solid fa-children"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= count($mesEnfants) ?></div>
            <div class="stat-label">Enfant(s) inscrit(s)</div>
        </div>
    </div>
    <div class="stat-card payments">
        <div class="stat-icon purple"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-content">
            <?php $totalDu = 0; foreach ($mesEnfants as $e) $totalDu += $e['paiement_du']; ?>
            <div class="stat-value">$ <?= number_format($totalDu, 0, ',', ' ') ?></div>
            <div class="stat-label">Solde restant à payer</div>
        </div>
    </div>
    <div class="stat-card messages">
        <div class="stat-icon red"><i class="fa-solid fa-envelope"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $messagesNonLus ?></div>
            <div class="stat-label">Messages non lus</div>
        </div>
    </div>
    <div class="stat-card attendance">
        <div class="stat-icon cyan"><i class="fa-solid fa-bell"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $notifsNonLues ?></div>
            <div class="stat-label">Notifications</div>
        </div>
    </div>
</div>

<div class="charts-grid">
    <?php if (empty($mesEnfants)): ?>
        <div class="card"><div class="card-body"><div class="empty-state"><i class="fa-solid fa-children"></i><p>Aucun enfant trouvé.</p></div></div></div>
    <?php else: ?>
        <?php foreach ($mesEnfants as $enfant): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <div class="card-header-icon <?= $enfant['sexe'] === 'M' ? 'blue' : 'purple' ?>">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div>
                            <div class="card-header-title"><?= e($enfant['prenom'] . ' ' . $enfant['nom']) ?></div>
                            <div class="card-header-subtitle"><?= e($enfant['classe_nom']) ?> — <?= e($enfant['matricule']) ?></div>
                        </div>
                    </div>
                    <div class="card-header-actions">
                        <a href="/gestion_scolaire/eleves/fiche/<?= $enfant['id'] ?>" class="btn-card-action"><i class="fa-solid fa-eye"></i> Détails</a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 12px;">
                        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:10px;">
                            <div style="font-size:1.4rem;font-weight:700;color:<?= ($enfant['moyenne'] ?? 0) >= 10 ? '#10b981' : '#ef4444' ?>">
                                <?= $enfant['moyenne'] ? number_format($enfant['moyenne'], 1) : '—' ?>
                            </div>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:4px;">Moyenne</div>
                        </div>
                        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:10px;">
                            <div style="font-size:1.4rem;font-weight:700;color:<?= $enfant['absences'] > 5 ? '#ef4444' : '#10b981' ?>"><?= $enfant['absences'] ?></div>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:4px;">Absences</div>
                        </div>
                        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:10px;">
                            <div style="font-size:1.4rem;font-weight:700;color:<?= $enfant['retards'] > 3 ? '#f59e0b' : '#10b981' ?>"><?= $enfant['retards'] ?></div>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:4px;">Retards</div>
                        </div>
                        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:10px;">
                            <div style="font-size:1.1rem;font-weight:700;color:<?= $enfant['paiement_du'] > 0 ? '#ef4444' : '#10b981' ?>">
                                <?= $enfant['paiement_du'] > 0 ? '$ ' . number_format($enfant['paiement_du'], 0) : 'Payé' ?>
                            </div>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:4px;">À payer</div>
                        </div>
                    </div>
                    <div class="d-flex gap-sm mt-3">
                        <a href="/gestion_scolaire/notes/consulter" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-chart-bar"></i> Notes</a>
                        <a href="/gestion_scolaire/bulletins" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-file-invoice"></i> Bulletin</a>
                        <a href="/gestion_scolaire/absences" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-calendar-xmark"></i> Absences</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="bottom-grid">
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon green"><i class="fa-solid fa-calendar"></i></div>
                <div>
                    <div class="card-header-title">Prochains événements</div>
                    <div class="card-header-subtitle">Calendrier scolaire</div>
                </div>
            </div>
        </div>
        <div class="card-body no-padding">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Événement</th><th>Type</th></tr></thead>
                    <tbody>
                        <?php if (empty($prochainsEvenements)): ?>
                            <tr><td colspan="3"><div class="empty-state"><i class="fa-solid fa-calendar"></i><p>Aucun événement</p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($prochainsEvenements as $event): ?>
                                <tr>
                                    <td><strong><?= formatDate($event['date_debut'], 'd/m/Y') ?></strong></td>
                                    <td><?= e($event['titre']) ?></td>
                                    <td><span class="badge badge-info"><?= e($event['type_evenement']) ?></span></td>
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
                <div class="card-header-icon orange"><i class="fa-solid fa-lightbulb"></i></div>
                <div><div class="card-header-title">Conseils</div><div class="card-header-subtitle">Pour le suivi</div></div>
            </div>
        </div>
        <div class="card-body">
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:#f0fdf4;border-radius:10px;">
                    <i class="fa-solid fa-check-circle" style="color:#10b981;font-size:1.2rem;margin-top:2px;"></i>
                    <div><strong style="font-size:0.85rem;">Consultez régulièrement les notes</strong><p style="font-size:0.75rem;color:#64748b;margin:4px 0 0;">Suivez l'évolution des résultats de vos enfants.</p></div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:#dbeafe;border-radius:10px;">
                    <i class="fa-solid fa-info-circle" style="color:#3b82f6;font-size:1.2rem;margin-top:2px;"></i>
                    <div><strong style="font-size:0.85rem;">Justifiez les absences rapidement</strong><p style="font-size:0.75rem;color:#64748b;margin:4px 0 0;">Une absence non justifiée peut affecter le dossier scolaire.</p></div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:#fef3c7;border-radius:10px;">
                    <i class="fa-solid fa-exclamation-triangle" style="color:#f59e0b;font-size:1.2rem;margin-top:2px;"></i>
                    <div><strong style="font-size:0.85rem;">Réglez les frais scolaires à temps</strong><p style="font-size:0.75rem;color:#64748b;margin:4px 0 0;">Les retards de paiement peuvent entraîner des pénalités.</p></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>