(() => {
    const card = document.querySelector('.attendance-card');
    const message = document.getElementById('pointageMessage');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const csrfName = window.pointageCsrfName || '_csrf';
    const presentCount = document.getElementById('compteurPresents');
    const search = document.getElementById('rechercheEleve');
    const dialog = document.getElementById('dialogConge');

    const showMessage = (text, isError = false) => {
        if (!message) return;
        message.textContent = text;
        message.className = `pointage-message is-visible ${isError ? 'is-error' : 'is-success'}`;
    };

    const updateCount = () => {
        if (!presentCount) return;
        presentCount.textContent = document.querySelectorAll('.presence-checkbox:checked').length;
    };

    const request = async (data) => {
        const body = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(`${key}[]`, item));
            } else {
                body.append(key, value);
            }
        });
        body.append(csrfName, csrfToken);
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'La demande n’a pas pu aboutir.');
        }
        return result;
    };

    document.querySelectorAll('.presence-checkbox').forEach((checkbox) => {
        checkbox.addEventListener('change', updateCount);
    });
    updateCount();

    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('fr');
        document.querySelectorAll('.attendance-student').forEach((student) => {
            student.hidden = query !== '' && !student.dataset.search.includes(query);
        });
    });

    document.getElementById('validerAppel')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const checked = [...document.querySelectorAll('.presence-checkbox:checked')].map((input) => input.value);
        const total = document.querySelectorAll('.presence-checkbox').length;
        const absents = total - checked.length;
        if (!window.confirm(`Valider l’appel avec ${checked.length} présent(s) et ${absents} absent(s) ?`)) return;

        button.disabled = true;
        try {
            const result = await request({
                action: 'validerAppel',
                classe_id: card.dataset.classeId,
                date_appel: card.dataset.date,
                eleves_presents: checked
            });
            showMessage(result.message);
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            showMessage(error.message, true);
            button.disabled = false;
        }
    });

    document.getElementById('ouvrirModalConge')?.addEventListener('click', () => dialog?.showModal());
    document.getElementById('annulerDialogConge')?.addEventListener('click', () => dialog?.close());
    document.getElementById('confirmerConge')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const motif = document.getElementById('motifConge').value.trim();
        if (!motif) {
            document.getElementById('motifConge').focus();
            return;
        }
        if (!window.confirm('Confirmer ce congé ? Aucun pointage ne sera créé pour cette date.')) return;
        button.disabled = true;
        try {
            const result = await request({
                action: 'marquerConge',
                classe_id: card.dataset.classeId,
                date_appel: card.dataset.date,
                motif_conge: motif
            });
            dialog.close();
            showMessage(result.message);
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            showMessage(error.message, true);
            button.disabled = false;
        }
    });

    document.getElementById('annulerConge')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const closureNotice = document.querySelector('.closure-notice');
        if (!window.confirm('Annuler le congé manuel pour cette date ?')) return;
        button.disabled = true;
        try {
            const result = await request({
                action: 'annulerConge',
                classe_id: closureNotice.dataset.classeId,
                date_appel: closureNotice.dataset.date
            });
            showMessage(result.message);
            window.setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            showMessage(error.message, true);
            button.disabled = false;
        }
    });
})();
