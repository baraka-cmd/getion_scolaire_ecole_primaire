<?php
/**
 * Fichier : public/views/modules/paiements/liste.php
 * Rôle    : Liste des paiements avec filtres, statistiques et actions
 * Système : École primaire congolaise — Gestion financière
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('paiements.consulter');

global $db;

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PaiementController.php';
$paiementController = new PaiementController();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $paiementController->handleAjax();
}

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PaymentReadController.php';
$paymentReadController = new PaymentReadController();
extract($paymentReadController->getViewData('liste', $_GET), EXTR_OVERWRITE);

$pageTitle = 'Gestion des paiements';
$pageIcon  = 'fa-money-bill-wave';
$pageDescription = 'Liste des paiements, encaissements et suivi financier';
$extraCSS = ['modules/paiements.css'];
$extraJS  = ['modules/paiements.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <span class="current">Paiements</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-money-bill-wave"></i> Gestion des paiements</h4>
        <p>Un dossier par élève : consultez ses versements et reçus dans son historique.</p>
    </div>
    <div class="resultats-header-right">
        <form method="get" class="d-flex gap-sm align-items-center">
            <label for="anneeFinance" class="mb-0">Année :</label>
            <select id="anneeFinance" name="annee_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($annees as $annee): ?>
                    <option value="<?= (int) $annee['id'] ?>" <?= $anneeId === (int) $annee['id'] ? 'selected' : '' ?>>
                        <?= e($annee['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/enregistrer?annee_id=<?= $anneeId ?>" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nouveau paiement
        </a>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/impayes?annee_id=<?= $anneeId ?>" class="btn btn-outline-warning btn-sm">
            <i class="fa-solid fa-exclamation-circle"></i> Impayés
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Imprimer
        </button>
    </div>
</div>

<!-- ============================================================
     STATISTIQUES
     ============================================================ -->
<div class="retards-stats mb-4">
    <div class="retard-stat-card">
        <div class="retard-stat-icon green">
            <i class="fa-solid fa-hand-holding-dollar"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">$ <?= number_format((float) $totauxVersements['total_usd'], 2, ',', ' ') ?></div>
            <div class="retard-stat-label">Encaissé en USD · année</div>
            <span class="stat-change neutral"><?= (int) $totauxVersements['nb_versements'] ?> versement(s)</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon blue">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">FC <?= number_format((float) $totauxVersements['total_cdf'], 0, ',', ' ') ?></div>
            <div class="retard-stat-label">Encaissé en CDF · année</div>
            <span class="stat-change neutral">Total séparé, sans conversion rétroactive</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon orange">
            <i class="fa-solid fa-user-check"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= (int) $totauxMois['nb_versements_mois'] ?></div>
            <div class="retard-stat-label">Versements ce mois</div>
            <span class="stat-change neutral">$ <?= number_format((float) $totauxMois['mois_usd'], 2, ',', ' ') ?> · FC <?= number_format((float) $totauxMois['mois_cdf'], 0, ',', ' ') ?></span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon red">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= $nbImpayes ?></div>
            <div class="retard-stat-label">Élèves avec solde ouvert</div>
            <span class="stat-change down">Sur <?= $nbElevesTotal ?> inscrits</span>
        </div>
    </div>
</div>

<!-- ============================================================
     FILTRES
     ============================================================ -->
<div class="retards-filters no-print">
    <div class="filter-group">
        <label><i class="fa-solid fa-chalkboard"></i> Classe</label>
        <select id="filterClasse" onchange="appliquerFiltres()">
            <option value="">Toutes les classes</option>
            <?php foreach ($classes as $cl): ?>
                <option value="<?= $cl['id'] ?>"><?= e($cl['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label><i class="fa-solid fa-toggle-on"></i> Statut</label>
        <select id="filterStatut" onchange="appliquerFiltres()">
            <option value="">Tous les statuts</option>
            <option value="Enregistré">Enregistrés</option>
            <option value="Annulé">Annulés</option>
            <option value="Remboursé">Remboursés</option>
        </select>
    </div>
    <div class="filter-group">
        <label><i class="fa-solid fa-calendar"></i> Du</label>
        <input type="date" id="filterDateDebut" onchange="appliquerFiltres()">
    </div>
    <div class="filter-group">
        <label><i class="fa-solid fa-calendar"></i> Au</label>
        <input type="date" id="filterDateFin" onchange="appliquerFiltres()">
    </div>
    <div class="filter-group">
        <label><i class="fa-solid fa-search"></i> Recherche</label>
        <input type="text" id="filterRecherche" placeholder="Nom, prénom, matricule..." onkeyup="appliquerFiltres()">
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="reinitialiserFiltres()" style="align-self:flex-end;">
        <i class="fa-solid fa-rotate"></i> Réinitialiser
    </button>
</div>

<!-- ============================================================
     TABLEAU
     ============================================================ -->
<div class="retards-table-card">
    <div class="table-header-info" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color-light);">
        <span><strong id="totalPaiements">—</strong> élève(s) avec versements</span>
        <span style="font-size:0.75rem;color:var(--color-gray-400);">
            <i class="fa-solid fa-info-circle"></i> Les sommes restent séparées par devise. Ouvrez la fiche pour le détail.
        </span>
    </div>
    <div class="table-responsive">
        <table class="table payment-register-table">
            <thead>
                <tr>
                    <th>Élève</th>
                    <th>Classe</th>
                    <th>Versements</th>
                    <th>Montant des reçus · USD</th>
                    <th>Montant des reçus · CDF</th>
                    <th>Dernier versement</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tbodyPaiements">
                <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Chargement...</p></div></td></tr>
            </tbody>
        </table>
    </div>
    <div class="pagination-wrapper">
        <div class="pagination-info" id="paginationInfo"></div>
        <div class="pagination" id="paginationPaiements"></div>
    </div>
</div>

<script>
function getFiltres() {
    return {
        classe_id: document.getElementById('filterClasse')?.value || '',
        statut: document.getElementById('filterStatut')?.value || '',
        date_debut: document.getElementById('filterDateDebut')?.value || '',
        date_fin: document.getElementById('filterDateFin')?.value || '',
        recherche: document.getElementById('filterRecherche')?.value || '',
        annee_id: '<?= $anneeId ?>'
    };
}

function appliquerFiltres() { chargerPaiements(1); }

function reinitialiserFiltres() {
    ['filterClasse','filterStatut','filterDateDebut','filterDateFin','filterRecherche'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    chargerPaiements(1);
}

function chargerPaiements(page) {
    const formData = new FormData();
    formData.append('action', 'getRegistrePaiements');
    formData.append('page', page);

    const filtres = getFiltres();
    Object.keys(filtres).forEach(key => {
        if (filtres[key]) formData.append(key, filtres[key]);
    });

    fetch((window.APP_BASE_PATH || '') + '/paiements', {
        method: 'POST', body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) afficherPaiements(data);
        else showToast(data.message || 'Impossible de charger le registre.', 'error');
    });
}

function afficherPaiements(data) {
    const tbody = document.getElementById('tbodyPaiements');
    document.getElementById('totalPaiements').textContent = data.total;

    if (!data.eleves || data.eleves.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Aucun élève ne correspond à ces critères.</p></div></td></tr>';
        document.getElementById('paginationPaiements').innerHTML = '';
        document.getElementById('paginationInfo').textContent = '';
        return;
    }

    tbody.innerHTML = data.eleves.map(eleve => {
        return `
        <tr>
            <td><span class="badge badge-primary">${escapeHtml(eleve.matricule)}</span><br><strong>${escapeHtml(eleve.prenom + ' ' + eleve.nom)}</strong></td>
            <td>${escapeHtml(eleve.classe_nom)}</td>
            <td>${Number(eleve.nombre_versements)} reçu(s)</td>
            <td><strong>${formatFinanceAmount(eleve.recu_usd, 'USD')}</strong></td>
            <td><strong>${formatFinanceAmount(eleve.recu_cdf, 'CDF')}</strong></td>
            <td>${escapeHtml(eleve.derniere_date_format || '—')}</td>
            <td>
                <div class="d-flex gap-xs">
                    <a href="${(window.APP_BASE_PATH || '')}/eleves/fiche/${encodeURIComponent(eleve.eleve_id)}?onglet=paiements&annee_id=${encodeURIComponent(getFiltres().annee_id)}" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-clock-rotate-left"></i> Historique
                    </a>
                </div>
            </td>
        </tr>`;
    }).join('');

    let paginationHtml = '';
    if (data.pages > 1) {
        const start = Math.max(1, data.page - 2);
        const end = Math.min(data.pages, data.page + 2);
        if (start > 1) paginationHtml += `<a href="#" onclick="chargerPaiements(1); return false;">1</a><span>…</span>`;
        for (let i = start; i <= end; i++) {
            paginationHtml += `<a href="#" onclick="chargerPaiements(${i}); return false;" class="${i === data.page ? 'active' : ''}">${i}</a>`;
        }
        if (end < data.pages) paginationHtml += `<span>…</span><a href="#" onclick="chargerPaiements(${data.pages}); return false;">${data.pages}</a>`;
    }
    document.getElementById('paginationPaiements').innerHTML = paginationHtml;
    document.getElementById('paginationInfo').textContent = `Page ${data.page} / ${data.pages} — ${data.total} résultat(s)`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatFinanceAmount(amount, currency) {
    const formatted = (Number(amount) || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${formatted} ${currency}`;
}

document.addEventListener('DOMContentLoaded', () => chargerPaiements(1));
</script>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>