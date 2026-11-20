'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('formPaiement');
    const basePath = window.APP_BASE_PATH || '';
    const endpoint = `${basePath}/paiements`;
    let searchTimeout;

    window.escapeHtml = function(value) {
        const element = document.createElement('span');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    };

    async function postAction(action, values) {
        const formData = values instanceof FormData ? values : new FormData();
        if (!(values instanceof FormData)) {
            Object.entries(values || {}).forEach(([key, value]) => formData.append(key, value));
        }
        formData.set('action', action);
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'La requête a échoué.');
        }
        return result;
    }

    function formatAmount(amount, currency) {
        const number = Number(amount) || 0;
        const formatted = number.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `${formatted} ${currency}`;
    }

    function formatPeriod(period) {
        if (period === 'ANNUEL') return 'Année scolaire entière';
        if (period === 'UNIQUE') return 'Paiement unique';
        return period;
    }

    function estFraisScolarite(categorie) {
        const normalized = String(categorie || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase();
        return /^(scolarite|minerval)(?:\s|$)/.test(normalized)
            || ['frais scolaire', 'frais scolaires', 'frais de scolarite'].includes(normalized);
    }

    function showToastSafely(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        } else {
            window.alert(message);
        }
    }

    window.contreEcrireVersement = async function(versementId) {
        const type = window.prompt('Tapez "annulation" ou "remboursement" pour la contre-écriture.');
        if (!['annulation', 'remboursement'].includes(type)) return;
        const motif = window.prompt('Motif obligatoire de cette opération comptable :');
        if (!motif || !motif.trim()) return;
        if (!window.confirm('Confirmer la contre-écriture ? Le reçu initial restera dans l’historique.')) return;

        try {
            const result = await postAction('annulerPaiement', {
                versement_id: String(versementId),
                type_correction: type,
                motif: motif.trim()
            });
            showToastSafely(result.message, 'success');
            window.location.reload();
        } catch (error) {
            showToastSafely(error.message || 'Impossible de contre-écrire ce versement.', 'error');
        }
    };

    function renderSearchResults(container, students) {
        container.replaceChildren();
        if (!students.length) {
            const empty = document.createElement('p');
            empty.className = 'search-empty';
            empty.textContent = 'Aucun élève trouvé pour cette année.';
            container.appendChild(empty);
        } else {
            students.forEach(student => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'resultat-eleve-item';
                button.innerHTML = `
                    <span class="resultat-eleve-avatar ${student.sexe === 'M' ? 'male' : 'female'}">
                        ${escapeHtml((student.prenom.charAt(0) + student.nom.charAt(0)).toUpperCase())}
                    </span>
                    <span class="resultat-eleve-info">
                        <strong>${escapeHtml(`${student.prenom} ${student.nom}`)}</strong>
                        <span>${escapeHtml(student.matricule)} — ${escapeHtml(student.classe_nom)}</span>
                    </span>`;
                button.addEventListener('click', () => {
                    window.selectionnerEleve(
                        student.id,
                        `${student.prenom} ${student.nom}`,
                        student.matricule,
                        student.classe_nom,
                        student.sexe
                    );
                });
                container.appendChild(button);
            });
        }
        container.classList.add('show');
    }

    async function rechercherEleves(input) {
        const container = document.getElementById('resultatsRecherche');
        if (!container) return;
        const query = input.value.trim();
        if (query.length < 2) {
            container.replaceChildren();
            container.classList.remove('show');
            return;
        }

        try {
            const result = await postAction('rechercherEleve', {
                recherche: query,
                annee_id: document.getElementById('annee_scolaire_id').value
            });
            renderSearchResults(container, result.eleves || []);
        } catch (error) {
            container.textContent = error.message || 'Erreur pendant la recherche des élèves.';
            container.classList.add('show');
        }
    }

    async function chargerHistorique(eleveId) {
        const container = document.getElementById('historiqueEleveContent');
        if (!container) return;
        container.textContent = 'Chargement de l’historique…';
        try {
            const result = await postAction('getListePaiements', {
                eleve_id: String(eleveId),
                annee_id: document.getElementById('annee_scolaire_id').value,
                page: '1'
            });
            const entries = result.paiements || [];
            if (!entries.length) {
                container.textContent = 'Aucun versement enregistré pour cet élève cette année.';
                return;
            }
            container.innerHTML = entries.map(payment => `
                <div class="history-payment">
                    <div>
                        <strong>${escapeHtml(payment.frais_libelle)} · ${escapeHtml(payment.periode_code || '')}</strong>
                        <small>${escapeHtml(payment.date_format)} — ${escapeHtml(payment.mode_paiement)}</small>
                    </div>
                    <div class="history-payment-amount">
                        <strong>${formatAmount(payment.montant_recu, payment.devise_recue)}</strong>
                        <small>${payment.statut === 'Annulé' ? 'Contre-écrit' : `Affecté : ${formatAmount(payment.montant_affecte, payment.devise_creance)}`}</small>
                    </div>
                </div>`).join('');
        } catch (error) {
            container.textContent = error.message || 'Impossible de charger l’historique.';
        }
    }

    async function chargerCreances(eleveId) {
        const select = document.getElementById('creance_id');
        const sectionFrais = document.getElementById('sectionFrais');
        if (!select) return;
        select.replaceChildren(new Option('Chargement des créances…', ''));
        sectionFrais.style.display = 'block';
        document.getElementById('sectionMontant').style.display = 'none';
        document.getElementById('btnEnregistrerPaiement').style.display = 'none';
        try {
            const result = await postAction('getCreancesEleve', {
                eleve_id: String(eleveId),
                annee_id: document.getElementById('annee_scolaire_id').value
            });
            const receivables = result.creances || [];
            select.replaceChildren(new Option(
                receivables.length ? '— Choisir un frais / une période —' : 'Aucune créance ouverte pour cette année',
                ''
            ));
            receivables.forEach(item => {
                const option = new Option(
                    `${item.libelle_snapshot} · ${formatPeriod(item.periode_code)} · reste ${formatAmount(item.reste_a_payer, item.devise)}`,
                    item.id
                );
                option.dataset.total = item.montant_du;
                option.dataset.paid = item.montant_paye;
                option.dataset.balance = item.reste_a_payer;
                option.dataset.currency = item.devise;
                option.dataset.period = item.periode_code;
                option.dataset.tuition = estFraisScolarite(item.categorie) ? '1' : '0';
                select.add(option);
            });
        } catch (error) {
            select.replaceChildren(new Option('Erreur de chargement', ''));
            showToastSafely(error.message || 'Impossible de charger les créances.', 'error');
        }
    }

    function updatePaymentSummary() {
        const select = document.getElementById('creance_id');
        const option = select && select.selectedOptions[0];
        const amountBox = document.getElementById('montantAttendu');
        const sectionAmount = document.getElementById('sectionMontant');
        const saveButton = document.getElementById('btnEnregistrerPaiement');
        const rateInput = document.getElementById('taux_cdf_par_usd');
        const rateGroup = document.getElementById('tauxChangeGroup');
        const tuitionInfo = document.getElementById('tauxScolariteInfo');
        const conversionInfo = document.getElementById('montantConvertiScolarite');
        if (!option || !option.value) {
            amountBox.style.display = 'none';
            sectionAmount.style.display = 'none';
            saveButton.style.display = 'none';
            if (tuitionInfo) tuitionInfo.style.display = 'none';
            if (conversionInfo) conversionInfo.style.display = 'none';
            return;
        }

        const currency = option.dataset.currency;
        const tenderCurrency = document.getElementById('devise_recue').value;
        const tuition = option.dataset.tuition === '1';
        document.getElementById('montantAttenduValeur').textContent =
            `${formatAmount(option.dataset.total, currency)} · ${formatAmount(option.dataset.paid, currency)} · ${formatAmount(option.dataset.balance, currency)}`;
        amountBox.style.display = 'block';
        sectionAmount.style.display = 'block';
        saveButton.style.display = 'inline-flex';
        const needsRate = currency !== tenderCurrency;
        if (tuition) {
            rateInput.required = false;
            rateInput.value = document.getElementById('taux_scolarite_configure').value;
            rateGroup.style.display = 'none';
            tuitionInfo.style.display = 'block';
            const amount = Number(document.getElementById('montant_recu').value);
            if (amount > 0) {
                const credited = tenderCurrency === 'CDF'
                    ? amount / Number(rateInput.value)
                    : amount;
                conversionInfo.textContent = `Montant qui sera affecté à la scolarité : ${formatAmount(credited, 'USD')}.`;
                conversionInfo.style.display = 'block';
            } else {
                conversionInfo.style.display = 'none';
            }
        } else {
            tuitionInfo.style.display = 'none';
            conversionInfo.style.display = 'none';
            rateInput.required = needsRate;
            rateGroup.style.display = needsRate ? 'block' : 'none';
            if (!needsRate) rateInput.value = '1';
            else if (!rateInput.value || Number(rateInput.value) === 1) rateInput.value = '';
        }
    }

    window.selectionnerEleve = function(id, nom, matricule, classe, sexe) {
        document.getElementById('eleve_id').value = id;
        const container = document.querySelector('.recherche-eleve-container');
        if (container) {
            container.innerHTML = `
                <div class="eleve-trouve-card">
                    <div class="eleve-trouve-avatar ${sexe === 'M' ? 'male' : 'female'}">
                        ${escapeHtml(nom.charAt(0) + (nom.split(' ')[1] || '').charAt(0))}
                    </div>
                    <div class="eleve-trouve-info">
                        <strong>${escapeHtml(nom)}</strong>
                        <span>${escapeHtml(matricule)} — ${escapeHtml(classe)}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="changerEleve()">
                        <i class="fa-solid fa-rotate"></i> Changer
                    </button>
                </div>`;
        }
        document.getElementById('historiqueEleve').style.display = 'block';
        chargerCreances(id);
        chargerHistorique(id);
    };

    window.changerEleve = function() {
        document.getElementById('eleve_id').value = '';
        const container = document.querySelector('.recherche-eleve-container');
        if (container) {
            container.innerHTML = `
                <div class="search-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="rechercheEleve" class="form-control"
                           placeholder="Rechercher par nom, prénom ou matricule..." autocomplete="off">
                </div>
                <div id="resultatsRecherche" class="resultats-recherche"></div>
                <small class="form-help">Tapez au moins 2 caractères pour lancer la recherche</small>`;
        }
        document.getElementById('creance_id').replaceChildren(new Option('— Sélectionnez d’abord un élève —', ''));
        document.getElementById('sectionFrais').style.display = 'none';
        document.getElementById('sectionMontant').style.display = 'none';
        document.getElementById('historiqueEleve').style.display = 'none';
        document.getElementById('btnEnregistrerPaiement').style.display = 'none';
    };

    window.changerAnneeFinance = function(anneeId) {
        window.location.href = `${basePath}/paiements/enregistrer?annee_id=${encodeURIComponent(anneeId)}`;
    };
    window.mettreAJourMontant = updatePaymentSummary;

    document.addEventListener('input', event => {
        if (event.target.id !== 'rechercheEleve') return;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => rechercherEleves(event.target), 300);
    });
    document.addEventListener('click', event => {
        const results = document.getElementById('resultatsRecherche');
        const input = document.getElementById('rechercheEleve');
        if (results && input && !results.contains(event.target) && event.target !== input) {
            results.classList.remove('show');
        }
    });

    if (form) {
        document.getElementById('creance_id').addEventListener('change', () => {
            const option = document.getElementById('creance_id').selectedOptions[0];
            if (option && option.value) {
                document.getElementById('devise_recue').value = option.dataset.currency;
                document.getElementById('taux_cdf_par_usd').value =
                    option.dataset.tuition === '1'
                        ? document.getElementById('taux_scolarite_configure').value
                        : '1';
            }
            updatePaymentSummary();
        });
        document.getElementById('devise_recue').addEventListener('change', updatePaymentSummary);
        document.getElementById('montant_recu').addEventListener('input', updatePaymentSummary);
        const preselected = document.querySelector('.eleve-trouve-card[data-eleve-id]');
        if (preselected) {
            window.selectionnerEleve(
                preselected.dataset.eleveId,
                preselected.dataset.nom,
                preselected.dataset.matricule,
                preselected.dataset.classe,
                preselected.dataset.sexe
            );
        }

        document.getElementById('btnEnregistrerPaiement').addEventListener('click', async event => {
            const button = event.currentTarget;
            if (!form.reportValidity()) return;
            if (!document.getElementById('eleve_id').value || !document.getElementById('creance_id').value) {
                showToastSafely('Sélectionnez un élève et une créance.', 'warning');
                return;
            }
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enregistrement…';
            try {
                const result = await postAction('enregistrerPaiement', new FormData(form));
                document.getElementById('modalRecuContent').innerHTML = `
                    <div class="receipt-success">
                        <i class="fa-solid fa-circle-check"></i>
                        <h5>Versement enregistré</h5>
                        <p>Reçu N° <strong>${escapeHtml(result.numero_recu)}</strong></p>
                        <p>Montant reçu : <strong>${formatAmount(result.montant_recu, result.devise_recue)}</strong></p>
                        <p>Affecté au frais : <strong>${formatAmount(result.montant_affecte, result.devise_creance)}</strong></p>
                        ${result.devise_recue !== result.devise_creance ? `<p>Taux appliqué : <strong>1 USD = ${formatAmount(result.taux_cdf_par_usd, 'CDF')}</strong></p>` : ''}
                        <p>Solde restant : <strong>${formatAmount(result.reste_a_payer, result.devise_creance)}</strong></p>
                    </div>`;
                document.getElementById('btnVoirRecu').href =
                    `${basePath}/paiements/recu?versement_id=${encodeURIComponent(result.id)}`;
                document.getElementById('modalRecu').classList.add('show');
                document.body.style.overflow = 'hidden';
                chargerCreances(document.getElementById('eleve_id').value);
                chargerHistorique(document.getElementById('eleve_id').value);
            } catch (error) {
                showToastSafely(error.message || 'Impossible d’enregistrer le versement.', 'error');
            } finally {
                button.disabled = false;
                button.innerHTML = '<i class="fa-solid fa-save"></i> Enregistrer et générer le reçu';
            }
        });
    }

    const receiptModal = document.getElementById('modalRecu');
    if (receiptModal) {
        window.closeModalRecu = () => {
            receiptModal.classList.remove('show');
            document.body.style.overflow = '';
        };
        receiptModal.addEventListener('click', event => {
            if (event.target === receiptModal) window.closeModalRecu();
        });
    }
});
