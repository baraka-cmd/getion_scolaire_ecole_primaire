<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/PasswordResetRepository.php';
require_once __DIR__ . '/AuthRateLimitService.php';
require_once __DIR__ . '/EmailService.php';

class PasswordResetService
{
    private PasswordResetRepository $repository;
    private AuthRateLimitService $rateLimiter;
    private EmailService $emailService;

    public function __construct(
        ?PasswordResetRepository $repository = null,
        ?AuthRateLimitService $rateLimiter = null,
        ?EmailService $emailService = null
    ) {
        $this->repository = $repository ?? new PasswordResetRepository();
        $this->rateLimiter = $rateLimiter ?? new AuthRateLimitService();
        $this->emailService = $emailService ?? new EmailService();
    }

    public function requestReset(string $email, string $schoolName): void
    {
        $clientIp = AuthRateLimitService::clientIp();
        $normalizedEmail = mb_strtolower(trim($email));

        $blocked = $this->rateLimiter->isBlocked('password_reset', $clientIp)
            || $this->rateLimiter->isBlocked('password_reset', $normalizedEmail, 'email');
        if (!$blocked) {
            $blocked = $this->rateLimiter->recordFailure('password_reset', $clientIp, 5, 900, 900)
                || $this->rateLimiter->recordFailure('password_reset', $normalizedEmail, 3, 3600, 3600, 'email');
        }
        if ($blocked) {
            return;
        }

        $user = $this->repository->findActiveUserByEmail($normalizedEmail);
        if (!$user) {
            return;
        }

        $canonicalUrl = APP_CANONICAL_URL;
        if (
            $canonicalUrl === ''
            && ENVIRONMENT === 'development'
            && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
        ) {
            $canonicalUrl = BASE_URL;
        }

        $parts = parse_url($canonicalUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $secure = is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https';
        $local = ENVIRONMENT === 'development'
            && strtolower((string) ($parts['scheme'] ?? '')) === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $valid = filter_var($canonicalUrl, FILTER_VALIDATE_URL) !== false
            && ($secure || $local)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);

        if (!$valid) {
            error_log('Réinitialisation mot de passe désactivée : APP_CANONICAL_URL doit être configurée en HTTPS.');
            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->repository->saveToken((int) $user['id'], $tokenHash);

        $resetUrl = rtrim($canonicalUrl, '/') . '/reset-password?token=' . rawurlencode($token);
        $content = '<p>Bonjour ' . e($user['prenom'] . ' ' . $user['nom']) . ',</p>'
            . '<p>Une demande de réinitialisation a été faite pour votre compte sur '
            . e($schoolName) . '.</p>'
            . '<p><a href="' . e($resetUrl) . '">Choisir un nouveau mot de passe</a></p>'
            . '<p>Ce lien est à usage unique et expire dans 30 minutes. Si vous n’avez pas demandé cette réinitialisation, ignorez ce message.</p>';

        if (!$this->emailService->envoyerTemplate(
            $user['email'],
            'Réinitialisation de votre mot de passe — ' . $schoolName,
            $content
        )) {
            $this->repository->deleteToken((int) $user['id'], $tokenHash);
            error_log('Échec SMTP pendant une demande de réinitialisation de mot de passe.');
        }
    }

    public function findValidToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        return $this->repository->findValidToken(hash('sha256', $token));
    }

    public function resetPassword(string $token, string $password): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);
        $success = $this->repository->consumeTokenAndUpdatePassword(hash('sha256', $token), $hash);
        if ($success) {
            $this->rateLimiter->clear('login', AuthRateLimitService::clientIp());
        }
        return $success;
    }
}
