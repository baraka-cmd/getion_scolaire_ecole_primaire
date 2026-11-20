<?php
/**
 * Fichier : src/services/GoogleAuthService.php
 * Rôle     : Gestion des requêtes REST vers Google OAuth 2.0
 */

defined('ACCESS') or die('Accès interdit');

class GoogleAuthService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private const REQUEST_TIMEOUT = 20;

    public function __construct()
    {
        $this->clientId = trim((string) GOOGLE_CLIENT_ID);
        $this->clientSecret = trim((string) GOOGLE_CLIENT_SECRET);
        $this->redirectUri = trim((string) GOOGLE_REDIRECT_URI);
    }

    private function isConfigured(): bool
    {
        if ($this->clientId === '' || $this->clientSecret === '' || !filter_var($this->redirectUri, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($this->redirectUri);
        if (
            !is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            return false;
        }

        return preg_match('#/auth/google-callback2?$#', rtrim($parts['path'] ?? '', '/')) === 1;
    }

    /**
     * Génère l'URL d'autorisation Google OAuth 2.0 avec protection CSRF
     */
    public function getAuthUrl(): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('La configuration OAuth Google est absente ou invalide.');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['oauth2state'] = bin2hex(random_bytes(32));
        $_SESSION['oauth2state_created_at'] = time();
        $codeVerifier = bin2hex(random_bytes(32));
        $_SESSION['oauth2pkce_verifier'] = $codeVerifier;

        $params = [
            'client_id'     => trim($this->clientId),
            'redirect_uri'  => trim($this->redirectUri),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'state'         => $_SESSION['oauth2state'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt'        => 'select_account'
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Échange le code d'autorisation contre un Access Token
     */
    public function getAccessToken(string $code, string $codeVerifier): ?string
    {
        if (
            !$this->isConfigured()
            || $code === ''
            || !preg_match('/^[a-f0-9]{64}$/', $codeVerifier)
            || !function_exists('curl_init')
        ) {
            return null;
        }

        $url = 'https://oauth2.googleapis.com/token';

        $params = [
            'code'          => $code,
            'client_id'     => trim($this->clientId),
            'client_secret' => trim($this->clientSecret),
            'redirect_uri'  => trim($this->redirectUri),
            'code_verifier' => $codeVerifier,
            'grant_type'    => 'authorization_code'
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $error !== '' || $status !== 200) {
            error_log('Google OAuth token exchange failed; HTTP status ' . $status . ($error !== '' ? ', cURL error: ' . $error : ''));
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) && !empty($data['access_token']) ? (string) $data['access_token'] : null;
    }

    /**
     * Récupère les informations du profil utilisateur via le token d'accès
     */
    public function getUserInfo(string $accessToken): ?array
    {
        if ($accessToken === '' || !function_exists('curl_init')) {
            return null;
        }

        $url = 'https://www.googleapis.com/oauth2/v3/userinfo';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT
        ]);
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $error !== '' || $status !== 200) {
            error_log('Google OAuth userinfo request failed; HTTP status ' . $status . ($error !== '' ? ', cURL error: ' . $error : ''));
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}