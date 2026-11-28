<?php
/**
 * Fichier : public/views/modules/frais/liste.php
 * Rôle    : Configuration des frais scolaires
 * Système : École primaire congolaise — Gestion financière
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('paiements.consulter');

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PaiementController.php';
$paiementController = new PaiementController();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $paiementController->handleAjax();
}

// Frais et créances restent rattachés à une année scolaire déterminée.
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PaymentReadController.php';
$paymentReadController = new PaymentReadController();
extract($paymentReadController->getViewData('frais', $_GET), EXTR_OVERWRITE);

$pageTitle = 'Configuration des frais scolaires';
$pageIcon  = 'fa-tags';
$pageDescription = 'Gérez les types de frais et leurs montants';
$extraCSS = ['modules/paiements.css'];
$extraJS  = ['modules/paiements.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements">Paiements</a>
    <span class="separator">/</span>
    <span class="current">Frais scolaires</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-tags"></i> Configuration des frais scolaires</h4>
        <p>Gérez les types de frais, leurs montants et leur obligation.</p>
    </div>
    <div class="resultats-header-right">
        <form method="get" class="d-flex gap-sm align-items-center">
            <label for="anneeFinance" class="mb-0">Année :</label>
            <select id="anneeFinance" name="annee_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($annees as $annee): ?>
                    <option value="<?= (int) $annee['id'] ?>" <?= $anneeId === (int) $annee['id'] ? 'selected' : '' ?>>
                        <?= e($annee['libelle']) ?><?= $annee['est_cloturee'] ? ' — clôturée' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button type="button" class="btn btn-primary btn-sm" id="btnAjouterFrais">
            <i class="fa-solid fa-plus"></i> Nouveau frais
        </button>
        <button type="button" class="btn btn-outline-success btn-sm" id="btnGenererCreances" data-annee="<?= $anneeId ?>">
            <i class="fa-solid fa-users"></i> Générer les créances
        </button>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-list"></i> Paiements
        </a>
    </div>
</div>

<div id="flashMessage"></div>

<!-- ============================================================
     STATISTIQUES
     ============================================================ -->
<div class="retards-stats mb-4">
    <div class="retard-stat-card">
        <div class="retard-stat-icon blue"><i class="fa-solid fa-tags"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= $totalFrais ?></div>
            <div class="retard-stat-label">Frais configurés</div>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon green"><i class="fa-solid fa-check-circle"></i></div>
        <div class="retard-stat-content">
            <?php $actifs = count(array_filter($frais, fn($f) => $f['est_actif'])); ?>
            <div class="retard-stat-value"><?= $actifs ?></div>
            <div class="retard-stat-label">Frais actifs</div>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon orange"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">$ <?= number_format($totalEncaisseUsd, 2, ',', ' ') ?></div>
            <div class="retard-stat-label">Encaissé en USD</div>
            <span class="stat-change neutral">FC <?= number_format($totalEncaisseCdf, 0, ',', ' ') ?> encaissés</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon purple"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="retard-stat-content">
            <?php $obligatoires = count(array_filter($frais, fn($f) => $f['est_obligatoire'])); ?>
            <div class="retard-stat-value"><?= $obligatoires ?></div>
            <div class="retard-stat-label">Frais obligatoires</div>
        </div>
    </div>
</div>

<!-- ============================================================
     TABLEAU DES FRAIS
     ============================================================ -->
<div class="retards-table-card">
    <div class="table-header-info" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color-light);">
        <span><strong><?= $totalFrais ?></strong> frais trouvé(s)</span>
        <span style="font-size:0.75rem;color:var(--color-gray-400);">
            <i class="fa-solid fa-info-circle"></i> Les frais désactivés n'apparaissent plus dans les formulaires de paiement
        </span>
    </div>
    <div class="table-responsive">
        <table class="table finance-fees-table">
            <thead>
                <tr>
                    <th>Libellé</th>
                    <th>Catégorie</th>
                    <th>Description</th>
                    <th>Montant</th>
                    <th>Échéancier</th>
                    <th>Classe</th>
                    <th>Obligatoire</th>
                    <th>Statut</th>
                    <th>Élèves facturés</th>
                    <th>Encaissé USD / CDF</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($frais)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state" style="padding:40px;">
                                <i class="fa-solid fa-tags"></i>
                                <p>Aucun frais configuré pour cette année scolaire.</p>
                                <button type="button" class="btn btn-primary btn-sm mt-3" id="btnAjouterFraisBis">
                                    <i class="fa-solid fa-plus"></i> Créer un frais
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($frais as $fraisItem): ?>
                        <tr class="<?= !$fraisItem['est_actif'] ? 'ligne-inactive' : '' ?>">
                            <td><strong><?= e($fraisItem['libelle']) ?></strong></td>
                            <td><?= e($fraisItem['categorie']) ?></td>
                            <td><small><?= e($fraisItem['description'] ?: '—') ?></small></td>
                            <td><strong style="color:#065f46;"><?= $fraisItem['devise'] === 'USD' ? '$' : 'FC' ?> <?= number_format($fraisItem['montant'], 2, ',', ' ') ?></strong></td>
                            <td><?= e(ucfirst($fraisItem['periodicite'])) ?> · échéance le <?= (int) $fraisItem['jour_echeance'] ?></td>
                            <td>
                                <?php if ($fraisItem['classe_id']): 
                                    $classeFrais = ['nom' => $paymentReadController->getClassName((int) $fraisItem['classe_id'])];
                                    echo e($classeFrais['nom'] ?? '—');
                                else: ?>
                                    <span class="badge badge-info">Toutes les classes</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($fraisItem['est_obligatoire']): ?>
                                    <span class="badge badge-warning"><i class="fa-solid fa-check"></i> Obligatoire</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Optionnel</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($fraisItem['est_actif']): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-circle"></i> Actif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?= (int) $fraisItem['nb_eleves'] ?> élève(s)</span>
                            </td>
                            <td>
                                <strong>$ <?= number_format($fraisItem['encaisse_usd'], 2, ',', ' ') ?></strong>
                                <br><small>FC <?= number_format($fraisItem['encaisse_cdf'], 0, ',', ' ') ?></small>
                            </td>
                            <td>
                                <div class="d-flex gap-xs">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-modifier-frais"
                                            data-id="<?= $fraisItem['id'] ?>"
                                            data-libelle="<?= e($fraisItem['libelle']) ?>"
                                            data-description="<?= e($fraisItem['description'] ?? '') ?>"
                                            data-montant="<?= $fraisItem['montant'] ?>"
                                            data-classe="<?= $fraisItem['classe_id'] ?? '' ?>"
                                            data-obligatoire="<?= $fraisItem['est_obligatoire'] ?>"
                                            data-categorie="<?= e($fraisItem['categorie']) ?>"
                                            data-devise="<?= e($fraisItem['devise']) ?>"
                                            data-periodicite="<?= e($fraisItem['periodicite']) ?>"
                                            data-jour="<?= (int) $fraisItem['jour_echeance'] ?>"
                                            title="Modifier">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-info btn-toggle-frais"
                                            data-id="<?= $fraisItem['id'] ?>"
                                            data-statut="<?= $fraisItem['est_actif'] ?>"
                                            title="<?= $fraisItem['est_actif'] ? 'Désactiver' : 'Activer' ?>">
                                        <i class="fa-solid fa-<?= $fraisItem['est_actif'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                    </button>
                                    <?php if ($fraisItem['nb_creances'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-supprimer-frais"
                                            data-id="<?= $fraisItem['id'] ?>"
                                            data-libelle="<?= e($fraisItem['libelle']) ?>"
                                            title="Supprimer">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================
     MODALE : AJOUTER/MODIFIER UN FRAIS
     ============================================================ -->
<div class="modal-overlay" id="modalFrais">
    <div class="modal-content" style="max-width:550px;">
        <div class="modal-header">
            <h5><i class="fa-solid fa-tag"></i> <span id="modalFraisTitre">Ajouter un frais</span></h5>
            <button type="button" class="modal-close" id="btnCloseFrais">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formFrais" autocomplete="off">
                <input type="hidden" id="frais_id" name="frais_id" value="">
                <input type="hidden" name="annee_scolaire_id" value="<?= $anneeId ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="frais_libelle" class="form-label">
                            <i class="fa-solid fa-tag"></i> Libellé <span class="required">*</span>
                        </label>
                        <input type="text" id="frais_libelle" name="libelle" class="form-control"
                               placeholder="Ex. Minerval, inscription, bulletin..." required maxlength="150">
                    </div>
                    <div class="form-group">
                        <label for="frais_categorie" class="form-label">Catégorie</label>
                        <input type="text" id="frais_categorie" name="categorie" class="form-control"
                               placeholder="Scolarité, inscription, fournitures..." required maxlength="80">
                    </div>
                </div>

                <div class="form-group">
                    <label for="frais_description" class="form-label">
                        <i class="fa-solid fa-align-left"></i> Description
                    </label>
                    <textarea id="frais_description" name="description" class="form-control" rows="2" 
                              placeholder="Description du frais..."></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="frais_montant" class="form-label">
                            <i class="fa-solid fa-money-bill"></i> Montant attendu <span class="required">*</span>
                        </label>
                        <div class="input-icon-wrapper">
                            <input type="number" id="frais_montant" name="montant" class="form-control" 
                                   placeholder="0,00" min="0.01" step="0.01" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="frais_devise" class="form-label">Devise du frais</label>
                        <select id="frais_devise" name="devise" class="form-control" required>
                            <option value="USD">Dollar américain (USD)</option>
                            <option value="CDF">Franc congolais (CDF)</option>
                        </select>
                        <small id="fraisDeviseHelp" class="form-help">La devise s’applique à cette catégorie de frais.</small>
                    </div>
                    <div class="form-group">
                        <label for="frais_classe" class="form-label">
                            <i class="fa-solid fa-chalkboard"></i> Classe
                        </label>
                        <select id="frais_classe" name="classe_id" class="form-control">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= e($cl['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-help">Laissez vide pour appliquer à toutes les classes.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="frais_periodicite" class="form-label">Périodicité</label>
                        <select id="frais_periodicite" name="periodicite" class="form-control" required>
                            <option value="unique">Unique (une seule fois)</option>
                            <option value="annuel" selected>Annuel (payable en tranches)</option>
                            <option value="trimestriel">Trimestriel (3 créances)</option>
                            <option value="mensuel">Mensuel</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="frais_jour_echeance" class="form-label">Jour limite de paiement</label>
                        <input type="number" id="frais_jour_echeance" name="jour_echeance" class="form-control"
                               min="1" max="31" value="10" required>
                        <small class="form-help">Le dernier jour du mois est retenu si nécessaire.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" id="frais_obligatoire" name="est_obligatoire" value="1" checked>
                        <span class="checkbox-custom"><i class="fa-solid fa-check"></i></span>
                        <span class="checkbox-label">Frais obligatoire</span>
                    </label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary" id="btnCancelFrais">
                <i class="fa-solid fa-xmark"></i> Annuler
            </button>
            <button type="button" class="btn btn-primary" id="btnSaveFrais">
                <i class="fa-solid fa-save"></i> Enregistrer
            </button>
        </div>
    </div>
</div>

<script>
// ============================================================
// MODALE FRAIS
// ============================================================
const modalFrais = document.getElementById('modalFrais');
const modalFraisTitre = document.getElementById('modalFraisTitre');
const formFrais = document.getElementById('formFrais');
const fraisCategorie = document.getElementById('frais_categorie');
const fraisDevise = document.getElementById('frais_devise');
const fraisDeviseHelp = document.getElementById('fraisDeviseHelp');

function synchroniserDeviseFrais() {
    const categorie = fraisCategorie.value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLowerCase();
    const scolarite = /^(scolarite|minerval)(?:\s|$)/.test(categorie)
        || ['frais scolaire', 'frais scolaires', 'frais de scolarite'].includes(categorie);
    if (scolarite) {
        fraisDevise.value = 'USD';
    }
    fraisDevise.disabled = scolarite;
    fraisDeviseHelp.textContent = scolarite
        ? 'La scolarité est toujours due et comptabilisée en USD. Les paiements en CDF sont convertis au taux configuré dans Paramètres.'
        : 'La devise s’applique à cette catégorie de frais.';
}

fraisCategorie.addEventListener('input', synchroniserDeviseFrais);

function openModalFrais() { modalFrais.classList.add('show'); document.body.style.overflow = 'hidden'; }
function closeModalFrais() { modalFrais.classList.remove('show'); document.body.style.overflow = ''; formFrais.reset(); }

document.getElementById('btnCloseFrais')?.addEventListener('click', closeModalFrais);
document.getElementById('btnCancelFrais')?.addEventListener('click', closeModalFrais);
modalFrais?.addEventListener('click', function(e) { if (e.target === modalFrais) closeModalFrais(); });

// Bouton Ajouter
document.getElementById('btnAjouterFrais')?.addEventListener('click', function() {
    modalFraisTitre.textContent = 'Ajouter un frais';
    document.getElementById('frais_id').value = '';
    formFrais.reset();
    document.getElementById('frais_obligatoire').checked = true;
    synchroniserDeviseFrais();
    openModalFrais();
});

document.getElementById('btnAjouterFraisBis')?.addEventListener('click', function() {
    document.getElementById('btnAjouterFrais').click();
});

// Boutons Modifier
document.querySelectorAll('.btn-modifier-frais').forEach(btn => {
    btn.addEventListener('click', function() {
        modalFraisTitre.textContent = 'Modifier le frais';
        document.getElementById('frais_id').value = this.dataset.id;
        document.getElementById('frais_libelle').value = this.dataset.libelle;
        document.getElementById('frais_description').value = this.dataset.description;
        document.getElementById('frais_montant').value = this.dataset.montant;
        document.getElementById('frais_classe').value = this.dataset.classe;
        document.getElementById('frais_obligatoire').checked = this.dataset.obligatoire === '1';
        document.getElementById('frais_categorie').value = this.dataset.categorie;
        document.getElementById('frais_devise').value = this.dataset.devise;
        document.getElementById('frais_periodicite').value = this.dataset.periodicite;
        document.getElementById('frais_jour_echeance').value = this.dataset.jour;
        synchroniserDeviseFrais();
        openModalFrais();
    });

});

document.getElementById('btnGenererCreances')?.addEventListener('click', async function() {
    const formData = new FormData();
    formData.append('action', 'genererCreancesAnnee');
    formData.append('annee_id', this.dataset.annee);
    this.disabled = true;
    try {
        const response = await fetch((window.APP_BASE_PATH || '') + '/paiements', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Échec de la génération.');
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 500);
    } catch (error) {
        showToast(error.message || 'Erreur pendant la génération des créances.', 'error');
    } finally {
        this.disabled = false;
    }
});

// Sauvegarder
document.getElementById('btnSaveFrais').addEventListener('click', async function() {
    if (!formFrais.reportValidity()) return;

    const formData = new FormData(formFrais);
    const fraisId = document.getElementById('frais_id').value;
    formData.append('action', fraisId ? 'modifierFrais' : 'ajouterFrais');

    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sauvegarde...';

    try {
        const res = await fetch((window.APP_BASE_PATH || '') + '/paiements', {
            method: 'POST', body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (res.ok && data.success) {
            showToast(data.message, 'success');
            closeModalFrais();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Impossible d’enregistrer ce frais.', 'error');
        }
    } catch (e) {
        showToast('Erreur de connexion.', 'error');
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="fa-solid fa-save"></i> Enregistrer';
    }
});

// Toggle statut
document.querySelectorAll('.btn-toggle-frais').forEach(btn => {
    btn.addEventListener('click', async function() {
        const fraisId = this.dataset.id;
        const formData = new FormData();
        formData.append('action', 'toggleStatutFrais');
        formData.append('frais_id', fraisId);

        try {
            const res = await fetch((window.APP_BASE_PATH || '') + '/paiements', {
                method: 'POST', body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 400);
            } else {
                showToast(data.message, 'error');
            }
        } catch (e) {
            showToast('Erreur de connexion.', 'error');
        }
    });
});

// Supprimer
document.querySelectorAll('.btn-supprimer-frais').forEach(btn => {
    btn.addEventListener('click', function() {
        const fraisId = this.dataset.id;
        const libelle = this.dataset.libelle;

        showConfirmDialog(
            'Voulez-vous supprimer le frais <strong>' + escapeHtml(libelle) + '</strong> ?',
            async function(confirmed) {
                if (confirmed) {
                    const formData = new FormData();
                    formData.append('action', 'supprimerFrais');
                    formData.append('frais_id', fraisId);

                    try {
                        const res = await fetch((window.APP_BASE_PATH || '') + '/paiements', {
                            method: 'POST', body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const data = await res.json();
                        if (data.success) {
                            showToast(data.message, 'success');
                            setTimeout(() => location.reload(), 400);
                        } else {
                            showToast(data.message, 'error');
                        }
                    } catch (e) {
                        showToast('Erreur de connexion.', 'error');
                    }
                }
            },
            { title: 'Supprimer le frais', confirmText: 'Supprimer', confirmClass: 'btn-danger', icon: 'fa-trash', iconColor: '#ef4444' }
        );
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}
</script>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>