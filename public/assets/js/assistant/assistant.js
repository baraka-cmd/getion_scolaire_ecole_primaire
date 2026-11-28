'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('assistantSidebar');
    const openSidebar = document.getElementById('assistantSidebarOpen');
    const closeSidebar = document.getElementById('assistantSidebarClose');
    const newConversation = document.getElementById('newConversation');
    const mobileNew = document.getElementById('assistantMobileNew');
    const welcome = document.getElementById('assistantWelcome');
    const input = document.getElementById('assistantInput');
    const composer = document.getElementById('assistantComposer');
    const messages = document.getElementById('assistantMessages');
    const sendButton = document.getElementById('assistantSend');
    const fileInput = document.getElementById('assistantFileInput');
    const attachButton = document.getElementById('assistantAttach');
    const uploadPanel = document.getElementById('assistantUploadPanel');
    const uploadPreview = document.getElementById('assistantUploadPreview');
    const uploadCancel = document.getElementById('assistantUploadCancel');
    const uploadDelete = document.getElementById('assistantUploadDelete');
    const uploadConfirm = document.getElementById('assistantUploadConfirm');
    const historyMore = document.getElementById('assistantHistoryMore');
    const canDeleteConversations = window.ASSISTANT_CAN_DELETE === true;
    let conversationId = 0;
    let historyPage = 1;
    let historyRequestId = 0;
    let historyController = null;
    let importId = 0;
    let selectedFile = null;
    const basePath = window.APP_BASE_PATH || '';
    const activeConversationKey = `assistant.activeConversation.${basePath}`;
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const updateConversationUrl = (id) => {
        const url = new URL(window.location.href);
        if (id > 0) {
            url.searchParams.set('conversation_id', String(id));
        } else {
            url.searchParams.delete('conversation_id');
        }
        window.history.replaceState({}, '', url);
    };

    const appendMessage = (content, role, loading = false) => {
        if (!messages) return null;
        const message = document.createElement('div');
        message.className = `assistant-message ${role}${loading ? ' assistant-message-loading' : ''}`;
        message.innerHTML = `
            <span class="assistant-message-avatar"><i class="fa-solid ${role === 'user' ? 'fa-user' : 'fa-wand-magic-sparkles'}"></i></span>
            <div class="assistant-message-content">${content}</div>
        `;
        messages.appendChild(message);
        messages.scrollTop = messages.scrollHeight;
        return message;
    };

    // Markdown volontairement limité : tout le texte est échappé avant
    // d'autoriser uniquement quelques balises de présentation contrôlées.
    const formatAssistantText = (value) => {
        const safe = escapeHtml(String(value || ''))
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/^#{1,3}\s+(.+)$/gm, '<strong>$1</strong>')
            .replace(/^\s*[-*]\s+(.+)$/gm, '• $1');
        return safe.split(/\n{2,}/).map(paragraph => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`).join('');
    };

    const renderResultActions = (result, display = null) => result
        ? `<div class="assistant-response-actions">
            <button type="button" class="assistant-result-action" data-action="copy" title="Copier la réponse"><i class="fa-regular fa-copy"></i> Copier</button>
            <button type="button" class="assistant-result-action" data-action="download" title="Télécharger la réponse"><i class="fa-solid fa-download"></i> Télécharger</button>
        </div><span class="assistant-result-data" data-result="${escapeHtml(JSON.stringify(result))}" data-display="${escapeHtml(JSON.stringify(display || {}))}" hidden></span>`
        : '';

    const renderAssistantResponse = (data) => {
        const classification = data.classification || {
            intent: data.intent || '',
            parameters: data.parameters || {},
            status: data.status || ''
        };
        const parameters = classification.parameters || {};
        const result = data.result || data.data || null;
        const display = data.display || null;
        const parameterRows = Object.entries(parameters)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => `<div class="assistant-data-row"><span>${escapeHtml(key.replaceAll('_', ' '))}</span><strong>${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : value)}</strong></div>`)
            .join('');
        const permission = data.permission
            ? `<span class="assistant-response-permission"><i class="fa-solid fa-shield-halved"></i> Permission vérifiée</span>`
            : '';
        let resultDetails = '';
        if (result?.type === 'student_list' || result?.type === 'subjects' || result?.type === 'parents' || result?.type === 'subjects_and_parents' || result?.type === 'payment_students' || result?.type === 'absence_list') {
            const items = result.items || (result.subjects || []).concat(result.parents || []);
            resultDetails = `<div class="assistant-response-card assistant-result-table"><div class="assistant-response-card-title"><i class="fa-solid fa-table-list"></i> Résultats (${items.length})</div>${items.slice(0, 100).map((item) => {
                const label = item.prenom && item.nom ? `${item.prenom} ${item.nom}` : (item.libelle || '');
                const detail = item.reste !== undefined
                    ? `Reste : ${item.reste}`
                    : item.date_absence
                        ? `${item.date_absence} · ${item.est_justifiee ? 'Justifiée' : 'Non justifiée'}`
                        : item.classe || item.eleve_prenom && item.eleve_nom
                            ? (item.classe || `Élève : ${item.eleve_prenom} ${item.eleve_nom}`)
                            : (item.code || item.lien || '');
                return `<div class="assistant-data-row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(detail)}</strong></div>`;
            }).join('')}</div>`;
        }
        if (result?.type === 'payment_summary' || result?.type === 'absence_summary' || result?.type === 'result_summary' || result?.type === 'result_ranking' || result?.type === 'proclamation') {
            const summary = result.summary || {};
            const labels = result.type === 'payment_summary'
                ? [['attendu', 'Total attendu'], ['paye', 'Total payé'], ['reste', 'Total restant'], ['eleves', 'Élèves concernés']]
                : result.type === 'absence_summary'
                    ? [['total', 'Total absences'], ['justifiees', 'Justifiées'], ['non_justifiees', 'Non justifiées']]
                    : [['total', result.type === 'proclamation' ? 'Élèves proclamés' : 'Résultats'], ['moyenne', 'Moyenne générale'], ['reussis', result.type === 'proclamation' ? 'Admis' : 'Réussis'], ['echoues', 'Échoués']];
            resultDetails = `<div class="assistant-response-card assistant-result-table"><div class="assistant-response-card-title"><i class="fa-solid fa-chart-column"></i> Synthèse</div>${labels.map(([key, label]) => `<div class="assistant-data-row"><span>${label}</span><strong>${escapeHtml(String(summary[key] ?? 0))}</strong></div>`).join('')}</div>`;
            const items = summary.items || summary.top_absents || [];
            if (items.length) {
                resultDetails += `<div class="assistant-response-card assistant-result-table"><div class="assistant-response-card-title"><i class="fa-solid fa-table-list"></i> Détails</div>${items.map((item) => `<div class="assistant-data-row"><span>${escapeHtml(`${item.prenom || ''} ${item.nom || ''}`.trim())}</span><strong>${escapeHtml(String(item.moyenne_generale ?? item.total ?? item.decision ?? ''))}</strong></div>`).join('')}</div>`;
            }
        }
        if (display?.type === 'table' && Array.isArray(display.columns) && Array.isArray(display.rows)) {
            const columns = display.columns.filter(column => column && typeof column.key === 'string').slice(0, 12);
            const rows = display.rows.slice(0, 100);
            if (columns.length) {
                resultDetails += `<section class="assistant-response-card assistant-data-table-wrap"><div class="assistant-response-card-title">${escapeHtml(display.title || 'Données détaillées')}</div><div class="assistant-data-table-scroll"><table class="assistant-data-table"><thead><tr>${columns.map(column => `<th scope="col">${escapeHtml(column.label || column.key)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${columns.map(column => `<td>${escapeHtml(String(row[column.key] ?? '—'))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>${rows.length >= 100 ? '<p class="assistant-table-limit">Affichage limité aux 100 premières lignes.</p>' : ''}</section>`;
            }
        }
        const followups = this.getFollowupSuggestions(result);
        const followupHtml = followups.length ? `<div class="assistant-followups" aria-label="Questions de suivi">${followups.map(prompt => `<button type="button" class="assistant-followup" data-prompt="${escapeHtml(prompt)}">${escapeHtml(prompt)}</button>`).join('')}</div>` : '';
        return `<div class="assistant-response-text">${formatAssistantText(data.message || 'Réponse reçue.')}</div>${permission}${resultDetails}${followupHtml}${renderResultActions(result, display)}${classification.intent ? `<div class="assistant-response-card"><div class="assistant-response-card-title"><i class="fa-solid fa-chart-simple"></i> Filtres de la demande</div><div class="assistant-data-row"><span>Domaine</span><strong>${escapeHtml(classification.intent)}</strong></div>${parameterRows}</div>` : ''}`;
    };

    const getFollowupSuggestions = (result) => {
        if (!result || typeof result !== 'object') return [];
        const suggestions = {
            payment_summary: ['Compare avec la période précédente', 'Quels élèves ont encore un solde ?', 'Détaille les paiements par classe'],
            absence_summary: ['Compare avec le mois précédent', 'Détaille les absences non justifiées', 'Quelle classe est la plus concernée ?'],
            result_summary: ['Compare avec le trimestre précédent', 'Détaille les résultats par classe', 'Quels élèves sont en difficulté ?'],
            result_ranking: ['Explique le classement', 'Compare avec la période précédente'],
            student_count: ['Répartis les élèves par classe', 'Compare avec l’année précédente'],
            class_count: ['Affiche les effectifs par classe']
        };
        return (suggestions[result.type] || []).slice(0, 3);
    };

    const resultText = (result) => {
        if (!result) return '';
        if (result.type === 'display_text') return result.text || '';
        if (result.type === 'student_count') return `Nombre d’élèves : ${result.count}`;
        if (result.type === 'class_count') return `Nombre de classes : ${result.count}`;
        if (result.type === 'student_gender_counts') {
            return `Garçons : ${result.counts.garcons}\nFilles : ${result.counts.filles}\nTotal : ${result.counts.total}`;
        }
        if (result.type === 'student_list') {
            return result.items.map((student) => `${student.prenom} ${student.nom}${student.classe ? ` (${student.classe})` : ''}`).join('\n');
        }
        if (result.type === 'payment_summary') {
            const s = result.summary || {};
            return `Total attendu : ${s.attendu || 0}\nTotal payé : ${s.paye || 0}\nTotal restant : ${s.reste || 0}\nÉlèves concernés : ${s.eleves || 0}`;
        }
        if (result.type === 'payment_students') {
            return (result.items || []).map((item) => `${item.prenom} ${item.nom} - reste : ${item.reste}`).join('\n');
        }
        if (result.type === 'absence_summary') {
            const s = result.summary || {};
            return `Total absences : ${s.total || 0}\nJustifiées : ${s.justifiees || 0}\nNon justifiées : ${s.non_justifiees || 0}`;
        }
        if (result.type === 'absence_list') {
            return (result.items || []).map((item) => `${item.prenom} ${item.nom} - ${item.date_absence} - ${item.est_justifiee ? 'Justifiée' : 'Non justifiée'}`).join('\n');
        }
        if (result.type === 'result_summary') {
            const s = result.summary || {};
            return `Résultats : ${s.total || 0}\nMoyenne générale : ${s.moyenne || 0}\nRéussis : ${s.reussis || 0}\nÉchoués : ${s.echoues || 0}`;
        }
        if (result.type === 'result_ranking') {
            return (result.summary?.items || []).map((item) => `${item.rang || '-'} - ${item.prenom} ${item.nom} : ${item.moyenne_generale}`).join('\n');
        }
        return JSON.stringify(result, null, 2);
    };

    const downloadResult = (result) => {
        const blob = new Blob([resultText(result)], { type: 'text/plain;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'resultat-assistant.txt';
        link.click();
        URL.revokeObjectURL(link.href);
    };

    messages?.addEventListener('click', (event) => {
        const followup = event.target.closest('.assistant-followup');
        if (!followup || !input || !composer) return;
        input.value = followup.dataset.prompt || '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        composer.requestSubmit();
    });

    messages?.addEventListener('click', async (event) => {
        const button = event.target.closest('.assistant-result-action');
        if (!button) return;
        const container = button.closest('.assistant-message-content');
        const dataElement = container?.querySelector('.assistant-result-data');
        if (!dataElement) return;
        const result = JSON.parse(dataElement.dataset.result || '{}');
        const display = JSON.parse(dataElement.dataset.display || '{}');
        if (button.dataset.action === 'copy') {
            await navigator.clipboard.writeText(display.export_text || resultText(result));
            button.innerHTML = '<i class="fa-solid fa-check"></i> Copié';
            setTimeout(() => { button.innerHTML = '<i class="fa-regular fa-copy"></i> Copier'; }, 1400);
        } else {
            downloadResult(display.export_text ? { type: 'display_text', text: display.export_text } : result);
        }
    });

    const renderStoredMessage = (message) => {
        let content = message.role === 'assistant'
            ? formatAssistantText(message.contenu || '')
            : escapeHtml(message.contenu || '');
        if (message.role === 'assistant' && message.metadata_json) {
            try {
                const metadata = JSON.parse(message.metadata_json);
                content = renderAssistantResponse({
                    message: message.contenu || '',
                    permission: metadata.permission || '',
                    result: metadata.result || null,
                    display: metadata.display || null,
                    classification: {
                        intent: message.intention || '',
                        parameters: metadata.parameters || {},
                    },
                });
            } catch (error) {
                console.warn('Métadonnées de message invalides.', error);
            }
        }
        appendMessage(content, message.role === 'user' ? 'user' : 'assistant');
    };

    const startConversation = (prompt = '') => {
        historyRequestId += 1;
        historyController?.abort();
        historyController = null;
        document.querySelectorAll('.assistant-conversation-item').forEach((item) => item.classList.remove('active'));
        if (welcome) welcome.style.display = 'block';
        if (messages) {
            messages.replaceChildren();
            messages.style.display = 'none';
        }
        conversationId = 0;
        window.localStorage.removeItem(activeConversationKey);
        updateConversationUrl(0);
        resetUploadPanel();
        if (input) {
            input.value = prompt;
            input.focus();
            input.style.height = 'auto';
            input.style.height = `${input.scrollHeight}px`;
        }
        sidebar?.classList.remove('is-open');
    };

    openSidebar?.addEventListener('click', () => sidebar?.classList.add('is-open'));
    closeSidebar?.addEventListener('click', () => sidebar?.classList.remove('is-open'));
    newConversation?.addEventListener('click', () => startConversation());
    mobileNew?.addEventListener('click', () => startConversation());

    document.querySelectorAll('.assistant-suggestion').forEach((suggestion) => {
        suggestion.addEventListener('click', () => startConversation(suggestion.dataset.prompt || ''));
    });

    const bindConversation = (conversation) => {
        conversation.addEventListener('click', () => {
            document.querySelectorAll('.assistant-conversation-item').forEach((item) => item.classList.remove('active'));
            conversation.classList.add('active');
            loadConversation(Number(conversation.dataset.conversationId));
            sidebar?.classList.remove('is-open');
        });
    };
    document.querySelectorAll('.assistant-conversation-item').forEach(bindConversation);

    const deleteConversation = (button) => {
        const id = Number(button.dataset.conversationId);
        if (!id || !window.confirm('Supprimer définitivement cette conversation ?')) return;

        button.disabled = true;
        const formData = new FormData();
        formData.append('conversation_id', String(id));
        formData.append('csrf_token', csrfToken());
        fetch(`${basePath}/api/assistant/delete`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(readJsonResponse).then(() => {
            button.closest('.assistant-conversation-row')?.remove();
            if (conversationId === id) startConversation();
        }).catch((error) => {
            button.disabled = false;
            window.alert(error.message);
        });
    };
    document.querySelectorAll('.assistant-conversation-delete').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            deleteConversation(button);
        });
    });

    const ensureConversationItem = (id, title) => {
        if (!id || !title) return;
        const existing = document.querySelector(`.assistant-conversation-item[data-conversation-id="${id}"]`);
        if (existing) {
            const titleElement = existing.querySelector('strong');
            if (titleElement) titleElement.textContent = title;
            existing.classList.add('active');
            return;
        }

        const row = document.createElement('div');
        row.className = 'assistant-conversation-row';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'assistant-conversation-item active';
        button.dataset.conversationId = id;
        button.innerHTML = `<i class="fa-regular fa-message"></i><span><strong>${escapeHtml(title)}</strong><small>À l’instant</small></span>`;
        row.appendChild(button);
        if (canDeleteConversations) {
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'assistant-conversation-delete';
            deleteButton.dataset.conversationId = id;
            deleteButton.setAttribute('aria-label', 'Supprimer la conversation');
            deleteButton.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
            deleteButton.addEventListener('click', (event) => {
                event.stopPropagation();
                deleteConversation(deleteButton);
            });
            row.appendChild(deleteButton);
        }
        document.getElementById('conversationList')?.prepend(row);
        bindConversation(button);
    };

    const loadConversation = (id) => {
        if (!id) return;
        window.localStorage.setItem(activeConversationKey, String(id));
        updateConversationUrl(id);
        const requestId = ++historyRequestId;
        historyController?.abort();
        historyController = new AbortController();
        fetch(`${basePath}/api/assistant/history?conversation_id=${encodeURIComponent(id)}`, {
            cache: 'no-store',
            credentials: 'same-origin',
            signal: historyController.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
        }).then(readJsonResponse).then((data) => {
            if (requestId !== historyRequestId) return;
            conversationId = id;
            window.localStorage.setItem(activeConversationKey, String(id));
            document.querySelectorAll('.assistant-conversation-item').forEach((item) => {
                item.classList.toggle('active', Number(item.dataset.conversationId) === id);
            });
            if (welcome) welcome.style.display = 'none';
            if (messages) {
                messages.replaceChildren();
                messages.style.display = 'block';
            }
            (data.messages || []).forEach(renderStoredMessage);
            requestAnimationFrame(() => {
                if (messages) messages.scrollTop = messages.scrollHeight;
            });
        }).catch((error) => {
            if (error.name === 'AbortError' || requestId !== historyRequestId) return;
            if (error.status === 404) {
                window.localStorage.removeItem(activeConversationKey);
                updateConversationUrl(0);
            }
            if (messages) {
                messages.style.display = 'block';
                messages.replaceChildren();
            }
            appendMessage(escapeHtml(`Impossible de charger cette conversation : ${error.message}`), 'assistant');
        }).finally(() => {
            if (requestId === historyRequestId) historyController = null;
        });
    };

    const requestedConversationId = Number(new URLSearchParams(window.location.search).get('conversation_id') || 0);
    const savedConversationId = requestedConversationId > 0
        ? requestedConversationId
        : Number(window.localStorage.getItem(activeConversationKey) || 0);
    if (savedConversationId > 0) {
        const savedConversation = document.querySelector(
            `.assistant-conversation-item[data-conversation-id="${savedConversationId}"]`
        );
        if (savedConversation) {
            savedConversation.classList.add('active');
        }
        loadConversation(savedConversationId);
    }

    historyMore?.addEventListener('click', () => {
        historyPage += 1;
        historyMore.disabled = true;
        fetch(`${basePath}/api/assistant/history?page=${historyPage}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(async (response) => {
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Historique indisponible.');
            const list = document.getElementById('conversationList');
            (data.conversations?.items || []).forEach((conversation) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'assistant-conversation-item';
                button.dataset.conversationId = conversation.id;
                button.innerHTML = `<i class="fa-regular fa-message"></i><span><strong>${escapeHtml(conversation.titre)}</strong><small>${escapeHtml(conversation.last_message_at || conversation.created_at)}</small></span>`;
                const row = document.createElement('div');
                row.className = 'assistant-conversation-row';
                row.appendChild(button);
                if (canDeleteConversations) {
                    const deleteButton = document.createElement('button');
                    deleteButton.type = 'button';
                    deleteButton.className = 'assistant-conversation-delete';
                    deleteButton.dataset.conversationId = conversation.id;
                    deleteButton.setAttribute('aria-label', 'Supprimer la conversation');
                    deleteButton.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
                    deleteButton.addEventListener('click', (event) => {
                        event.stopPropagation();
                        deleteConversation(deleteButton);
                    });
                    row.appendChild(deleteButton);
                }
                list?.appendChild(row);
                bindConversation(button);
            });
            if (!data.conversations?.has_more && historyMore) historyMore.hidden = true;
        }).catch((error) => appendMessage(error.message, 'assistant')).finally(() => {
            historyMore.disabled = false;
        });
    });

    input?.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 130)}px`;
    });

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);

    const resetUploadPanel = () => {
        selectedFile = null;
        importId = 0;
        if (fileInput) fileInput.value = '';
        if (uploadPanel) uploadPanel.hidden = true;
        if (uploadPreview) uploadPreview.replaceChildren();
        if (uploadConfirm) uploadConfirm.hidden = true;
    };

    const renderUploadPreview = (file, report = null, loading = false) => {
        if (!uploadPanel || !uploadPreview) return;
        uploadPanel.hidden = false;
        const isImage = file.type.startsWith('image/');
        const image = isImage ? `<img src="${URL.createObjectURL(file)}" alt="Aperçu de ${escapeHtml(file.name)}">` : '<i class="fa-solid fa-file-csv assistant-file-icon"></i>';
        const rows = report?.rows || [];
        const errors = report?.errors || [];
        const rowsHtml = rows.length
            ? `<div class="assistant-student-preview"><strong><i class="fa-solid fa-table-list"></i> Élèves détectés</strong><div class="assistant-preview-table"><div class="assistant-preview-row assistant-preview-head"><span>Ligne</span><span>Élève</span><span>État</span></div>${rows.slice(0, 8).map((row) => `<div class="assistant-preview-row"><span>${escapeHtml(row.line)}</span><span>${escapeHtml(`${row.data?.prenom || ''} ${row.data?.nom || ''}`)}</span><span class="${row.status === 'ready' ? 'is-ready' : 'is-duplicate'}">${row.status === 'ready' ? 'Prêt' : 'Doublon'}</span></div>`).join('')}</div>${rows.length > 8 ? `<small>+ ${rows.length - 8} autre(s) ligne(s)</small>` : ''}</div>`
            : '';
        const errorsHtml = errors.length
            ? `<div class="assistant-upload-errors"><strong><i class="fa-solid fa-triangle-exclamation"></i> ${errors.length} erreur(s)</strong><span>${escapeHtml(errors[0].message || 'Certaines lignes sont invalides.')}</span></div>`
            : '';
        uploadPreview.innerHTML = `
            <div class="assistant-file-summary">${image}<span><strong>${escapeHtml(file.name)}</strong><small>${(file.size / 1024 / 1024).toFixed(2)} Mo · ${loading ? 'Analyse en cours...' : (report?.message || 'Fichier analysé')}</small></span>${loading ? '<i class="fa-solid fa-circle-notch assistant-spin"></i>' : ''}</div>
            ${rowsHtml}${errorsHtml}
        `;
        if (uploadConfirm) uploadConfirm.hidden = loading || !report || report.type !== 'csv' || !rows.some((row) => row.status === 'ready');
    };

    async function readJsonResponse(response) {
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (error) {
            const parseError = new Error(response.status >= 500
                ? 'Le serveur est momentanément indisponible.'
                : 'Réponse serveur invalide. Veuillez réessayer.');
            parseError.status = response.status;
            throw parseError;
        }
        if (!response.ok || !data.success) {
            const requestError = new Error(data.message || 'Opération impossible.');
            requestError.status = response.status;
            throw requestError;
        }
        return data;
    }

    const importRequest = (url, formData) => fetch(`${basePath}${url}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    }).then(readJsonResponse);

    attachButton?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        selectedFile = file;
        renderUploadPreview(file, null, true);
        const formData = new FormData();
        formData.append('file', file);
        formData.append('conversation_id', String(conversationId));
        formData.append('csrf_token', csrfToken());
        importRequest('/api/assistant/upload', formData).then((data) => {
            conversationId = Number(data.conversation_id || conversationId);
            importId = Number(data.import_id || 0);
            renderUploadPreview(file, data.report);
        }).catch((error) => {
            renderUploadPreview(file, { type: 'error', message: error.message, rows: [], errors: [{ message: error.message }] });
        });
    });

    const cancelImport = () => {
        if (!importId) {
            resetUploadPanel();
            return Promise.resolve();
        }
        const formData = new FormData();
        formData.append('import_id', String(importId));
        formData.append('csrf_token', csrfToken());
        return importRequest('/api/assistant/import/cancel', formData).finally(resetUploadPanel);
    };
    uploadCancel?.addEventListener('click', cancelImport);
    uploadDelete?.addEventListener('click', cancelImport);
    uploadConfirm?.addEventListener('click', () => {
        if (!importId) return;
        uploadConfirm.disabled = true;
        const formData = new FormData();
        formData.append('import_id', String(importId));
        formData.append('csrf_token', csrfToken());
        importRequest('/api/assistant/import/confirm', formData).then((data) => {
            appendMessage(`Import terminé : ${Number(data.imported || 0)} élève(s) ajouté(s).`, 'assistant');
            resetUploadPanel();
        }).catch((error) => {
            appendMessage(escapeHtml(error.message || 'Une erreur est survenue.'), 'assistant');
            uploadConfirm.disabled = false;
        });
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing) return;
        if (event.shiftKey) return;
        event.preventDefault();
        composer?.requestSubmit();
    });

    composer?.addEventListener('submit', (event) => {
        event.preventDefault();
        const message = input?.value.trim() || '';
        if (!message || !sendButton) return;

        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        appendMessage(message.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]), 'user');
        if (welcome) welcome.style.display = 'none';
        if (messages) messages.style.display = 'block';
        input.value = '';
        input.style.height = 'auto';
        sendButton.disabled = true;
        const loadingMessage = appendMessage('<span role="status" aria-live="polite"><i class="fa-solid fa-circle-notch"></i> Je comprends votre demande et prépare la réponse…</span>', 'assistant', true);
        const formData = new FormData();
        formData.append('message', message);
        formData.append('conversation_id', String(conversationId));
        formData.append('csrf_token', token);

        fetch(`${basePath}/api/assistant/classify`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(readJsonResponse).then((data) => {
            conversationId = Number(data.conversation_id || conversationId);
            if (conversationId) window.localStorage.setItem(activeConversationKey, String(conversationId));
            ensureConversationItem(conversationId, data.conversation_title);
            return renderAssistantResponse(data);
        }).then((reply) => {
            if (loadingMessage) loadingMessage.remove();
            appendMessage(reply, 'assistant');
        }).catch((error) => {
            if (loadingMessage) loadingMessage.remove();
            appendMessage(error.message, 'assistant');
        }).finally(() => {
            sendButton.disabled = false;
            input?.focus();
        });
    });
});
