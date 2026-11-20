<?php
/**
 * Fichier : public/views/templates/header.php
 * Rôle     : En-tête HTML commun à toutes les pages
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../../../src/config/app.php';

// URLs dynamiques (S'adaptent automatiquement en local et sur InfinityFree)
$baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : (defined('APP_URL') ? rtrim(APP_URL, '/') : '');
$assetsUrl = defined('ASSETS_URL') ? rtrim(ASSETS_URL, '/') . '/' : ($baseUrl !== '' ? $baseUrl . '/assets/' : '/assets/');
$layoutCssVersion = filemtime(ASSETS_PATH . 'css' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'layout.css');
$sidebarCssVersion = filemtime(ASSETS_PATH . 'css' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'sidebar.css');
$printCssVersion = filemtime(ASSETS_PATH . 'css' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'print.css');
$variablesCssVersion = filemtime(ASSETS_PATH . 'css' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'variables.css');

// Vérifier la session
//$security->checkSessionTimeout();
//$security->validateSessionIntegrity();

// Une page protégée doit toujours exposer un token CSRF utilisable.
// On ne le remplace que lorsqu'il est absent ou arrivé à expiration afin
// de ne pas invalider les formulaires déjà ouverts dans un autre onglet.
if (
    empty($_SESSION[CSRF_TOKEN_NAME])
    || (int) ($_SESSION[CSRF_TOKEN_NAME . '_time'] ?? 0) <= time() - CSRF_TOKEN_LIFETIME
) {
    generateCsrfToken();
}

// Récupérer les infos utilisateur
$utilisateurNom    = $_SESSION['utilisateur_nom']    ?? '';
$utilisateurPrenom = $_SESSION['utilisateur_prenom'] ?? '';
$utilisateurEmail  = $_SESSION['utilisateur_email']  ?? '';
$utilisateurPhoto  = $_SESSION['utilisateur_photo']  ?? '';
$utilisateurRole   = $_SESSION['utilisateur_role']   ?? '';

// Récupérer les paramètres de l'école (avec cache de session)
if (!isset($_SESSION['ecole_params'])) {
    $ecoleParams = $db->fetchOne("SELECT nom_ecole, logo, devise FROM parametres_ecole WHERE id = 1");
    $_SESSION['ecole_params'] = $ecoleParams ?: [];
}
$ecoleParams = $_SESSION['ecole_params'] ?? [];
$ecoleNom = $ecoleParams['nom_ecole'] ?? ECOLE_NOM;
$ecoleLogo = $ecoleParams['logo'] ?? '';
$themeInterface = (string) $db->fetchColumn("SELECT theme_interface FROM parametres_ecole WHERE id = 1");
if (!array_key_exists($themeInterface, APP_COLOR_THEMES)) {
    $themeInterface = 'sapphire';
}

// Titre et icône de la page
$pageTitle = $pageTitle ?? 'Tableau de bord';
$pageIcon  = $pageIcon  ?? 'fa-gauge-high';
$pageDescription = $pageDescription ?? '';

// CSS et JS additionnels
$extraCSS = $extraCSS ?? [];
$extraJS  = $extraJS  ?? [];
?>
<script>
    window.APP_BASE_PATH = <?= json_encode(rtrim(parse_url(BASE_URL, PHP_URL_PATH), '/'), JSON_UNESCAPED_SLASHES) ?>;
</script>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($themeInterface) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="<?= e($pageDescription ?: APP_NAME . ' — Système de gestion scolaire complet') ?>">
    <meta name="author" content="<?= APP_AUTHOR ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="<?= e(APP_COLOR_THEMES[$themeInterface]['primary']) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <?php
    $headerCsrfToken = (string) ($_SESSION[CSRF_TOKEN_NAME] ?? '');
    $headerCsrfTime = (int) ($_SESSION[CSRF_TOKEN_NAME . '_time'] ?? 0);
    if ($headerCsrfToken === '' || $headerCsrfTime < time() - CSRF_TOKEN_LIFETIME) {
        $headerCsrfToken = generateCsrfToken();
    }
    ?>
    <meta name="csrf-token" content="<?= e($headerCsrfToken) ?>">
    <link rel="manifest" href="<?= e($baseUrl) ?>/manifest.webmanifest">

    <title><?= e($pageTitle) ?> — <?= e($ecoleNom) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= $assetsUrl ?>images/favicon.ico">
    <link rel="apple-touch-icon" href="<?= $assetsUrl ?>images/favicon.ico">

    <!-- Préchargement des ressources critiques -->
    <link rel="preload" href="<?= $assetsUrl ?>fontawesome/css/all.min.css" as="style">
    <link rel="preload" href="<?= $assetsUrl ?>css/common/variables.css?v=<?= $variablesCssVersion ?>" as="style">
    <link rel="preload" href="<?= $assetsUrl ?>css/common/reset.css" as="style">
    <link rel="preload" href="<?= $assetsUrl ?>fonts/poppins/Poppins-Regular.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="<?= $assetsUrl ?>fonts/poppins/Poppins-SemiBold.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="<?= $assetsUrl ?>fonts/poppins/Poppins-Bold.ttf" as="font" type="font/ttf" crossorigin>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= $assetsUrl ?>fontawesome/css/all.min.css">

    <!-- Styles communs -->
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/common/variables.css?v=<?= $variablesCssVersion ?>">
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/common/reset.css">
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/common/layout.css?v=<?= $layoutCssVersion ?>">

    <!-- Styles des templates -->
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/templates/header.css">
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/templates/sidebar.css?v=<?= $sidebarCssVersion ?>">
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/templates/footer.css">

    <!-- Styles additionnels par page -->
    <?php if (!empty($extraCSS)): ?>
        <?php foreach ((array)$extraCSS as $css): ?>
            <?php
            $cssPath = ASSETS_PATH . 'css' . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $css);
            $cssVersion = is_file($cssPath) ? filemtime($cssPath) : APP_VERSION;
            ?>
            <link rel="stylesheet" href="<?= $assetsUrl ?>css/<?= e((string) $css) ?>?v=<?= e((string) $cssVersion) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $assetsUrl ?>css/common/print.css?v=<?= $printCssVersion ?>">
</head>
<body>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<aside class="sidebar" id="sidebar">
    <!-- Profil utilisateur -->
    <div class="sidebar-brand">
        <a href="<?= $baseUrl ?>/dashboard">
            <?php 
            $logoPath = UPLOADS_PATH . 'logos' . DIRECTORY_SEPARATOR . basename($ecoleLogo);
            if (!empty($ecoleLogo) && file_exists($logoPath)): 
            ?>
                <img src="<?= $assetsUrl . 'uploads/logos/' . basename($ecoleLogo) ?>" 
                     alt="Logo" 
                     style="width:50px;height:50px;border-radius:12px;object-fit:contain;flex-shrink:0;">
            <?php else: ?>
                <i class="fa-solid fa-school" style="font-size:1.8rem;"></i>
            <?php endif; ?>
            <span><?= e($ecoleNom) ?></span>
        </a>
    </div>

    <!-- Menu de navigation -->
    <nav class="sidebar-menu" id="sidebarMenu">
        <?php require_once __DIR__ . '/sidebar.php'; ?>
    </nav>
</aside>

<!-- Overlay mobile -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<!-- ============================================================
     CONTENU PRINCIPAL
     ============================================================ -->
<div class="main-wrapper" id="main-wrapper">
    <!-- Barre de navigation supérieure -->
    <nav class="navbar-top" id="navbar-top">
        <div class="navbar-left">
            <button type="button" class="btn-toggle-sidebar" id="btn-toggle-sidebar" aria-label="Basculer le menu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="page-title-wrapper">
                <div class="page-title-icon">
                    <i class="fa-solid <?= e($pageIcon) ?>"></i>
                </div>
                <span class="page-title-text"><?= e($pageTitle) ?></span>
            </div>
        </div>

        <div class="navbar-right">
            <!-- Recherche globale -->
            <div class="navbar-search" id="navbarSearch">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input
                    type="text"
                    id="navbarSearchInput"
                    placeholder="Rechercher... (Ctrl+K)"
                    aria-label="Recherche globale"
                >
            </div>

            <!-- Date et heure -->
            <div class="navbar-datetime" id="currentDateTime" title="Date et heure actuelles">
                <i class="fa-regular fa-calendar"></i>
                <span><?= formatDate(date('Y-m-d'), 'l j F Y') ?></span>
            </div>

            <!-- Notifications -->
            <button type="button" class="navbar-action-btn" title="Notifications" data-url="<?= $baseUrl ?>/notifications" aria-label="Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php
                $nbNotifs = 0;
                try {
                    $nbNotifs = $db->fetchColumn(
                        "SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :uid AND est_lue = 0",
                        ['uid' => $_SESSION['utilisateur_id']]
                    );
                } catch (Exception $e) {
                    $nbNotifs = 0;
                }
                ?>
                <?php if ($nbNotifs > 0): ?>
                    <span class="badge-count" style="display: flex;"><?= $nbNotifs > 99 ? '99+' : $nbNotifs ?></span>
                <?php else: ?>
                    <span class="badge-count" style="display: none;">0</span>
                <?php endif; ?>
            </button>

            <!-- Messages -->
            <button type="button" class="navbar-action-btn" title="Messages" data-url="<?= $baseUrl ?>/messages" aria-label="Messages">
                <i class="fa-solid fa-envelope"></i>
                <?php
                $nbMessages = 0;
                try {
                    $nbMessages = $db->fetchColumn(
                        "SELECT COUNT(*) FROM messages WHERE destinataire_id = :uid AND est_lu = 0 AND est_supprime_destinataire = 0",
                        ['uid' => $_SESSION['utilisateur_id']]
                    );
                } catch (Exception $e) {
                    $nbMessages = 0;
                }
                ?>
                <?php if ($nbMessages > 0): ?>
                    <span class="badge-count" style="display: flex;"><?= $nbMessages > 99 ? '99+' : $nbMessages ?></span>
                <?php else: ?>
                    <span class="badge-count" style="display: none;">0</span>
                <?php endif; ?>
            </button>

            <!-- Dropdown utilisateur -->
            <div class="navbar-user" id="userDropdown">
                <button type="button" class="user-dropdown-trigger" aria-haspopup="true" aria-expanded="false">
                    <img src="<?= !empty($utilisateurPhoto) ? $assetsUrl . 'uploads/photos_utilisateurs/' . $utilisateurPhoto : $assetsUrl . 'images/avatar_default.png' ?>"
                         alt="Photo"
                         class="user-avatar"
                         onerror="this.src='<?= $assetsUrl ?>images/avatar_default.png'">
                    <div class="user-info">
                        <span class="user-name"><?= e($utilisateurPrenom) ?></span>
                        <span class="user-role-text"><?= e($utilisateurRole) ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down user-dropdown-arrow"></i>
                </button>

                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <!-- En-tête -->
                    <div class="user-dropdown-header">
                        <img src="<?= !empty($utilisateurPhoto) ? $assetsUrl . 'uploads/photos_utilisateurs/' . $utilisateurPhoto : $assetsUrl . 'images/avatar_default.png' ?>"
                             alt="Photo"
                             onerror="this.src='<?= $assetsUrl ?>images/avatar_default.png'">
                        <div class="user-info">
                            <span class="user-name"><?= e($utilisateurPrenom . ' ' . $utilisateurNom) ?></span>
                            <span class="user-email"><?= e($utilisateurEmail) ?></span>
                        </div>
                    </div>

                    <!-- Liens -->
                    <div class="user-dropdown-links">
                        <a href="<?= $baseUrl ?>/profil">
                            <i class="fa-solid fa-user"></i> Mon profil
                        </a>
                        <a href="<?= $baseUrl ?>/profil/modifier">
                            <i class="fa-solid fa-pen-to-square"></i> Modifier mon profil
                        </a>
                        <a href="<?= $baseUrl ?>/profil/mot-de-passe">
                            <i class="fa-solid fa-lock"></i> Changer mot de passe
                        </a>
                        <div class="user-dropdown-divider"></div>
                        <a href="<?= $baseUrl ?>/aide">
                            <i class="fa-solid fa-circle-question"></i> Aide & Support
                        </a>
                        <a href="<?= $baseUrl ?>/parametres">
                            <i class="fa-solid fa-gear"></i> Paramètres
                        </a>
                        <div class="user-dropdown-divider"></div>
                        <form action="<?= $baseUrl ?>/logout" method="post" class="logout-form">
                            <input type="hidden" name="<?= e(CSRF_TOKEN_NAME) ?>" value="<?= e(generateCsrfToken()) ?>">
                            <button type="submit" class="logout-link">
                                <i class="fa-solid fa-right-from-bracket"></i> Déconnexion
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenu de la page -->
    <main class="page-content">

    <!-- Affichage des messages flash -->
    <?= displayFlash(); ?>