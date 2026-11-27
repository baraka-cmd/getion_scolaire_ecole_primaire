<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/PasswordResetService.php';

class PasswordResetController
{
    private PasswordResetService $service;

    public function __construct(?PasswordResetService $service = null)
    {
        $this->service = $service ?? new PasswordResetService();
    }

    public function handleForgotPassword(string $schoolName): array
    {
        $state = ['successMessage' => '', 'errorMessage' => '', 'errors' => [], 'oldEmail' => ''];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $state;
        }

        $state['oldEmail'] = post('email', '');
        if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
            http_response_code(419);
            $state['errorMessage'] = 'Session expirée. Actualisez la page puis réessayez.';
        } elseif (isBlank($state['oldEmail'])) {
            $state['errors']['email'] = 'Veuillez saisir votre adresse email.';
        } elseif (!isValidEmail($state['oldEmail'])) {
            $state['errors']['email'] = 'Veuillez saisir une adresse email valide.';
        }

        if (!$state['errors'] && $state['errorMessage'] === '') {
            $state['successMessage'] = 'Si cette adresse est associée à un compte actif, un lien de réinitialisation sera envoyé. Ce lien expirera dans 30 minutes.';
            try {
                $this->service->requestReset((string) $state['oldEmail'], $schoolName);
            } catch (Throwable $e) {
                error_log('Échec du traitement de réinitialisation de mot de passe (' . get_class($e) . ').');
            }
        }

        return $state;
    }

    public function handleResetPassword(): array
    {
        $token = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? (string) ($_POST['token'] ?? '')
            : (string) ($_GET['token'] ?? '');
        $reset = $this->service->findValidToken($token);
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
                        if ($this->service->resetPassword($token, $password)) {
                            redirect(BASE_URL . 'login?password_reset=1');
                        }
                        $errorMessage = 'Ce lien est invalide, expiré ou déjà utilisé.';
                    } catch (Throwable $e) {
                        error_log('Échec de validation du lien de réinitialisation (' . get_class($e) . ').');
                        $errorMessage = 'Une erreur technique est survenue. Veuillez réessayer.';
                    }
                }
            }
        }

        return ['token' => $token, 'reset' => $reset, 'errorMessage' => $errorMessage, 'errors' => $errors];
    }
}
