/**
 * Fichier : public/assets/js/modules/sauvegardes.js
 * Rôle    : Scripts de la page des sauvegardes
 * Système : École primaire congolaise — Administration
 * Version : 1.0
 */

'use strict';

// ============================================================
// VARIABLES GLOBALES
// ============================================================
var sauvegardeEnCours = false;
var restaurationEnCours = false;

// ============================================================
// INITIALISATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    chargerSauvegardes(1);
    initBoutonSauvegarde();
    initRaccourcisClavier();
    document.addEventListener('click', function(event) {
        var button = event.target.closest('[data-backup-action]');
        if (!button) return;
        var id = Number(button.dataset.id);
        var name = button.dataset.name || '';
        if (button.dataset.backupAction === 'restore') restaurerSauvegarde(id, name);
        if (button.dataset.backupAction === 'delete') supprimerSauvegarde(id, name);
    });
});

function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

// ============================================================
// 1. CHARGEMENT DES SAUVEGARDES
// ============================================================
function chargerSauvegardes(page) {
    var tbody = document.getElementById('tbodySauvegardes');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state" style="padding:30px;"><i class="fa-solid fa-spinner fa-spin"></i><p>Chargement des sauvegardes...</p></div></td></tr>';

    var formData = new FormData();
    formData.append('action', 'getListe');
    formData.append('page', page || 1);

    fetch(window.APP_BASE_PATH + "/sauvegardes", {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken()
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            afficherSauvegardes(data);
        } else {
            showToast(data.message || 'Erreur de chargement.', 'error');
        }
    })
    .catch(function() {
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state" style="padding:30px;"><i class="fa-solid fa-circle-exclamation"></i><p>Erreur de connexion au serveur.</p></div></td></tr>';
    });
}

// ============================================================
// 2. AFFICHAGE DES SAUVEGARDES
// ============================================================
function afficherSauvegardes(data) {
    var tbody = document.getElementById('tbodySauvegardes');
    var totalSpan = document.getElementById('totalSauvegardes');
    var paginationDiv = document.getElementById('paginationSauvegardes');
    var paginationInfo = document.getElementById('paginationInfo');

    if (!tbody) return;

    if (totalSpan) totalSpan.textContent = data.total;

    if (!data.sauvegardes || data.sauvegardes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state" style="padding:40px;"><i class="fa-solid fa-database"></i><p>Aucune sauvegarde trouvée.</p><p style="font-size:0.75rem;color:#94a3b8;">Cliquez sur "Sauvegarder maintenant" pour créer votre première sauvegarde.</p></div></td></tr>';
        if (paginationDiv) paginationDiv.innerHTML = '';
        if (paginationInfo) paginationInfo.textContent = '';
        return;
    }

    tbody.innerHTML = data.sauvegardes.map(function(s) {
        var typeBadge = s.type_sauvegarde === 'Manuelle'
            ? '<span class="badge badge-primary"><i class="fa-solid fa-hand-pointer"></i> Manuelle</span>'
            : '<span class="badge badge-info"><i class="fa-solid fa-robot"></i> Automatique</span>';
        var statutBadge = s.statut === 'Échec'
            ? '<span class="badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> Échec</span>'
            : (s.fichier_existe
                ? '<span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> Disponible</span>'
                : '<span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Fichier manquant</span>');
        var permissions = window.SAUVEGARDE_PERMISSIONS || {};
        var actions = '';
        if (s.fichier_existe && s.statut === 'Succès') {
            actions += '<a href="' + (window.APP_BASE_PATH || '') + '/sauvegardes/telecharger/' + Number(s.id) + '" class="btn btn-sm btn-outline-success" title="Télécharger cette sauvegarde"><i class="fa-solid fa-download"></i></a>';
            if (permissions.restaurer) {
                actions += '<button type="button" class="btn btn-sm btn-outline-warning" data-backup-action="restore" data-id="' + Number(s.id) + '" data-name="' + escapeHtml(s.nom_fichier) + '" title="Restaurer cette sauvegarde"><i class="fa-solid fa-rotate-left"></i></button>';
            }
        }
        if (permissions.supprimer) {
            actions += '<button type="button" class="btn btn-sm btn-outline-danger" data-backup-action="delete" data-id="' + Number(s.id) + '" data-name="' + escapeHtml(s.nom_fichier) + '" title="Supprimer cette sauvegarde"><i class="fa-solid fa-trash"></i></button>';
        }

        return `
        <tr>
            <td><i class="fa-solid fa-file-zipper" style="font-size:1.3rem;color:#2c3e90;"></i></td>
            <td><strong>${escapeHtml(s.nom_fichier)}</strong></td>
            <td>${escapeHtml(s.taille_formatee)}</td>
            <td>${typeBadge}</td>
            <td>${escapeHtml(s.date_format)} <small class="text-muted">${escapeHtml(s.heure_format)}</small></td>
            <td><small>${escapeHtml(s.auteur_nom || '—')}</small></td>
            <td>${statutBadge}</td>
            <td>
                <div class="d-flex gap-xs">
                    ${actions}
                </div>
            </td>
        </tr>`;
    }).join('');

    // Pagination
    if (data.pages > 1) {
        var html = '';
        for (var i = 1; i <= data.pages; i++) {
            html += '<a href="#" onclick="chargerSauvegardes(' + i + '); return false;" class="' + (i === data.page ? 'active' : '') + '">' + i + '</a>';
        }
        if (paginationDiv) paginationDiv.innerHTML = html;
        if (paginationInfo) paginationInfo.textContent = 'Page ' + data.page + ' / ' + data.pages + ' — ' + data.total + ' sauvegarde(s)';
    } else {
        if (paginationDiv) paginationDiv.innerHTML = '';
        if (paginationInfo) paginationInfo.textContent = '';
    }
}

// ============================================================
// 3. BOUTON SAUVEGARDER
// ============================================================
function initBoutonSauvegarde() {
    var btn = document.getElementById('btnSauvegarder');
    if (!btn) return;

    btn.addEventListener('click', function() {
        if (sauvegardeEnCours) return;
        sauvegardeEnCours = true;

        this.disabled = true;
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sauvegarde en cours...';

        var formData = new FormData();
        formData.append('action', 'sauvegarder');

        fetch(window.APP_BASE_PATH + "/sauvegardes", {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken()
            }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message + ' (' + data.taille + ')', 'success');
                chargerSauvegardes(1);
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showToast(data.message || 'Erreur lors de la sauvegarde.', 'error');
                sauvegardeEnCours = false;
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Sauvegarder maintenant';
            }
        })
        .catch(function() {
            showToast('Erreur de connexion au serveur.', 'error');
            sauvegardeEnCours = false;
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Sauvegarder maintenant';
        });
    });
}

// ============================================================
// 4. SUPPRIMER UNE SAUVEGARDE
// ============================================================
function supprimerSauvegarde(id, nom) {
    showConfirmDialog(
        'Voulez-vous supprimer la sauvegarde <strong>' + escapeHtml(nom) + '</strong> ?<br><small>Cette action est irréversible. Le fichier sera définitivement supprimé du serveur.</small>',
        function(confirmed) {
            if (confirmed) {
                var formData = new FormData();
                formData.append('action', 'supprimer');
                formData.append('sauvegarde_id', id);

                fetch(window.APP_BASE_PATH + "/sauvegardes", {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken()
                    }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        chargerSauvegardes(1);
                    } else {
                        showToast(data.message || 'Erreur lors de la suppression.', 'error');
                    }
                })
                .catch(function() {
                    showToast('Erreur de connexion.', 'error');
                });
            }
        },
        {
            title: 'Supprimer la sauvegarde',
            confirmText: 'Supprimer',
            confirmClass: 'btn-danger',
            icon: 'fa-trash',
            iconColor: '#ef4444'
        }
    );
}

// ============================================================
// 5. RESTAURER UNE SAUVEGARDE
// ============================================================
function restaurerSauvegarde(id, nom) {
    if (restaurationEnCours) return;

    showConfirmDialog(
        '<div style="text-align:left;">' +
        '<div class="restauration-warning" style="background:#fef2f2;border:2px solid #ef4444;border-radius:12px;padding:16px;margin-bottom:14px;">' +
        '<div style="display:flex;align-items:center;gap:8px;font-weight:700;color:#991b1b;font-size:0.9rem;margin-bottom:8px;">' +
        '<i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:1.2rem;"></i> ATTENTION : Cette action est irréversible !</div>' +
        '<div style="font-size:0.82rem;color:#b91c1c;line-height:1.6;">' +
        'Vous allez restaurer la sauvegarde <strong>' + escapeHtml(nom) + '</strong>.<br>' +
        'Toutes les données actuelles seront remplacées par celles de la sauvegarde.</div>' +
        '</div>' +
        '<p style="font-size:0.82rem;color:#64748b;margin-bottom:0;">' +
        '<i class="fa-solid fa-info-circle"></i> Une sauvegarde de sécurité sera automatiquement créée avant la restauration.</p>' +
        '</div>',
        function(confirmed) {
            if (confirmed) {
                restaurationEnCours = true;

                var formData = new FormData();
                formData.append('action', 'restaurer');
                formData.append('sauvegarde_id', id);

                var btn = document.getElementById('btnSauvegarder');
                if (btn) btn.disabled = true;

                showToast('Restauration en cours... Veuillez patienter.', 'info', 30000);

                fetch(window.APP_BASE_PATH + "/sauvegardes", {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken()
                    }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(function() {
                            window.location.href = (window.APP_BASE_PATH || '') + '/login';
                        }, 1500);
                    } else {
                        showToast(data.message || 'Erreur lors de la restauration.', 'error');
                        restaurationEnCours = false;
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(function() {
                    showToast('Erreur de connexion au serveur.', 'error');
                    restaurationEnCours = false;
                    if (btn) btn.disabled = false;
                });
            }
        },
        {
            title: '⚠️ Restaurer la base de données',
            confirmText: 'Je comprends, restaurer',
            confirmClass: 'btn-warning',
            cancelText: 'Annuler',
            icon: 'fa-triangle-exclamation',
            iconColor: '#ef4444'
        }
    );
}

// ============================================================
// 6. RACCOURCIS CLAVIER
// ============================================================
function initRaccourcisClavier() {
    document.addEventListener('keydown', function(e) {
        // Ctrl+B : Sauvegarder
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            var btn = document.getElementById('btnSauvegarder');
            if (btn && !btn.disabled) {
                btn.click();
            }
        }
    });
}

// ============================================================
// 7. UTILITAIRES
// ============================================================
function escapeHtml(texte) {
    if (!texte) return '';
    var div = document.createElement('div');
    div.textContent = texte;
    return div.innerHTML;
}