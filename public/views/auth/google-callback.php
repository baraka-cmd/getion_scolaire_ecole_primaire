<?php
/**
 * Fichier : views/auth/google-callback.php
 * Rôle     : Endpoint de retour (Callback) appelé par Google après authentification
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../src/config/app.php';
require_once SRC_PATH . 'services/GoogleAuthService.php';
require_once SRC_PATH . 'controllers/AuthController.php';

$loginBase = preg_replace('#/auth/google-callback2?$#', '', GOOGLE_REDIRECT_URI);
$loginUrl = rtrim((string) $loginBase, '/') . '/login';

if (isset($_GET['error'])) {
    unset($_SESSION['oauth2state'], $_SESSION['oauth2state_created_at'], $_SESSION['oauth2pkce_verifier']);
    redirect($loginUrl . '?error=google_cancelled');
}

$state = $_GET['state'] ?? '';
if (!is_string($state)) {
    $state = '';
}
$expectedState = (string) ($_SESSION['oauth2state'] ?? '');
$stateCreatedAt = (int) ($_SESSION['oauth2state_created_at'] ?? 0);
$codeVerifier = (string) ($_SESSION['oauth2pkce_verifier'] ?? '');
unset($_SESSION['oauth2state'], $_SESSION['oauth2state_created_at'], $_SESSION['oauth2pkce_verifier']);
if (
    $state === ''
    || $expectedState === ''
    || !preg_match('/^[a-f0-9]{64}$/', $codeVerifier)
    || $stateCreatedAt < time() - 600
    || $stateCreatedAt > time() + 60
    || !hash_equals($expectedState, $state)
) {
    redirect($loginUrl . '?error=invalid_state');
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    redirect($loginUrl . '?error=no_code');
}

$googleService = new GoogleAuthService();
$accessToken = $googleService->getAccessToken($code, $codeVerifier);

if (!$accessToken) {
    redirect($loginUrl . '?error=token_failed');
}

$googleUser = $googleService->getUserInfo($accessToken);

if (!$googleUser) {
    redirect($loginUrl . '?error=userinfo_failed');
}
if (($googleUser['email_verified'] ?? false) !== true) {
    redirect($loginUrl . '?error=google_email_unverified');
}

$authController = new AuthController();
$authController->handleGoogleCallback($googleUser);