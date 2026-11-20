/* ============================================================
   Fichier : public/assets/js/modules/bulletins.js
   Rôle    : Gestion des bulletins — Génération, consultation, impression
   Système : Bulletin congolais
   Version : 2.0 (Optimisé)
   ============================================================ */

'use strict';

document.addEventListener('DOMContentLoaded', () => {

    /**
     * Traite la réponse HTTP de manière sécurisée
     */
    const handleHttpResponse = async (response) => {
        if (!response.ok) {
            throw new Error(`Erreur serveur (${response.status})`);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Réponse invalide du serveur (Format non-JSON)');
        }
        return await response.json();
    };

    // ============================================================
    // 1. BOUTON GÉNÉRER LES BULLETINS
    // ============================================================
    const btnGenerer = document.getElementById('btnGenererBulletins');

    if (btnGenerer) {
        btnGenerer.addEventListener('click', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const classeId = urlParams.get('classe_id') || this.dataset.classeId;
            const trimestreId = urlParams.get('trimestre_id') || this.dataset.trimestreId;
            const anneeId = urlParams.get('annee_id') || this.dataset.anneeId || '';

            if (!classeId || !trimestreId) {
                if (typeof showToast === 'function') {
                    showToast('Veuillez sélectionner une classe et un trimestre.', 'warning');
                } else {
                    alert('Veuillez sélectionner une classe et un trimestre.');
                }
                return;
            }

            const executeGeneration = () => {
                const originalHtml = btnGenerer.innerHTML;
                btnGenerer.disabled = true;
                btnGenerer.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Génération en cours...';

                const formData = new FormData();
                formData.append('action', 'genererBulletinsClasse');
                formData.append('classe_id', classeId);
                formData.append('trimestre_id', trimestreId);
                formData.append('annee_id', anneeId);

                fetch(window.APP_BASE_PATH + "/bulletins", {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(handleHttpResponse)
                .then(data => {
                    if (data.success) {
                        if (typeof showToast === 'function') {
                            showToast(data.message || 'Bulletins générés avec succès.', 'success');
                        }
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        if (typeof showToast === 'function') {
                            showToast(data.message || 'Une erreur est survenue lors de la génération.', 'error');
                        }
                        btnGenerer.disabled = false;
                        btnGenerer.innerHTML = originalHtml;
                    }
                })
                .catch(err => {
                    console.error('Erreur lors de la génération :', err);
                    if (typeof showToast === 'function') {
                        showToast(err.message || 'Erreur de connexion au serveur.', 'error');
                    }
                    btnGenerer.disabled = false;
                    btnGenerer.innerHTML = originalHtml;
                });
            };

            if (typeof showConfirmDialog === 'function') {
                showConfirmDialog(
                    'Voulez-vous générer les bulletins pour cette classe et ce trimestre ?<br><small class="text-muted">Les bulletins existants seront remplacés.</small>',
                    function (confirmed) {
                        if (confirmed) executeGeneration();
                    },
                    {
                        title: 'Générer les bulletins',
                        confirmText: 'Générer',
                        confirmClass: 'btn-success',
                        icon: 'fa-file-invoice',
                        iconColor: '#10b981'
                    }
                );
            } else if (confirm('Voulez-vous générer les bulletins pour cette classe et ce trimestre ?')) {
                executeGeneration();
            }
        });
    }

    // ============================================================
    // 2. BOUTON RÉGÉNÉRER LES BULLETINS
    // ============================================================
    const btnRegenerer = document.getElementById('btnRegenererBulletins');

    if (btnRegenerer) {
        btnRegenerer.addEventListener('click', function () {
            if (!btnGenerer) {
                console.warn('Bouton de génération principal indisponible pour la régénération.');
                return;
            }

            const triggerRegenerate = () => btnGenerer.click();

            if (typeof showConfirmDialog === 'function') {
                showConfirmDialog(
                    'Voulez-vous régénérer tous les bulletins ?<br><small class="text-muted">Tous les bulletins existants seront remplacés.</small>',
                    function (confirmed) {
                        if (confirmed) triggerRegenerate();
                    },
                    {
                        title: 'Régénérer les bulletins',
                        confirmText: 'Régénérer',
                        confirmClass: 'btn-warning',
                        icon: 'fa-rotate',
                        iconColor: '#f59e0b'
                    }
                );
            } else if (confirm('Voulez-vous régénérer tous les bulletins ?')) {
                triggerRegenerate();
            }
        });
    }

    // ============================================================
    // 3. IMPRIMER UN BULLETIN SPÉCIFIQUE
    // ============================================================
    window.imprimerBulletin = function (eleveId) {
        if (!eleveId) {
            if (typeof showToast === 'function') {
                showToast('Identifiant élève invalide.', 'warning');
            }
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const anneeId = urlParams.get('annee_id') || '';
        const url = `${window.APP_BASE_PATH/bulletins/consulter?eleve_id=${encodeURIComponent(eleveId)}&annee_id=${encodeURIComponent(anneeId)}}`;
        
        window.open(url, '_blank', 'noopener,noreferrer');
    };

    // ============================================================
    // 4. CHARGER LES ÉLÈVES POUR L'IMPRESSION
    // ============================================================
    window.loadElevesForPrint = function (classeId) {
        const select = document.getElementById('selectElevePrint');
        if (!select) return;

        select.innerHTML = '<option value="">— Élève —</option>';

        if (!classeId) return;

        select.disabled = true;

        const formData = new FormData();
        formData.append('action', 'getBulletinsClasse');
        formData.append('classe_id', classeId);

        fetch(window.APP_BASE_PATH + "/bulletins", {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(handleHttpResponse)
        .then(data => {
            if (data.success && Array.isArray(data.bulletins)) {
                const seen = new Set();
                const fragment = document.createDocumentFragment();

                data.bulletins.forEach(b => {
                    if (b.eleve_id && !seen.has(b.eleve_id)) {
                        seen.add(b.eleve_id);
                        const option = document.createElement('option');
                        option.value = b.eleve_id;
                        
                        const prenom = b.prenom || '';
                        const nom = b.nom || '';
                        const matricule = b.matricule ? ` — ${b.matricule}` : '';
                        
                        option.textContent = `${prenom} ${nom}${matricule}`.trim();
                        fragment.appendChild(option);
                    }
                });

                select.appendChild(fragment);
            } else if (typeof showToast === 'function') {
                showToast(data.message || 'Impossible de récupérer la liste des élèves.', 'warning');
            }
        })
        .catch(err => {
            console.error('Erreur chargement élèves :', err);
            if (typeof showToast === 'function') {
                showToast('Erreur lors du chargement des élèves.', 'error');
            }
        })
        .finally(() => {
            select.disabled = false;
        });
    };

    console.log('%cBulletins%c initialisé avec succès.',
        'color: #2c3e90; font-weight: 700;', 'color: #64748b;');
});