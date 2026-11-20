<?php
/**
 * Fichier : public/views/auth/forgot-password.php
 * Rôle    : Formulaire de récupération de mot de passe (page autonome)
 * Système : École primaire congolaise — Authentification
 * Version : 1.1 — Sans header/sidebar
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../src/config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AuthRateLimitService.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'EmailService.php';

// Si déjà connecté, rediriger vers le dashboard
if (isLoggedIn()) {
    redirect('/dashboard');
}

$successMessage = '';
$errorMessage   = '';
$errors         = [];
$oldEmail       = '';

// Récupérer les paramètres de l'école
$ecoleParams = $_SESSION['ecole_params'] ?? [];
$ecoleNom = $ecoleParams['nom_ecole'] ?? ECOLE_NOM;
$ecoleLogo = $ecoleParams['logo'] ?? '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldEmail = post('email', '');

    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        http_response_code(419);
        $errorMessage = 'Session expirée. Actualisez la page puis réessayez.';
    } elseif (isBlank($oldEmail)) {
        $errors['email'] = 'Veuillez saisir votre adresse email.';
    } elseif (!isValidEmail($oldEmail)) {
        $errors['email'] = 'Veuillez saisir une adresse email valide.';
    }

    if (empty($errors) && $errorMessage === '') {
        $successMessage = 'Si cette adresse est associée à un compte actif, un lien de réinitialisation sera envoyé. Ce lien expirera dans 30 minutes.';
        $rateLimiter = new AuthRateLimitService($db);
        $clientIp = AuthRateLimitService::clientIp();
        $normalizedEmail = mb_strtolower(trim($oldEmail));

        try {
            $blocked = $rateLimiter->isBlocked('password_reset', $clientIp)
                || $rateLimiter->isBlocked('password_reset', $normalizedEmail, 'email');
            if (!$blocked) {
                $blocked = $rateLimiter->recordFailure('password_reset', $clientIp, 5, 900, 900)
                    || $rateLimiter->recordFailure('password_reset', $normalizedEmail, 3, 3600, 3600, 'email');
            }
            if (!$blocked) {
                $utilisateur = $db->fetchOne(
                    "SELECT id, nom, prenom, email
                     FROM utilisateurs
                     WHERE email = :email AND est_actif = 1
                     LIMIT 1",
                    ['email' => $normalizedEmail]
                );

                if ($utilisateur) {
                    $canonicalUrl = APP_CANONICAL_URL;
                    if (
                        $canonicalUrl === ''
                        && ENVIRONMENT === 'development'
                        && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
                    ) {
                        $canonicalUrl = BASE_URL;
                    }

                    $canonicalParts = parse_url($canonicalUrl);
                    $canonicalHost = is_array($canonicalParts) ? strtolower((string) ($canonicalParts['host'] ?? '')) : '';
                    $isSecureCanonicalUrl = is_array($canonicalParts)
                        && strtolower((string) ($canonicalParts['scheme'] ?? '')) === 'https';
                    $isLocalCanonicalUrl = ENVIRONMENT === 'development'
                        && strtolower((string) ($canonicalParts['scheme'] ?? '')) === 'http'
                        && in_array($canonicalHost, ['localhost', '127.0.0.1', '::1'], true);
                    $canonicalUrlValid = filter_var($canonicalUrl, FILTER_VALIDATE_URL) !== false
                        && ($isSecureCanonicalUrl || $isLocalCanonicalUrl)
                        && !isset($canonicalParts['user'])
                        && !isset($canonicalParts['pass'])
                        && !isset($canonicalParts['query'])
                        && !isset($canonicalParts['fragment']);

                    if (!$canonicalUrlValid) {
                        error_log('Réinitialisation mot de passe désactivée : APP_CANONICAL_URL doit être configurée en HTTPS.');
                    } else {
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                        $db->query(
                            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                             ON DUPLICATE KEY UPDATE
                                 token_hash = VALUES(token_hash),
                                 expires_at = VALUES(expires_at),
                                 created_at = NOW()",
                            ['user_id' => $utilisateur['id'], 'token_hash' => $tokenHash]
                        );

                        $resetUrl = rtrim($canonicalUrl, '/') . '/reset-password?token=' . rawurlencode($token);
                        $content = '<p>Bonjour ' . e($utilisateur['prenom'] . ' ' . $utilisateur['nom']) . ',</p>'
                            . '<p>Une demande de réinitialisation a été faite pour votre compte sur '
                            . e($ecoleNom) . '.</p>'
                            . '<p><a href="' . e($resetUrl) . '">Choisir un nouveau mot de passe</a></p>'
                            . '<p>Ce lien est à usage unique et expire dans 30 minutes. Si vous n’avez pas demandé cette réinitialisation, ignorez ce message.</p>';

                        if (!(new EmailService())->envoyerTemplate(
                            $utilisateur['email'],
                            'Réinitialisation de votre mot de passe — ' . $ecoleNom,
                            $content
                        )) {
                            $db->delete(
                                'DELETE FROM password_reset_tokens WHERE user_id = :user_id AND token_hash = :token_hash',
                                ['user_id' => $utilisateur['id'], 'token_hash' => $tokenHash]
                            );
                            error_log('Échec SMTP pendant une demande de réinitialisation de mot de passe.');
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Échec du traitement de réinitialisation de mot de passe (' . get_class($e) . ').');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Récupération de mot de passe — <?= e($ecoleNom) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2c3e90">

    <title>Mot de passe oublié — <?= e($ecoleNom) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/public/assets/images/favicon.ico">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="/public/assets/fontawesome/css/all.min.css">

    <!-- Styles communs -->
    <link rel="stylesheet" href="/public/assets/css/common/variables.css">
    <link rel="stylesheet" href="/public/assets/css/common/reset.css">
    <link rel="stylesheet" href="/public/assets/css/common/layout.css">

    <!-- Styles de la page -->
    <link rel="stylesheet" href="/public/assets/css/auth/forgot-password.css">

    <style>
        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
    </style>
</head>
<body>

<!-- ============================================================
     CONTENEUR PRINCIPAL
     ============================================================ -->
<div class="forgot-password-wrapper">
    <div class="forgot-password-container">
        
        <!-- Logo et titre -->
        <div class="forgot-password-header">
            <div class="forgot-password-logo">
                <?php 
                $logoPath = UPLOADS_PATH . 'logos' . DIRECTORY_SEPARATOR . basename($ecoleLogo);
                if (!empty($ecoleLogo) && file_exists($logoPath)): 
                ?>
                    <img src="<?= ASSETS_URL . 'uploads/logos/' . basename($ecoleLogo) ?>" alt="Logo" class="forgot-password-logo-img">
                <?php else: ?>
                    <div class="forgot-password-logo-icon">
                        <i class="fa-solid fa-school"></i>
                    </div>
                <?php endif; ?>
            </div>
            <h1><?= e($ecoleNom) ?></h1>
            <p>Récupération de mot de passe</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($successMessage)): ?>
            <div class="forgot-password-success">
                <div class="success-icon">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="success-content">
                    <h5>Demande reçue</h5>
                    <p><?= nl2br(e($successMessage)) ?></p>
                </div>
                <a href="/login" class="btn btn-primary btn-block mt-3">
                    <i class="fa-solid fa-arrow-left"></i> Retour à la connexion
                </a>
            </div>
        <?php else: ?>
            <!-- Icône cadenas -->
            <div class="forgot-password-icon">
                <i class="fa-solid fa-lock"></i>
            </div>

            <!-- Message d'erreur -->
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= e($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <form method="POST" action="" autocomplete="off" novalidate id="formForgotPassword">
                <?= csrfField() ?>

                <p class="forgot-password-instruction">
                    <i class="fa-solid fa-info-circle"></i>
                    Saisissez votre adresse email. Si elle correspond à un compte actif, vous recevrez un lien temporaire pour choisir un nouveau mot de passe.
                </p>

                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fa-solid fa-envelope"></i> Adresse email
                    </label>
                    <div class="input-icon-wrapper">
                        <span class="input-icon"><i class="fa-solid fa-at"></i></span>
                        <input type="email" id="email" name="email" 
                               class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($oldEmail) ?>"
                               placeholder="votre.email@exemple.com"
                               required autofocus autocomplete="email">
                    </div>
                    <?php if (isset($errors['email'])): ?>
                        <span class="invalid-feedback"><?= e($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" id="btnSubmit">
                    <i class="fa-solid fa-paper-plane"></i> Envoyer le lien de réinitialisation
                </button>
            </form>
        <?php endif; ?>

        <!-- Lien retour -->
        <div class="forgot-password-footer">
            <a href="/login">
                <i class="fa-solid fa-arrow-left"></i> Retour à la connexion
            </a>
        </div>
    </div>

    <!-- Illustration décorative -->
    <div class="forgot-password-illustration">
        <div class="illustration-content">
            <i class="fa-solid fa-shield-halved illustration-icon"></i>
            <h2>Sécurité renforcée</h2>
            <p>Un lien temporaire et à usage unique permet de choisir un nouveau mot de passe.</p>
            <div class="illustration-features">
                <div class="feature-item">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Mot de passe protégé par hachage</span>
                </div>
                <div class="feature-item">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Majuscules et minuscules</span>
                </div>
                <div class="feature-item">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Chiffres et caractères spéciaux</span>
                </div>
                <div class="feature-item">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Lien à usage unique, valable 30 minutes</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="/public/assets/js/auth/forgot-password.js"></script>

</body>
</html>