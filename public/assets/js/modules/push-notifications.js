'use strict';

(() => {
    const panel = document.getElementById('pushSetup');
    const button = document.getElementById('enablePushNotifications');
    const disableButton = document.getElementById('disablePushNotifications');
    const status = document.getElementById('pushSetupStatus');
    const description = document.getElementById('pushSetupDescription');
    if (!panel || !button || !disableButton || !status || !description) return;

    const workerUrl = panel.dataset.workerUrl;
    const workerScope = panel.dataset.workerScope;
    const notificationsEndpoint = panel.dataset.notificationsEndpoint;
    const vapidPublicKey = panel.dataset.vapidPublicKey;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let registrationPromise;
    let activeSubscription = null;

    function setStatus(message, state) {
        status.textContent = message;
        status.dataset.state = state;
    }

    function decodeApplicationServerKey(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const decoded = window.atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(decoded, character => character.charCodeAt(0));
    }

    async function postAction(action, values) {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', csrfToken);
        Object.entries(values).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(notificationsEndpoint, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'La demande n’a pas pu être traitée.');
        }
        return result;
    }

    function registerWorker() {
        if (!registrationPromise) {
            registrationPromise = navigator.serviceWorker.register(workerUrl, { scope: workerScope })
                .catch(error => {
                    registrationPromise = null;
                    throw error;
                });
        }
        return registrationPromise;
    }

    if (!window.isSecureContext) {
        description.textContent = 'Les notifications push nécessitent une connexion HTTPS sécurisée. localhost est autorisé pour les essais.';
        setStatus('Cette adresse ne permet pas l’activation des notifications push.', 'error');
        button.disabled = true;
        return;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        description.textContent = 'Ce navigateur ne prend pas en charge les notifications push Web nécessaires.';
        setStatus('Essayez avec un navigateur récent compatible.', 'error');
        button.disabled = true;
        return;
    }

    if (!vapidPublicKey) {
        description.textContent = 'La configuration push du serveur doit être terminée avant de pouvoir enregistrer cet appareil.';
        setStatus('Configuration serveur incomplète : aucun appareil ne sera enregistré.', 'error');
        button.disabled = true;
        disableButton.hidden = true;
        return;
    }

    if (/iPhone|iPad|iPod/i.test(navigator.userAgent) && !window.navigator.standalone) {
        description.textContent = 'Sur iPhone ou iPad, ajoutez d’abord ce site à l’écran d’accueil, puis ouvrez-le depuis cette icône.';
    }

    registerWorker()
        .then(async registration => {
            if (Notification.permission === 'granted') {
                activeSubscription = await registration.pushManager.getSubscription();
                if (activeSubscription) {
                    button.innerHTML = '<i class="fa-solid fa-rotate" aria-hidden="true"></i> Synchroniser cet appareil';
                    disableButton.hidden = false;
                    setStatus('Cet appareil possède déjà un abonnement. Synchronisez-le avec votre compte parent.', 'info');
                } else {
                    setStatus('Autorisation accordée. Cliquez pour créer et enregistrer l’abonnement de cet appareil.', 'info');
                }
            } else if (Notification.permission === 'denied') {
                setStatus('Les notifications sont bloquées dans les réglages du navigateur. Autorisez-les dans les paramètres du site.', 'error');
                button.disabled = true;
            } else {
                setStatus('Le service worker est prêt. Vous pouvez demander l’autorisation pour ce navigateur.', 'info');
            }
        })
        .catch(error => {
            console.error('Enregistrement du service worker impossible:', error);
            setStatus('Le service de notifications n’a pas pu être préparé. Actualisez la page ou réessayez plus tard.', 'error');
            button.disabled = true;
        });

    button.addEventListener('click', async () => {
        button.disabled = true;
        setStatus('Enregistrement sécurisé de cet appareil…', 'info');
        try {
            const registration = await registerWorker();
            if (Notification.permission !== 'granted') {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    setStatus(permission === 'denied'
                        ? 'Autorisation refusée. Vous pouvez la modifier dans les réglages du navigateur pour ce site.'
                        : 'Aucune autorisation n’a été accordée. Vous pourrez réessayer plus tard.', permission === 'denied' ? 'error' : 'info');
                    button.disabled = permission === 'denied';
                    return;
                }
            }

            activeSubscription = await registration.pushManager.getSubscription();
            if (!activeSubscription) {
                activeSubscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: decodeApplicationServerKey(vapidPublicKey)
                });
            }
            const result = await postAction('registerPushSubscription', {
                subscription: JSON.stringify(activeSubscription.toJSON())
            });
            setStatus(result.message + ' Le push est distinct des emails; les notifications admissibles s’afficheront après traitement par le serveur.', 'success');
            button.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Appareil enregistré';
            disableButton.hidden = false;
        } catch (error) {
            console.error('Enregistrement des notifications push impossible:', error);
            setStatus(error.message || 'L’activation a échoué. Vérifiez la connexion sécurisée et réessayez.', 'error');
            button.disabled = false;
        }
    });

    disableButton.addEventListener('click', async () => {
        disableButton.disabled = true;
        try {
            if (!activeSubscription) {
                const registration = await registerWorker();
                activeSubscription = await registration.pushManager.getSubscription();
            }
            if (!activeSubscription) {
                disableButton.hidden = true;
                setStatus('Aucun abonnement push actif sur cet appareil.', 'info');
                return;
            }

            setStatus('Désactivation de cet appareil…', 'info');
            const result = await postAction('removePushSubscription', { endpoint: activeSubscription.endpoint });
            await activeSubscription.unsubscribe();
            activeSubscription = null;
            disableButton.hidden = true;
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-bell" aria-hidden="true"></i> Activer sur cet appareil';
            setStatus(result.message, 'success');
        } catch (error) {
            console.error('Désactivation des notifications push impossible:', error);
            setStatus(error.message || 'L’appareil n’a pas pu être désactivé.', 'error');
        } finally {
            disableButton.disabled = false;
        }
    });
})();
