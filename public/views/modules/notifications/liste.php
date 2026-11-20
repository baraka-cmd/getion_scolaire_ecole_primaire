<?php
/**
 * Fichier : public/views/modules/notifications/liste.php
 * Rôle    : Centre de notifications personnel et historique groupé par destinataire
 */

defined('ACCESS') or define('ACCESS', true);
require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('notifications.consulter');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'NotificationController.php';
    (new NotificationController())->handleAjax();
}

global $db;
$isAdmin = hasRole(['Admin', 'Directeur']);
$isParent = hasRole('Parent');
$userId = (int) $_SESSION['utilisateur_id'];
$basePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
$where = $isAdmin ? '' : ' WHERE utilisateur_id = :uid';
$params = $isAdmin ? [] : ['uid' => $userId];

$totalNotifications = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM notifications{$where}",
    $params
);
$nonLues = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . ' est_lue = 0',
    $params
);
$lues = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . ' est_lue = 1',
    $params
);
$alertes = (int) $db->fetchColumn(
    "SELECT COUNT(*) FROM notifications{$where}" . ($where === '' ? ' WHERE' : ' AND') . " type_notification IN ('Alerte','Urgence')",
    $params
);
$destinataires = $isAdmin
    ? (int) $db->fetchColumn('SELECT COUNT(DISTINCT utilisateur_id) FROM notifications')
    : 0;

$pageTitle = $isAdmin ? 'Centre de notifications' : 'Mes notifications';
$pageIcon = 'fa-bell';
$pageDescription = $isAdmin
    ? 'Historique des notifications regroupé par destinataire'
    : 'Consultez vos notifications et alertes';
$extraCSS = ['modules/notifications.css'];
$extraJS = ['modules/notifications.js'];
if ($isParent) {
    $extraJS[] = 'modules/push-notifications.js';
}

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e($basePath) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <span class="current">Notifications</span>
</nav>

<?php if ($isParent): ?>
    <section class="notification-push-setup" id="pushSetup"
        data-worker-url="<?= e($basePath) ?>/service-worker.js"
        data-worker-scope="<?= e($basePath) ?>/"
        data-vapid-public-key="<?= e(PUSH_VAPID_PUBLIC_KEY) ?>"
        data-notifications-endpoint="<?= e($basePath) ?>/notifications">
        <div class="notification-push-copy">
            <span class="notification-push-icon" aria-hidden="true"><i class="fa-solid fa-mobile-screen-button"></i></span>
            <div>
                <h5>Notifications push sur cet appareil</h5>
                <p id="pushSetupDescription">Le push apparaît dans les notifications du navigateur; il est indépendant des emails envoyés.</p>
            </div>
        </div>
        <div class="notification-push-actions">
            <button type="button" class="btn btn-outline-primary btn-sm" id="enablePushNotifications" aria-describedby="pushSetupStatus">
                <i class="fa-solid fa-bell" aria-hidden="true"></i> Activer sur cet appareil
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="disablePushNotifications" hidden>
                Désactiver cet appareil
            </button>
            <p class="notification-push-status" id="pushSetupStatus" role="status" aria-live="polite">
                Activez cet appareil; le traitement push du serveur doit aussi être planifié.
            </p>
        </div>
    </section>
<?php endif; ?>

<main class="notification-center" id="notificationsPage" data-mode="<?= $isAdmin ? 'admin' : 'personnel' ?>">
    <div class="resultats-header notification-page-header">
        <div class="resultats-header-left">
            <h4><i class="fa-solid fa-bell"></i> <?= $isAdmin ? 'Centre de notifications' : 'Mes notifications' ?></h4>
            <p><?= $isAdmin
                ? 'Les messages sont regroupés par destinataire. Ouvrez un fil pour vérifier chaque lecture et livraison email.'
                : 'Retrouvez ici vos informations, rappels et alertes de l’école.' ?></p>
        </div>
        <div class="resultats-header-right">
            <?php if (!$isAdmin && $nonLues > 0): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnMarquerToutesLues">
                    <i class="fa-solid fa-check-double"></i> Tout marquer comme lu
                </button>
            <?php endif; ?>
            <?php if (can('notifications.envoyer')): ?>
                <a href="<?= e($basePath) ?>/notifications/envoyer" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-paper-plane"></i> Nouvelle notification
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="notification-stats" aria-label="Résumé des notifications">
        <div class="notification-stat">
            <span class="notification-stat-icon"><i class="fa-regular fa-bell"></i></span>
            <span><strong id="statTotal"><?= $totalNotifications ?></strong><small>Notifications</small></span>
        </div>
        <div class="notification-stat notification-stat-unread">
            <span class="notification-stat-icon"><i class="fa-regular fa-envelope"></i></span>
            <span><strong id="statUnread"><?= $nonLues ?></strong><small>Non lues</small></span>
        </div>
        <div class="notification-stat notification-stat-read">
            <span class="notification-stat-icon"><i class="fa-solid fa-check"></i></span>
            <span><strong id="statRead"><?= $lues ?></strong><small>Lues</small></span>
        </div>
        <?php if ($isAdmin): ?>
            <div class="notification-stat">
                <span class="notification-stat-icon"><i class="fa-solid fa-users"></i></span>
                <span><strong id="statRecipients"><?= $destinataires ?></strong><small>Destinataires</small></span>
            </div>
        <?php else: ?>
            <div class="notification-stat notification-stat-alert">
                <span class="notification-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span><strong id="statAlerts"><?= $alertes ?></strong><small>Alertes</small></span>
            </div>
        <?php endif; ?>
    </div>

    <section class="notification-filter-panel" aria-label="Filtres des notifications">
        <div class="notification-filter">
            <label for="filterType">Type</label>
            <select id="filterType" class="form-control">
                <option value="">Tous les types</option>
                <option value="Information">Information</option>
                <option value="Alerte">Alerte</option>
                <option value="Rappel">Rappel</option>
                <option value="Urgence">Urgence</option>
                <option value="Succès">Succès</option>
            </select>
        </div>
        <div class="notification-filter">
            <label for="filterLu"><?= $isAdmin ? 'État du fil' : 'Lecture' ?></label>
            <select id="filterLu" class="form-control">
                <option value="">Tous les statuts</option>
                <?php if ($isAdmin): ?>
                    <option value="0">Contient du non-lu</option>
                    <option value="1">Tout lu</option>
                <?php else: ?>
                    <option value="0">Non lues</option>
                    <option value="1">Lues</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="notification-filter notification-filter-search">
            <label for="filterRecherche"><?= $isAdmin ? 'Destinataire ou message' : 'Rechercher' ?></label>
            <input
                type="search"
                id="filterRecherche"
                class="form-control"
                placeholder="<?= $isAdmin ? 'Nom, titre ou contenu…' : 'Titre ou contenu…' ?>"
                autocomplete="off">
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnResetNotifications">
            <i class="fa-solid fa-rotate"></i> Effacer les filtres
        </button>
    </section>

    <div class="notification-list-heading">
        <div>
            <h5><?= $isAdmin ? 'Fils par destinataire' : 'Historique reçu' ?></h5>
            <p><?= $isAdmin
                ? 'Chaque fil conserve toutes les notifications et leur état individuel.'
                : 'Chaque notification reste séparée avec son propre état de lecture.' ?></p>
        </div>
        <span class="notification-list-count" id="notificationResultCount"></span>
    </div>

    <div class="notifications-list" id="notificationsList" aria-live="polite" aria-busy="true">
        <div class="notification-loading">
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
            <span>Chargement des notifications…</span>
        </div>
    </div>

    <div class="pagination-wrapper" id="paginationWrapper" style="display:none;">
        <div class="pagination-info" id="paginationInfo"></div>
        <div class="pagination" id="paginationNotifications"></div>
    </div>
</main>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
