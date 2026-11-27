<?php

defined('ACCESS') or define('ACCESS', true);
require_once __DIR__ . '/../../../src/config/app.php';
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PasswordResetController.php';

if (isLoggedIn()) {
    redirect(BASE_URL . 'dashboard');
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$resetController = new PasswordResetController();
$resetState = $resetController->handleResetPassword();
$token = $resetState['token'];
$reset = $resetState['reset'];
$errorMessage = $resetState['errorMessage'];
$errors = $resetState['errors'];

$pageTitle = 'Choisir un nouveau mot de passe';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> — <?= e(ECOLE_NOM) ?></title>
    <link rel="stylesheet" href="<?= e(CSS_URL) ?>auth/login.css">
</head>
<body>
<main class="login-container">
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($reset): ?>
        <form method="post" action="<?= e(BASE_URL) ?>reset-password" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="password">Nouveau mot de passe</label>
            <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
            <?php if (isset($errors['password'])): ?>
                <p class="text-danger"><?= e($errors['password']) ?></p>
            <?php endif; ?>
            <label for="confirm_password">Confirmer le mot de passe</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="12" autocomplete="new-password" required>
            <?php if (isset($errors['confirm_password'])): ?>
                <p class="text-danger"><?= e($errors['confirm_password']) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Enregistrer le nouveau mot de passe</button>
        </form>
    <?php elseif ($errorMessage === ''): ?>
        <p>Ce lien est invalide ou expiré. Demandez une nouvelle réinitialisation.</p>
    <?php endif; ?>
    <p><a href="<?= e(BASE_URL) ?>login">Retour à la connexion</a></p>
</main>
</body>
</html>
