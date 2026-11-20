<?php

define('ACCESS', true);
require_once __DIR__ . '/../src/config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AuthRateLimitService.php';
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'EleveController.php';
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'NoteController.php';

$db = Database::getInstance();
$security = Security::getInstance();
$rateLimiter = new AuthRateLimitService($db);
$testIp = '192.0.2.' . random_int(1, 254);
$testEmail = 'auth-smoke-' . bin2hex(random_bytes(8)) . '@example.invalid';

try {
    foreach (['=1+1', ' +SUM(A1:A2)', "\t@SUM(1,1)", '-1'] as $formulaValue) {
        if (spreadsheetSafeValue($formulaValue)[0] !== "'") {
            throw new RuntimeException('Spreadsheet formula input was not neutralized.');
        }
    }
    if (spreadsheetSafeValue('Felix Baraka') !== 'Felix Baraka') {
        throw new RuntimeException('Plain spreadsheet text was unexpectedly changed.');
    }

    $initialPasswordHash = generateRandomPasswordHash();
    if (
        password_verify('Parent@2025', $initialPasswordHash)
        || password_verify('Enseignant@2025', $initialPasswordHash)
    ) {
        throw new RuntimeException('An account received a legacy shared password.');
    }

    if ($rateLimiter->recordFailure('login', $testIp, 2, 900, 900)) {
        throw new RuntimeException('Login limiter blocked before its threshold.');
    }
    if (!$rateLimiter->recordFailure('login', $testIp, 2, 900, 900)) {
        throw new RuntimeException('Login limiter did not block at its threshold.');
    }
    if (!$rateLimiter->isBlocked('login', $testIp)) {
        throw new RuntimeException('Login block was not persisted.');
    }
    if ($rateLimiter->recordFailure('password_reset', $testEmail, 2, 900, 900, 'email')) {
        throw new RuntimeException('Email limiter blocked before its threshold.');
    }
    if (!$rateLimiter->recordFailure('password_reset', $testEmail, 2, 900, 900, 'email')) {
        throw new RuntimeException('Email limiter did not block at its threshold.');
    }

    $adminId = (int) $db->fetchColumn(
        'SELECT id FROM utilisateurs WHERE role_id = 1 AND est_actif = 1 ORDER BY id LIMIT 1'
    );
    $directorId = (int) $db->fetchColumn(
        'SELECT id FROM utilisateurs WHERE role_id = 2 AND est_actif = 1 ORDER BY id LIMIT 1'
    );
    $targetId = (int) $db->fetchColumn(
        'SELECT id FROM utilisateurs WHERE role_id NOT IN (1, 2) ORDER BY id LIMIT 1'
    );
    if (!$adminId || !$directorId || !$targetId) {
        throw new RuntimeException('Seeded Admin, Director, and target users are required for this smoke test.');
    }

    $_SESSION['utilisateur_id'] = $adminId;
    if (!$security->canAssignRole(1, $targetId) || $security->canAssignRole(1, $adminId)) {
        throw new RuntimeException('Admin role assignment or self-role protection failed.');
    }

    $_SESSION['utilisateur_id'] = $directorId;
    if (!$security->canAssignRole(3, $targetId) || $security->canAssignRole(1, $targetId)) {
        throw new RuntimeException('Role hierarchy protection failed.');
    }

    $_SESSION[CSRF_TOKEN_NAME] = 'smoke-test-csrf-token';
    $_SESSION[CSRF_TOKEN_NAME . '_time'] = time();
    if (!verifyCsrfToken('smoke-test-csrf-token') || verifyCsrfToken('invalid-token')) {
        throw new RuntimeException('CSRF token validation failed.');
    }
    $_POST = [];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoke-test-csrf-token';
    if (csrfTokenFromRequest() !== 'smoke-test-csrf-token') {
        throw new RuntimeException('CSRF request-header token was not accepted.');
    }
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_SESSION[CSRF_TOKEN_NAME] = str_repeat('a', 64);
    $_SESSION[CSRF_TOKEN_NAME . '_time'] = time();
    if (generateCsrfToken() !== str_repeat('a', 64)) {
        throw new RuntimeException('CSRF token generation unexpectedly replaced a live token.');
    }

    $studentId = (int) $db->fetchColumn('SELECT id FROM eleves ORDER BY id LIMIT 1');
    $_SESSION['utilisateur_id'] = $adminId;
    $_SESSION['utilisateur_role'] = 'Admin';
    $_SESSION['utilisateur_role_id'] = 1;
    if ($studentId > 0 && !(new EleveController())->canCurrentUserAccessStudent($studentId)) {
        throw new RuntimeException('Authorized administrator student access failed.');
    }
    $evaluationId = (int) $db->fetchColumn('SELECT id FROM evaluations ORDER BY id LIMIT 1');
    if ($evaluationId > 0 && !(new NoteController())->canCurrentUserManageEvaluation($evaluationId)) {
        throw new RuntimeException('Authorized administrator evaluation access failed.');
    }

    $parent = $db->fetchOne(
        "SELECT u.id, u.role_id
         FROM utilisateurs u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
         INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
         WHERE r.nom = 'Parent' AND u.est_actif = 1
         ORDER BY u.id LIMIT 1"
    );
    if ($parent) {
        $_SESSION['utilisateur_id'] = (int) $parent['id'];
        $_SESSION['utilisateur_role'] = 'Parent';
        $_SESSION['utilisateur_role_id'] = (int) $parent['role_id'];
        $linkedStudentId = (int) $db->fetchColumn(
            "SELECT ep.eleve_id
             FROM eleve_parent ep
             INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
             WHERE pt.utilisateur_id = :user_id LIMIT 1",
            ['user_id' => $parent['id']]
        );
        if (!(new EleveController())->canCurrentUserAccessStudent($linkedStudentId)) {
            throw new RuntimeException('Parent could not access a linked student.');
        }
        $unlinkedStudentId = (int) $db->fetchColumn(
            "SELECT e.id FROM eleves e
             WHERE NOT EXISTS (
                 SELECT 1 FROM eleve_parent ep
                 INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                 WHERE ep.eleve_id = e.id AND pt.utilisateur_id = :user_id
             )
             ORDER BY e.id LIMIT 1",
            ['user_id' => $parent['id']]
        );
        if ($unlinkedStudentId > 0 && (new EleveController())->canCurrentUserAccessStudent($unlinkedStudentId)) {
            throw new RuntimeException('Parent accessed an unrelated student.');
        }
    }
    $teacher = $db->fetchOne(
        "SELECT u.id, u.role_id, ens.classe_id, ev.id AS evaluation_id
         FROM utilisateurs u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN enseignants ens ON ens.utilisateur_id = u.id AND ens.est_actif = 1
         INNER JOIN evaluations ev ON ev.classe_id = ens.classe_id
         INNER JOIN classes c ON c.id = ev.classe_id
         INNER JOIN trimestres t ON t.id = ev.trimestre_id
             AND t.annee_scolaire_id = c.annee_scolaire_id
         WHERE r.nom = 'Enseignant' AND u.est_actif = 1
           AND c.annee_scolaire_id = (
               SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
           )
         ORDER BY u.id, ev.id LIMIT 1"
    );
    if ($teacher) {
        $_SESSION['utilisateur_id'] = (int) $teacher['id'];
        $_SESSION['utilisateur_role'] = 'Enseignant';
        $_SESSION['utilisateur_role_id'] = (int) $teacher['role_id'];
        if (!(new NoteController())->canCurrentUserManageEvaluation((int) $teacher['evaluation_id'])) {
            throw new RuntimeException('Teacher could not access an evaluation for their assigned class.');
        }
        $unassignedEvaluationId = (int) $db->fetchColumn(
            "SELECT ev.id FROM evaluations ev
             INNER JOIN classes c ON c.id = ev.classe_id
             WHERE c.annee_scolaire_id = (
                 SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
             ) AND ev.classe_id <> :class_id
             ORDER BY ev.id LIMIT 1",
            ['class_id' => $teacher['classe_id']]
        );
        if (
            $unassignedEvaluationId > 0
            && (new NoteController())->canCurrentUserManageEvaluation($unassignedEvaluationId)
        ) {
            throw new RuntimeException('Teacher accessed an evaluation for an unassigned class.');
        }
    }

    $db->beginTransaction();
    try {
        $suffix = bin2hex(random_bytes(8));
        $userId = $db->insert(
            'INSERT INTO utilisateurs (nom, prenom, email, login, role_id, est_actif)
             VALUES (:nom, :prenom, :email, :login, 6, 1)',
            [
                'nom' => 'Smoke',
                'prenom' => 'Test',
                'email' => 'auth-reset-' . $suffix . '@example.invalid',
                'login' => 'auth-reset-' . $suffix
            ]
        );
        $tokenHash = hash('sha256', bin2hex(random_bytes(32)));
        $db->query(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
            ['user_id' => $userId, 'token_hash' => $tokenHash]
        );
        $token = $db->fetchOne(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND expires_at > NOW()
             FOR UPDATE',
            ['token_hash' => $tokenHash]
        );
        if ((int) ($token['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Reset token lookup failed.');
        }
        $db->delete(
            'DELETE FROM password_reset_tokens WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        if ($db->fetchOne(
            'SELECT user_id FROM password_reset_tokens WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash]
        )) {
            throw new RuntimeException('Consumed reset token remained usable.');
        }
    } finally {
        $db->rollback();
    }

    echo "Authentication and authorization smoke test passed.\n";
} finally {
    $rateLimiter->clear('login', $testIp);
    $rateLimiter->clear('password_reset', $testEmail, 'email');
}
