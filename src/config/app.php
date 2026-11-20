<?php
/**
 * Fichier : src/config/app.php
 * Rôle     : Configuration générale, constantes et démarrage session
 * Version : 1.2 — Compatible local + InfinityFree
 */

defined('ACCESS') or die('Accès interdit');

// ============================================================
// CHARGEMENT DU FICHIER .ENV
// ============================================================
$envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $processValue = getenv($key);
            if ($processValue !== false) {
                $_ENV[$key] = $processValue;
            } elseif (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = trim($parts[1]);
            }
        }
    }
}

// ============================================================
// DÉTECTION ENVIRONNEMENT
// ============================================================
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$host = strtolower((string) parse_url('//' . $httpHost, PHP_URL_HOST));
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
define('ENVIRONMENT', $isLocal ? 'development' : 'production');

// ============================================================
// CONSTANTES DE L'APPLICATION
// ============================================================
define('APP_NAME', 'Gestion Scolaire');
define('APP_VERSION', '1.0.0');
define('APP_AUTHOR', 'BARAKA NTWALI');
define('APP_COLOR_THEMES', [
    'sapphire' => ['label' => 'Saphir', 'primary' => '#2c3e90', 'secondary' => '#3b4fa0'],
    'ocean' => ['label' => 'Océan', 'primary' => '#0369a1', 'secondary' => '#38bdf8'],
    'emerald' => ['label' => 'Émeraude', 'primary' => '#0f766e', 'secondary' => '#34d399'],
    'amethyst' => ['label' => 'Améthyste', 'primary' => '#6d28d9', 'secondary' => '#a78bfa'],
    'slate' => ['label' => 'Ardoise', 'primary' => '#334155', 'secondary' => '#64748b'],
]);

// ============================================================
// CHEMINS PHYSIQUES (serveur)
// ============================================================
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('PUBLIC_PATH', ROOT_PATH . 'public' . DIRECTORY_SEPARATOR);
define('VIEWS_PATH', PUBLIC_PATH . 'views' . DIRECTORY_SEPARATOR);
define('ASSETS_PATH', PUBLIC_PATH . 'assets' . DIRECTORY_SEPARATOR);
define('UPLOADS_PATH', ASSETS_PATH . 'uploads' . DIRECTORY_SEPARATOR);
define('STORAGE_PATH', ROOT_PATH . 'storage' . DIRECTORY_SEPARATOR);
define('DOCUMENTS_STORAGE_PATH', STORAGE_PATH . 'documents' . DIRECTORY_SEPARATOR);

// ============================================================
// URL DE BASE — Adaptatif local / production
// ============================================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
if ($isLocal) {
    define('BASE_URL', $protocol . $host . '/' . basename(ROOT_PATH) . '/');
    define('ASSETS_URL', BASE_URL . 'public/assets/');
} else {
    define('BASE_URL', $protocol . $host . '/');
    
    // Si sur InfinityFree les fichiers de public/ sont directement dans htdocs/
    if (is_dir(ROOT_PATH . 'assets')) {
        define('ASSETS_URL', BASE_URL . 'assets/');
    } else {
        define('ASSETS_URL', BASE_URL . 'public/assets/');
    }
}
define('APP_BASE_PATH', rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/'));

// Pointage dynamique vers les sous-dossiers d'assets
define('CSS_URL', ASSETS_URL . 'css/');
define('JS_URL', ASSETS_URL . 'js/');
define('IMAGES_URL', ASSETS_URL . 'images/');
define('FONTS_URL', ASSETS_URL . 'fonts/');
define('UPLOADS_URL', ASSETS_URL . 'uploads/');

// ============================================================
// SESSION
// ============================================================
define('SESSION_NAME', 'GS_SESSION');
define('SESSION_LIFETIME', 7200);
define('SESSION_TIMEOUT', 1800);

// ============================================================
// SÉCURITÉ
// ============================================================
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_LIFETIME', 3600);
define('PASSWORD_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_COST', 12);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_BLOCK_DURATION', 900);

// ============================================================
// CONFIGURATION SMTP / MAIL
// ============================================================
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);

// ============================================================
// ASSISTANT IA
// ============================================================
define('ASSISTANT_PROVIDER', strtolower(trim((string) ($_ENV['ASSISTANT_PROVIDER'] ?? 'gemini'))));
define('GEMINI_API_KEY', trim((string) ($_ENV['GEMINI_API_KEY'] ?? '')));
define('GEMINI_MODEL', trim((string) ($_ENV['GEMINI_MODEL'] ?? 'gemini-3.5-flash')));
define(
    'GEMINI_FALLBACK_MODELS',
    array_values(array_filter(array_map(
        'trim',
        explode(',', (string) ($_ENV['GEMINI_FALLBACK_MODELS'] ?? 'gemini-3-flash-preview,gemini-flash-lite-latest'))
    )))
);
define('GEMINI_API_TIMEOUT', max(5, (int) ($_ENV['GEMINI_API_TIMEOUT'] ?? 30)));
define('ASSISTANT_MAX_MESSAGE_LENGTH', max(500, (int) ($_ENV['ASSISTANT_MAX_MESSAGE_LENGTH'] ?? 4000)));
define('ASSISTANT_HISTORY_DAYS', max(1, (int) ($_ENV['ASSISTANT_HISTORY_DAYS'] ?? 60)));
define('ASSISTANT_RATE_LIMIT_WINDOW', max(60, (int) ($_ENV['ASSISTANT_RATE_LIMIT_WINDOW'] ?? 3600)));
define('ASSISTANT_RATE_LIMIT_REQUESTS', max(1, (int) ($_ENV['ASSISTANT_RATE_LIMIT_REQUESTS'] ?? 30)));
define('ASSISTANT_UPLOAD_MAX_SIZE', max(1024 * 1024, (int) ($_ENV['ASSISTANT_UPLOAD_MAX_SIZE'] ?? 5 * 1024 * 1024)));
define('ASSISTANT_IMPORT_MAX_ROWS', max(1, (int) ($_ENV['ASSISTANT_IMPORT_MAX_ROWS'] ?? 500)));

// ============================================================
// PAGINATION & UPLOAD
// ============================================================
define('ITEMS_PER_PAGE', 15);
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,csv');

// ============================================================
// GOOGLE OAUTH
// ============================================================
define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI', trim((string) ($_ENV['GOOGLE_REDIRECT_URI'] ?? '')));
define('APP_CANONICAL_URL', trim((string) ($_ENV['APP_CANONICAL_URL'] ?? '')));
define('PUSH_VAPID_PUBLIC_KEY', trim((string) ($_ENV['PUSH_VAPID_PUBLIC_KEY'] ?? '')));
define('PUSH_VAPID_PRIVATE_KEY', trim((string) ($_ENV['PUSH_VAPID_PRIVATE_KEY'] ?? '')));
define('PUSH_VAPID_SUBJECT', trim((string) ($_ENV['PUSH_VAPID_SUBJECT'] ?? '')));
define('PUSH_SUBSCRIPTION_ENCRYPTION_KEY', trim((string) ($_ENV['PUSH_SUBSCRIPTION_ENCRYPTION_KEY'] ?? '')));

// ============================================================
// ÉCOLE (valeurs par défaut)
// ============================================================
define('ECOLE_NOM', $_ENV['ECOLE_NOM'] ?? 'École Primaire LA RÉFÉRENCE');
define('ECOLE_DEVIS', $_ENV['ECOLE_DEVIS'] ?? 'Discipline - Travail - Excellence');
define('BAREME_NOTE_MAX', 20);
define('MOYENNE_PASSAGE', 10);

// ============================================================
// FUSEAU HORAIRE
// ============================================================
date_default_timezone_set('Africa/Lubumbashi');
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'fr');

// ============================================================
// GESTION DES ERREURS
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . 'logs' . DIRECTORY_SEPARATOR . 'php_errors.log');

// ============================================================
// DÉMARRAGE SESSION SÉCURISÉE
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => ($protocol === 'https://'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ============================================================
// INCLUSION BASE DE DONNÉES ET HELPERS
// ============================================================
require_once SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once SRC_PATH . 'helpers' . DIRECTORY_SEPARATOR . 'functions.php';

$db = Database::getInstance();

require_once SRC_PATH . 'core' . DIRECTORY_SEPARATOR . 'Security.php';
$security = Security::getInstance();