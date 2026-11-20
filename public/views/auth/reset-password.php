<?php

defined('ACCESS') or define('ACCESS', true);
require_once __DIR__ . '/../../../src/config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AuthRateLimitService.php';

if (isLoggedIn()) {
    redirect(BASE_URL . 'dashboard');
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['token'] ?? '')
    : (string) ($_GET['token'] ?? '');
$tokenValidFormat = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$reset = $tokenValidFormat
    ? $db->fetchOne(
        'SELECT user_id FROM password_reset_tokens
         WHERE token_hash = :token_hash AND expires_at > NOW()',
        ['token_hash' => hash('sha256', $token)]
    )
    : null;
$errorMessage = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        http_response_code(419);
        $errorMessage = 'Session expirée. Actualisez la page puis réessayez.';
    } elseif (!$reset) {
        $errorMessage = 'Ce lien est invalide ou expiré. Demandez une nouvelle réinitialisation.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');

        if (mb_strlen($password) < 12) {
            $errors['password'] = 'Le mot de passe doit contenir au moins 12 caractères.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Le mot de passe est trop long. La limite sécurisée est de 72 octets.';
        } elseif (
            !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password)
        ) {
            $errors['password'] = 'Utilisez au moins une majuscule, une minuscule et un chiffre.';
        }
        if ($password !== $confirmation) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }

        if (!$errors) {
            try {
                $db->beginTransaction();
                $lockedReset = $db->fetchOne(
                    'SELECT user_id FROM password_reset_tokens
                     WHERE token_hash = :token_hash AND expires_at > NOW()
                     FOR UPDATE',
                    ['token_hash' => hash('sha256', $token)]
                );
                if (!$lockedReset) {
                    $db->rollback();
                    $errorMessage = 'Ce lien est invalide, expiré ou déjà utilisé.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);
                    $updated = $db->update(
                        'UPDATE utilisateurs
                         SET mot_de_passe = :password, tentatives_echecs = 0, bloque_jusqua = NULL
                         WHERE id = :user_id AND est_actif = 1',
                        ['password' => $hashedPassword, 'user_id' => $lockedReset['user_id']]
                    );
                    if ($updated !== 1) {
                        $db->rollback();
                        $errorMessage = 'Ce lien est invalide ou expiré. Demandez une nouvelle réinitialisation.';
                    } else {
                        $db->delete(
                            'DELETE FROM password_reset_tokens WHERE user_id = :user_id',
                            ['user_id' => $lockedReset['user_id']]
                        );
                        $db->commit();
                        (new AuthRateLimitService($db))->clear('login', AuthRateLimitService::clientIp());
                        redirect(BASE_URL . 'login?password_reset=1');
                    }
                }
            } catch (Throwable $e) {
                if ($db->getPdo()->inTransaction()) {
                    $db->rollback();
                }
                error_log('Échec de validation du lien de réinitialisation (' . get_class($e) . ').');
                $errorMessage = 'Une erreur technique est survenue. Veuillez réessayer.';
            }
        }
    }
}

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
