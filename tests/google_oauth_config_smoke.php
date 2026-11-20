<?php

define('ACCESS', true);
define('GOOGLE_CLIENT_ID', 'test-client-id');
define('GOOGLE_CLIENT_SECRET', 'test-client-secret');
define('GOOGLE_REDIRECT_URI', 'http://localhost/gestion_scolaire_ecole_primaire/auth/google-callback2');

require_once __DIR__ . '/../src/services/GoogleAuthService.php';

$url = (new GoogleAuthService())->getAuthUrl();
$parameters = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

$valid =
    ($parameters['redirect_uri'] ?? '') === GOOGLE_REDIRECT_URI
    && preg_match('/^[a-f0-9]{64}$/', (string) ($parameters['state'] ?? '')) === 1
    && ($parameters['code_challenge_method'] ?? '') === 'S256'
    && preg_match('/^[A-Za-z0-9_-]{43}$/', (string) ($parameters['code_challenge'] ?? '')) === 1
    && strpos($url, GOOGLE_CLIENT_SECRET) === false;

if (!$valid) {
    fwrite(STDERR, "Google OAuth configuration smoke test failed.\n");
    exit(1);
}

if ((new GoogleAuthService())->getAccessToken('test-code', 'invalid-verifier') !== null) {
    fwrite(STDERR, "Google OAuth accepted an invalid PKCE verifier.\n");
    exit(1);
}

echo "Google OAuth configuration smoke test passed.\n";
