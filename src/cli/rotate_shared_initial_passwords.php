<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ACCESS', true);
require_once __DIR__ . '/../config/app.php';

$applyChanges = in_array('--apply', $argv, true);
$users = $db->fetchAll(
    'SELECT id, mot_de_passe
     FROM utilisateurs
     WHERE role_id IN (4, 6) AND mot_de_passe IS NOT NULL'
);
$legacyPasswords = ['Parent@2025', 'Enseignant@2025'];
$affectedUsers = [];

foreach ($users as $user) {
    foreach ($legacyPasswords as $legacyPassword) {
        if (password_verify($legacyPassword, (string) $user['mot_de_passe'])) {
            $affectedUsers[] = $user;
            break;
        }
    }
}

if (!$applyChanges) {
    echo count($affectedUsers) . " account(s) require password reset.\n";
    if ($affectedUsers) {
        echo "Run this command with --apply after backing up the database to invalidate those passwords.\n";
    }
    exit(0);
}

$rotated = 0;
$db->beginTransaction();
try {
    foreach ($affectedUsers as $user) {
        $rotated += $db->update(
            'UPDATE utilisateurs
             SET mot_de_passe = :new_password
             WHERE id = :id AND mot_de_passe = :old_password',
            [
                'new_password' => generateRandomPasswordHash(),
                'id' => $user['id'],
                'old_password' => $user['mot_de_passe']
            ]
        );
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('Échec de rotation des mots de passe initiaux partagés.');
    fwrite(STDERR, "Password rotation failed; no changes were committed.\n");
    exit(1);
}

echo $rotated . " account(s) rotated. Account holders must use the password-reset flow.\n";
