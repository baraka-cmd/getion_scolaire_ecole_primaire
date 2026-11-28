<?php
/**
 * Fichier : public/views/modules/paiements/impayes.php
 * Rôle    : Suivi des impayés — Élèves avec soldes restants
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
extract($paymentReadController->getViewData('impayes', $_GET), EXTR_OVERWRITE);

$pageTitle = 'Suivi des impayés';
$pageIcon  = 'fa-exclamation-circle';
$pageDescription = 'Liste des élèves avec soldes restants à payer';
$extraCSS = ['modules/paiements.css'];
$extraJS  = ['modules/paiements.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements">Paiements</a>
    <span class="separator">/</span>
    <span class="current">Impayés</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-exclamation-circle"></i> Suivi des impayés</h4>
        <p>Un élève apparaît une seule fois, même si plusieurs frais restent ouverts.</p>
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
        <a href="<?= e(APP_BASE_PATH) ?>/paiements?annee_id=<?= $anneeId ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-list"></i> Tous les paiements
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
        <div class="retard-stat-icon red">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">$ <?= number_format((float) $soldesOuverts['solde_usd'], 2, ',', ' ') ?></div>
            <div class="retard-stat-label">Soldes ouverts · USD</div>
            <span class="stat-change down">Créances de l’année sélectionnée</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon orange">
            <i class="fa-solid fa-user-xmark"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">FC <?= number_format((float) $soldesOuverts['solde_cdf'], 0, ',', ' ') ?></div>
            <div class="retard-stat-label">Soldes ouverts · CDF</div>
            <span class="stat-change down">Non convertis entre devises</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon green">
            <i class="fa-solid fa-user-check"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= $nbElevesImpayes ?> / <?= $nbElevesTotal ?></div>
            <div class="retard-stat-label">Élèves avec solde ouvert</div>
            <span class="stat-change neutral">Sur les inscriptions de l’année</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon blue">
            <i class="fa-solid fa-chart-pie"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= $nbCreancesEnRetard ?></div>
            <div class="retard-stat-label">Créances échues non soldées</div>
            <span class="stat-change down">À traiter en priorité</span>
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
        <label><i class="fa-solid fa-calendar-xmark"></i> Échéance</label>
        <select id="filterEcheance" onchange="appliquerFiltres()">
            <option value="">Tous les soldes ouverts</option>
            <option value="retard">Avec échéance en retard</option>
        </select>
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
     TABLEAU DES IMPAYÉS
     ============================================================ -->
<div class="retards-table-card">
    <div class="table-header-info" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color-light);">
        <span><strong id="totalImpayes">—</strong> élève(s) avec solde ouvert</span>
        <span style="font-size:0.75rem;color:var(--color-gray-400);">
            <i class="fa-solid fa-info-circle"></i> Les soldes USD et CDF ne sont jamais additionnés entre eux.
        </span>
    </div>
    <div class="table-responsive">
        <table class="table payment-register-table">
            <thead>
                <tr>
                    <th>Élève</th>
                    <th>Classe</th>
                    <th>Créances ouvertes</th>
                    <th>Solde USD</th>
                    <th>Solde CDF</th>
                    <th>Priorité / échéance</th>
                    <th>Prochaine relance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tbodyImpayes">
                <tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Chargement...</p></div></td></tr>
            </tbody>
        </table>
    </div>

    <div class="modal-overlay" id="modalSuiviFinancier">
        <div class="modal-content" style="max-width:620px;">
            <div class="modal-header">
                <h5><i class="fa-solid fa-phone-volume"></i> Enregistrer un suivi parent</h5>
                <button type="button" class="modal-close" onclick="fermerSuiviFinancier()">&times;</button>
            </div>
            <form id="formSuiviFinancier">
                <div class="modal-body">
                    <input type="hidden" name="eleve_id" id="suiviEleveId">
                    <input type="hidden" name="annee_id" value="<?= $anneeId ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="suiviType" class="form-label">Action réalisée / prévue</label>
                            <select id="suiviType" name="type_suivi" class="form-control" required>
                                <option value="appel">Appel téléphonique</option>
                                <option value="sms">SMS / message</option>
                                <option value="rendez-vous">Rendez-vous</option>
                                <option value="renvoi">Renvoi de l’élève</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="suiviStatut" class="form-label">État</label>
                            <select id="suiviStatut" name="statut" class="form-control" required>
                                <option value="effectué">Effectué</option>
                                <option value="à_faire">À faire / relancer</option>
                                <option value="sans_suite">Sans suite</option>
                            </select>
                        </div>
                        <div class="pagination-wrapper no-print">
                            <div class="pagination-info" id="paginationInfoImpayes"></div>
                            <div class="pagination" id="paginationImpayes"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="suiviContact" class="form-label">Parent / contact joint</label>
                            <input type="text" id="suiviContact" name="contact_parent" class="form-control" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label for="suiviRelance" class="form-label">Prochaine relance</label>
                            <input type="date" id="suiviRelance" name="date_relance" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="suiviMotif" class="form-label">Motif / solde discuté</label>
                        <input type="text" id="suiviMotif" name="motif" class="form-control" maxlength="500" required>
                    </div>
                    <div class="form-group">
                        <label for="suiviSuite" class="form-label">Réponse du parent et suite convenue</label>
                        <textarea id="suiviSuite" name="suite_donnee" class="form-control" rows="3"></textarea>
                    </div>
                    <small class="form-help">Ce suivi sert à la coordination comptable. Il ne bloque pas automatiquement l’appel ni les évaluations.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" onclick="fermerSuiviFinancier()">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Enregistrer le suivi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function getFiltres() {
    return {
        classe_id: document.getElementById('filterClasse')?.value || '',
        recherche: document.getElementById('filterRecherche')?.value || '',
        echeance: document.getElementById('filterEcheance')?.value || '',
        annee_id: '<?= $anneeId ?>'
    };
}

function appliquerFiltres() { chargerImpayes(1); }

function reinitialiserFiltres() {
    document.getElementById('filterClasse').value = '';
    document.getElementById('filterRecherche').value = '';
    document.getElementById('filterEcheance').value = '';
    chargerImpayes(1);
}

function chargerImpayes(page) {
    const formData = new FormData();
    formData.append('action', 'getImpayes');
    formData.append('page', page || 1);

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
        if (data.success) afficherImpayes(data);
        else showToast(data.message || 'Impossible de charger les impayés.', 'error');
    });
}

function afficherImpayes(data) {
    const tbody = document.getElementById('tbodyImpayes');
    document.getElementById('totalImpayes').textContent = data.total;

    if (!data.impayes || data.impayes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-check-circle" style="color:#10b981;"></i><p>Aucune créance ouverte pour cette année scolaire.</p></div></td></tr>';
        document.getElementById('paginationImpayes').innerHTML = '';
        document.getElementById('paginationInfoImpayes').textContent = '';
        return;
    }

    tbody.innerHTML = data.impayes.map(el => {
        const format = (amount, currency) => `${(Number(amount) || 0).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${currency}`;
        const echeance = el.plus_ancienne_echeance ? el.plus_ancienne_echeance.split('-').reverse().join('/') : '—';
        const enRetard = Number(el.nombre_en_retard) > 0;

        return `
        <tr>
            <td><span class="badge badge-primary">${escapeHtml(el.matricule)}</span><br><strong>${escapeHtml(el.prenom + ' ' + el.nom)}</strong></td>
            <td>${escapeHtml(el.classe_nom)}</td>
            <td>${Number(el.nombre_creances)} ouverte(s)</td>
            <td><strong class="text-danger">${format(el.solde_usd, 'USD')}</strong></td>
            <td><strong class="text-danger">${format(el.solde_cdf, 'CDF')}</strong></td>
            <td><span class="badge ${enRetard ? 'badge-danger' : 'badge-info'}">${enRetard ? `${Number(el.nombre_en_retard)} en retard` : 'À échoir'}</span><br><small>${echeance}</small></td>
            <td>${el.prochaine_relance ? el.prochaine_relance.split('-').reverse().join('/') : '—'}</td>
            <td>
                <a href="${(window.APP_BASE_PATH || '')}/paiements/enregistrer?eleve_id=${encodeURIComponent(el.id)}&annee_id=<?= $anneeId ?>" class="btn btn-sm btn-primary" title="Enregistrer un paiement">
                    <i class="fa-solid fa-money-bill"></i> Payer
                </a>
                <button type="button" class="btn btn-sm btn-outline-warning ms-1 btn-suivi-financier"
                        data-eleve-id="${Number(el.id)}" data-eleve-nom="${escapeHtml(el.prenom + ' ' + el.nom)}" title="Enregistrer un appel ou un suivi">
                    <i class="fa-solid fa-phone"></i>
                </button>
                <a href="${(window.APP_BASE_PATH || '')}/eleves/fiche/${encodeURIComponent(el.id)}?onglet=paiements&annee_id=<?= $anneeId ?>" class="btn btn-sm btn-outline-primary ms-1" title="Historique financier de l’élève">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </a>
            </td>
        </tr>`;
    }).join('');

    let paginationHtml = '';
    if (data.pages > 1) {
        const start = Math.max(1, data.page - 2);
        const end = Math.min(data.pages, data.page + 2);
        if (start > 1) paginationHtml += `<a href="#" onclick="chargerImpayes(1); return false;">1</a><span>…</span>`;
        for (let i = start; i <= end; i++) {
            paginationHtml += `<a href="#" onclick="chargerImpayes(${i}); return false;" class="${i === data.page ? 'active' : ''}">${i}</a>`;
        }
        if (end < data.pages) paginationHtml += `<span>…</span><a href="#" onclick="chargerImpayes(${data.pages}); return false;">${data.pages}</a>`;
    }
    document.getElementById('paginationImpayes').innerHTML = paginationHtml;
    document.getElementById('paginationInfoImpayes').textContent = `Page ${data.page} / ${data.pages} — ${data.total} élève(s)`;
}

function fermerSuiviFinancier() {
    document.getElementById('modalSuiviFinancier').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('click', event => {
    const button = event.target.closest('.btn-suivi-financier');
    if (button) {
        document.getElementById('suiviEleveId').value = button.dataset.eleveId;
        document.getElementById('suiviMotif').value = `Suivi de solde — ${button.dataset.eleveNom}`;
        document.getElementById('modalSuiviFinancier').classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    if (event.target.id === 'modalSuiviFinancier') fermerSuiviFinancier();
});

document.getElementById('formSuiviFinancier').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const formData = new FormData(form);
    formData.append('action', 'enregistrerSuiviFinancier');
    try {
        const response = await fetch((window.APP_BASE_PATH || '') + '/paiements', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Échec de l’enregistrement.');
        showToast(result.message, 'success');
        fermerSuiviFinancier();
        form.reset();
        chargerImpayes();
    } catch (error) {
        showToast(error.message || 'Impossible d’enregistrer ce suivi.', 'error');
    }
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => chargerImpayes(1));
</script>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>