'use strict';

(() => {
    const basePath = window.APP_BASE_PATH || '';
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    function escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    window.escapeHtml = escapeHtml;

    async function postNotification(action, values = {}, endpoint = '/notifications') {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', csrfToken());
        Object.entries(values).forEach(([key, value]) => {
            if (value !== undefined && value !== null) body.append(key, value);
        });

        const response = await fetch(basePath + endpoint, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            const error = new Error(result.message || `Erreur serveur (${response.status}).`);
            error.status = response.status;
            throw error;
        }
        return result;
    }

    function showError(error) {
        const message = error.status === 419
            ? 'Votre session a expiré. Actualisez la page puis réessayez.'
            : (error.message || 'Une erreur est survenue.');
        if (typeof showToast === 'function') showToast(message, 'error');
        else window.alert(message);
    }

    function updateBadge(count) {
        const badge = document.querySelector('.navbar-action-btn[title="Notifications"] .badge-count');
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.style.display = count > 0 ? 'flex' : 'none';
    }

    async function refreshBadge() {
        try {
            const result = await postNotification('getCount');
            updateBadge(Number(result.count || 0));
        } catch (error) {
            if (error.status !== 403) console.error('Compteur des notifications indisponible:', error);
        }
    }

    function safeNotificationLink(value) {
        if (!value) return '';
        try {
            const url = new URL(value, window.location.origin);
            return url.origin === window.location.origin ? url.href : '';
        } catch {
            return '';
        }
    }

    const typeDetails = {
        Information: { icon: 'fa-circle-info', label: 'Information' },
        Alerte: { icon: 'fa-circle-exclamation', label: 'Alerte' },
        Rappel: { icon: 'fa-clock', label: 'Rappel' },
        Urgence: { icon: 'fa-triangle-exclamation', label: 'Urgence' },
        Succès: { icon: 'fa-circle-check', label: 'Succès' }
    };

    function renderType(type) {
        const details = typeDetails[type] || typeDetails.Information;
        return `<span class="notification-type-badge notification-type-${escapeHtml(type || 'Information')}">
            <i class="fa-solid ${details.icon}" aria-hidden="true"></i>${escapeHtml(details.label)}
        </span>`;
    }

    function renderPersonalNotification(notification) {
        const isRead = Number(notification.est_lue) === 1;
        const url = safeNotificationLink(notification.lien);
        return `
            <article class="notification-item ${isRead ? 'is-read' : 'non-lue'}" data-notification-id="${Number(notification.id)}">
                <span class="notification-icon" aria-hidden="true"><i class="fa-solid ${typeDetails[notification.type_notification]?.icon || typeDetails.Information.icon}"></i></span>
                <div class="notification-content">
                    <div class="notification-header">
                        <h6 class="notification-titre">${escapeHtml(notification.titre)}</h6>
                        ${isRead
                            ? '<span class="notification-read-state"><i class="fa-solid fa-check" aria-hidden="true"></i> Lu</span>'
                            : '<span class="notification-badge-non-lue">Nouveau</span>'}
                    </div>
                    <p class="notification-message">${escapeHtml(notification.message).replace(/\n/g, '<br>')}</p>
                    <div class="notification-meta">
                        <span class="notification-date"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${escapeHtml(notification.date_format)} à ${escapeHtml(notification.heure_format)}</span>
                        ${isRead && notification.date_lecture_format
                            ? `<span class="notification-date-lecture"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Lu le ${escapeHtml(notification.date_lecture_format)}</span>`
                            : ''}
                        ${renderType(notification.type_notification)}
                    </div>
                </div>
                <div class="notification-actions">
                    ${url ? `<a href="${escapeHtml(url)}" class="btn btn-sm btn-outline-primary" title="Ouvrir le lien"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>` : ''}
                    ${!isRead ? `<button type="button" class="btn btn-sm btn-outline-primary" data-action="mark-read" data-id="${Number(notification.id)}">Marquer comme lu</button>` : ''}
                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete" data-id="${Number(notification.id)}" aria-label="Supprimer la notification"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                </div>
            </article>`;
    }

    function renderEmailStatus(notification) {
        const status = notification.statut_email || 'non_demande';
        const labels = {
            non_demande: ['Email non demandé', 'neutral'],
            en_attente: ['Email en attente', 'pending'],
            en_cours: ['Envoi email en cours', 'pending'],
            envoye: ['Email envoyé', 'success'],
            echec: ['Échec email après plusieurs tentatives', 'error'],
            sans_email: ['Adresse email absente ou invalide', 'error']
        };
        const [label, style] = labels[status] || ['État email inconnu', 'neutral'];
        const retryInfo = Number(notification.tentatives_email || 0) > 0
            ? `<span class="notification-email-attempts">${Number(notification.tentatives_email)} tentative(s)</span>`
            : '';
        const errorInfo = notification.erreur_email
            ? `<small class="notification-email-error">${escapeHtml(notification.erreur_email)}</small>`
            : '';
        return `<div class="notification-email-state ${style}">
            <span><i class="fa-solid fa-envelope" aria-hidden="true"></i> ${label}</span>
            ${retryInfo}${errorInfo}
        </div>`;
    }

    function renderThreadNotification(notification) {
        const isRead = Number(notification.est_lue) === 1;
        const campaignId = Number(notification.campagne_id || 0);
        const groupTarget = ['tous', 'classe'].includes(notification.type_destinataire);
        const source = campaignId
            ? (groupTarget
                ? `Envoi groupé · ${Number(notification.nb_destinataires || 0)} destinataire(s)`
                : 'Envoi individuel')
            : (notification.origine === 'automatique'
                ? 'Notification automatique'
                : 'Ancienne notification · type d’envoi non enregistré');
        const link = safeNotificationLink(notification.lien);

        return `
            <article class="notification-thread-item ${isRead ? 'is-read' : 'non-lue'}">
                <div class="notification-thread-marker" aria-hidden="true"></div>
                <div class="notification-thread-content">
                    <div class="notification-thread-top">
                        <div>
                            <h6 class="notification-titre">${escapeHtml(notification.titre)}</h6>
                            <span class="notification-source">${escapeHtml(source)}</span>
                        </div>
                        <span class="notification-read-state ${isRead ? '' : 'unread'}">
                            <i class="fa-solid ${isRead ? 'fa-check-double' : 'fa-envelope'}" aria-hidden="true"></i>
                            ${isRead ? `Lu${notification.date_lecture_format ? ` · ${escapeHtml(notification.date_lecture_format)}` : ''}` : 'Non lu'}
                        </span>
                    </div>
                    <p class="notification-message">${escapeHtml(notification.message).replace(/\n/g, '<br>')}</p>
                    <div class="notification-meta">
                        <span class="notification-date"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${escapeHtml(notification.date_format)} à ${escapeHtml(notification.heure_format)}</span>
                        ${renderType(notification.type_notification)}
                        ${link ? `<a class="notification-inline-link" href="${escapeHtml(link)}">Voir <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>` : ''}
                    </div>
                    ${renderEmailStatus(notification)}
                </div>
            </article>`;
    }

    function renderConversation(conversation) {
        const recipientId = Number(conversation.destinataire_id);
        const unread = Number(conversation.non_lues || 0);
        const count = Number(conversation.total_notifications || 0);
        const name = conversation.destinataire_nom || 'Compte utilisateur';
        const initial = Array.from(name.trim())[0]?.toLocaleUpperCase('fr') || '?';

        return `
            <article class="notification-conversation" data-recipient-id="${recipientId}">
                <div class="notification-conversation-summary">
                    <span class="notification-recipient-avatar" aria-hidden="true">${escapeHtml(initial)}</span>
                    <div class="notification-conversation-main">
                        <div class="notification-conversation-heading">
                            <h6>${escapeHtml(name)}</h6>
                            <span class="notification-conversation-date">${escapeHtml(conversation.dernier_envoi || '')}</span>
                        </div>
                        <div class="notification-conversation-preview">
                            <strong>${escapeHtml(conversation.dernier_titre || 'Notification')}</strong>
                            <span>${escapeHtml(conversation.dernier_message || '').replace(/\s+/g, ' ')}</span>
                        </div>
                        <div class="notification-conversation-meta">
                            <span><i class="fa-regular fa-bell" aria-hidden="true"></i> ${count} notification(s)</span>
                            <span><i class="fa-regular fa-user" aria-hidden="true"></i> ${escapeHtml(conversation.destinataire_login || '')}</span>
                            ${unread > 0
                                ? `<span class="notification-unread-count">${unread} non lue(s)</span>`
                                : '<span class="notification-all-read"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Tout lu</span>'}
                        </div>
                    </div>
                    <button type="button" class="notification-thread-toggle" data-action="toggle-thread" aria-expanded="false">
                        <span>Voir l’historique</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="notification-thread" hidden></div>
            </article>`;
    }

    function updateStats(stats, mode, recipientCount) {
        const values = {
            statTotal: stats?.total,
            statUnread: stats?.non_lues,
            statRead: stats?.lues,
            statAlerts: stats?.alertes,
            statRecipients: recipientCount
        };
        Object.entries(values).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element && value !== undefined) element.textContent = String(value);
        });
    }

    function renderPagination(data) {
        const wrapper = document.getElementById('paginationWrapper');
        const links = document.getElementById('paginationNotifications');
        const info = document.getElementById('paginationInfo');
        if (!wrapper || !links || !info) return;
        if (Number(data.pages) <= 1) {
            wrapper.style.display = 'none';
            return;
        }

        wrapper.style.display = 'flex';
        const lastPage = Number(data.pages);
        const currentPage = Number(data.page);
        const visiblePages = [...new Set([1, currentPage - 1, currentPage, currentPage + 1, lastPage])]
            .filter(page => page >= 1 && page <= lastPage)
            .sort((left, right) => left - right);
        const pages = [];
        let previousPage = 0;
        visiblePages.forEach(page => {
            if (page - previousPage > 1) pages.push('<span class="pagination-dots">…</span>');
            pages.push(`<button type="button" class="pagination-link ${page === currentPage ? 'active' : ''}" data-page="${page}" ${page === currentPage ? 'aria-current="page"' : ''}>${page}</button>`);
            previousPage = page;
        });
        links.innerHTML = pages.join('');
        info.textContent = `Page ${data.page} / ${data.pages} — ${data.total} ${document.getElementById('notificationsPage')?.dataset.mode === 'admin' ? 'destinataire(s)' : 'notification(s)'}`;
    }

    function currentFilters() {
        return {
            type: document.getElementById('filterType')?.value || '',
            lu: document.getElementById('filterLu')?.value || '',
            recherche: document.getElementById('filterRecherche')?.value.trim() || ''
        };
    }

    async function loadNotifications(page = 1) {
        const container = document.getElementById('notificationsList');
        if (!container) return;
        container.setAttribute('aria-busy', 'true');
        container.innerHTML = '<div class="notification-loading"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>Chargement…</span></div>';

        try {
            const result = await postNotification('getListe', { page, ...currentFilters() });
            const mode = document.getElementById('notificationsPage')?.dataset.mode;
            const count = document.getElementById('notificationResultCount');
            if (count) count.textContent = mode === 'admin'
                ? `${result.total} destinataire(s)`
                : `${result.total} notification(s)`;
            updateStats(result.stats, mode, result.total);

            if (mode === 'admin') {
                const conversations = result.conversations || [];
                container.innerHTML = conversations.length
                    ? conversations.map(renderConversation).join('')
                    : '<div class="notification-empty"><i class="fa-regular fa-bell-slash" aria-hidden="true"></i><h6>Aucun destinataire trouvé</h6><p>Modifiez les filtres ou la recherche.</p></div>';
            } else {
                const notifications = result.notifications || [];
                container.innerHTML = notifications.length
                    ? notifications.map(renderPersonalNotification).join('')
                    : '<div class="notification-empty"><i class="fa-regular fa-bell-slash" aria-hidden="true"></i><h6>Aucune notification</h6><p>Votre recherche ne correspond à aucun message.</p></div>';
            }
            renderPagination(result);
        } catch (error) {
            container.innerHTML = '<div class="notification-empty notification-empty-error"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><h6>Impossible de charger les notifications</h6><p>Vérifiez votre connexion puis réessayez.</p><button class="btn btn-outline-primary btn-sm" type="button" data-action="reload">Réessayer</button></div>';
            showError(error);
        } finally {
            container.setAttribute('aria-busy', 'false');
        }
    }

    async function loadThreadPage(card, page, append = false) {
        const thread = card.querySelector('.notification-thread');
        thread.setAttribute('aria-busy', 'true');
        try {
            const result = await postNotification('getConversation', {
                destinataire_id: card.dataset.recipientId,
                page
            });
            const notifications = result.notifications || [];
            const items = notifications.map(renderThreadNotification).join('');
            const remaining = Math.max(0, Number(result.total) - Number(result.page) * 20);
            const olderButton = Number(result.page) < Number(result.pages)
                ? `<button type="button" class="notification-load-older" data-action="load-older" data-page="${Number(result.page) + 1}">
                    <i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Charger ${remaining} notification(s) plus ancienne(s)
                </button>`
                : '';

            if (append) {
                thread.querySelector('[data-action="load-older"]')?.remove();
                thread.insertAdjacentHTML('beforeend', items + olderButton);
            } else {
                thread.innerHTML = notifications.length
                    ? items + olderButton
                    : '<p class="notification-thread-empty">Aucune notification dans cet historique.</p>';
            }
            thread.dataset.loaded = '1';
            thread.dataset.page = String(result.page);
            thread.dataset.pages = String(result.pages);
        } finally {
            thread.setAttribute('aria-busy', 'false');
        }
    }

    async function toggleThread(card, button) {
        const thread = card.querySelector('.notification-thread');
        const isOpen = button.getAttribute('aria-expanded') === 'true';
        if (isOpen) {
            thread.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            button.querySelector('span').textContent = 'Voir l’historique';
            return;
        }

        thread.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        button.querySelector('span').textContent = 'Masquer l’historique';
        if (thread.dataset.loaded === '1') return;

        thread.innerHTML = '<div class="notification-thread-loading"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Chargement de l’historique…</div>';
        try {
            await loadThreadPage(card, 1);
        } catch (error) {
            thread.innerHTML = '<p class="notification-thread-error">L’historique n’a pas pu être chargé. Fermez puis rouvrez le fil pour réessayer.</p>';
            thread.setAttribute('aria-busy', 'false');
            button.setAttribute('aria-expanded', 'false');
            thread.hidden = true;
            showError(error);
        }
    }

    function initListPage() {
        const container = document.getElementById('notificationsList');
        if (!container) return;

        let searchTimer;
        ['filterType', 'filterLu'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => loadNotifications(1));
        });
        document.getElementById('filterRecherche')?.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadNotifications(1), 250);
        });
        document.getElementById('btnResetNotifications')?.addEventListener('click', () => {
            document.getElementById('filterType').value = '';
            document.getElementById('filterLu').value = '';
            document.getElementById('filterRecherche').value = '';
            loadNotifications(1);
        });

        document.getElementById('paginationNotifications')?.addEventListener('click', event => {
            const button = event.target.closest('[data-page]');
            if (button) loadNotifications(Number(button.dataset.page));
        });

        container.addEventListener('click', async event => {
            const control = event.target.closest('[data-action]');
            if (!control) return;
            const card = control.closest('.notification-conversation');

            if (control.dataset.action === 'toggle-thread' && card) {
                await toggleThread(card, control);
                return;
            }
            if (control.dataset.action === 'load-older' && card) {
                control.disabled = true;
                try {
                    await loadThreadPage(card, Number(control.dataset.page), true);
                } catch (error) {
                    control.disabled = false;
                    showError(error);
                }
                return;
            }
            if (control.dataset.action === 'reload') {
                loadNotifications(1);
                return;
            }

            try {
                if (control.dataset.action === 'mark-read') {
                    await postNotification('marquerLue', { notification_id: control.dataset.id });
                    if (typeof showToast === 'function') showToast('Notification marquée comme lue.', 'success');
                } else if (control.dataset.action === 'delete') {
                    if (!window.confirm('Supprimer cette notification ?')) return;
                    await postNotification('supprimer', { notification_id: control.dataset.id });
                    if (typeof showToast === 'function') showToast('Notification supprimée.', 'success');
                }
                await loadNotifications(1);
                refreshBadge();
            } catch (error) {
                showError(error);
            }
        });

        document.getElementById('btnMarquerToutesLues')?.addEventListener('click', async event => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await postNotification('marquerToutesLues');
                if (typeof showToast === 'function') showToast(result.message, 'success');
                await loadNotifications(1);
                refreshBadge();
            } catch (error) {
                showError(error);
            } finally {
                button.disabled = false;
            }
        });

        loadNotifications(1);
        refreshBadge();
    }

    function initSendPage() {
        const form = document.getElementById('formNotification');
        const button = document.getElementById('btnEnvoyerNotification');
        if (!form || !button) return;

        const typeSelect = document.getElementById('destinataire_type');
        const classSelect = document.getElementById('classe_id');
        const parentSelect = document.getElementById('parent_id');
        let loadingParents = false;
        let sending = false;

        function setVisible(element, visible) {
            if (element) element.style.display = visible ? '' : 'none';
        }

        window.changerDestinataire = () => {
            const target = typeSelect.value;
            const needsClass = target === 'classe' || target === 'parent';
            setVisible(document.getElementById('groupeClasse'), needsClass);
            setVisible(document.getElementById('groupeParent'), target === 'parent');
            setVisible(document.getElementById('resumeDestinataires'), target !== '');
            classSelect.required = needsClass;
            parentSelect.required = target === 'parent';

            if (target === 'tous') {
                document.getElementById('resumeDestinatairesTexte').textContent =
                    'Les parents avec un compte actif et un enfant inscrit dans l’année active seront destinataires.';
            } else if (target === 'classe') {
                document.getElementById('resumeDestinatairesTexte').textContent =
                    'Les parents ayant un compte actif d’élèves actifs ou redoublants de cette classe seront destinataires.';
            } else if (target === 'parent') {
                document.getElementById('resumeDestinatairesTexte').textContent =
                    'Le parent sélectionné doit être lié à un élève inscrit dans cette classe pour l’année active.';
            }
            if (target === 'parent' && classSelect.value) loadParents();
        };

        async function loadParents() {
            if (!classSelect.value || loadingParents) return;
            loadingParents = true;
            parentSelect.replaceChildren(new Option('Chargement…', ''));
            try {
                const result = await postNotification('getParentsByClasse', {
                    classe_id: classSelect.value
                });
                parentSelect.replaceChildren(new Option('— Sélectionnez un parent —', ''));
                result.parents.forEach(parent => {
                    const label = `${parent.prenom} ${parent.nom}${parent.telephone_principal ? ` — ${parent.telephone_principal}` : ''}`;
                    parentSelect.add(new Option(label, parent.id));
                });
                if (!result.parents.length) {
                    parentSelect.replaceChildren(new Option('Aucun parent admissible dans cette classe', ''));
                }
            } catch (error) {
                parentSelect.replaceChildren(new Option('Erreur de chargement — réessayez', ''));
                showError(error);
            } finally {
                loadingParents = false;
            }
        }

        window.auChangementClasse = () => {
            if (typeSelect.value === 'parent') loadParents();
        };

        typeSelect.addEventListener('change', window.changerDestinataire);
        classSelect.addEventListener('change', window.auChangementClasse);
        window.changerDestinataire();

        button.addEventListener('click', async () => {
            if (sending) return;
            if (!form.reportValidity()) return;

            const formData = new FormData(form);
            formData.append('action', 'envoyer');
            formData.append('csrf_token', csrfToken());
            sending = true;
            button.disabled = true;
            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Enregistrement…';

            try {
                const response = await fetch(basePath + '/notifications/envoyer', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    const error = new Error(result.message || `Erreur serveur (${response.status}).`);
                    error.status = response.status;
                    throw error;
                }
                if (typeof showToast === 'function') showToast(result.message, 'success');
                window.setTimeout(() => {
                    window.location.href = basePath + '/notifications';
                }, 900);
            } catch (error) {
                showError(error);
            } finally {
                sending = false;
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initListPage();
        initSendPage();
    });
})();
