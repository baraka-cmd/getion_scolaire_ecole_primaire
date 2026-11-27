<?php
/**
 * Fichier : src/controllers/AuthController.php
 * Rôle    : Traitement backend de l'authentification (login + register)
 */

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AuthRateLimitService.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'AuthAccountService.php';

class AuthController
{
    private AuthRateLimitService $rateLimiter;
    private AuthAccountService $accountService;

    public function __construct()
    {
        $this->rateLimiter = new AuthRateLimitService(Database::getInstance());
        $this->accountService = new AuthAccountService();
    }

    // ============================================================
    // LOGIN
    // ============================================================

    /**
     * Traite la connexion d'un utilisateur
     */
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Méthode non autorisée.', [], 405);
        }

        $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(false, 'Session expirée. Veuillez rafraîchir la page.');
        }

        $email    = post('email');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $errors = [];

        if (isBlank($email)) {
            $errors['email'] = 'L\'adresse email est obligatoire.';
        } elseif (!isValidEmail($email)) {
            $errors['email'] = 'Adresse email invalide.';
        }

        if (isBlank($password)) {
            $errors['password'] = 'Le mot de passe est obligatoire.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $clientIp = AuthRateLimitService::clientIp();
            if ($this->rateLimiter->isBlocked('login', $clientIp)) {
                jsonResponse(false, 'Trop de tentatives. Réessayez dans quelques minutes.', [], 429);
            }

            $utilisateur = $this->accountService->findByEmail((string) $email);

            if (!$utilisateur) {
                $this->logFailedAttempt(null, $email);
                $this->rateLimiter->recordFailure('login', $clientIp);
                jsonResponse(false, 'Email ou mot de passe incorrect.');
            }

            if (!$utilisateur['est_actif']) {
                jsonResponse(false, 'Email ou mot de passe incorrect.');
            }

            if (!empty($utilisateur['bloque_jusqua'])) {
                $bloqueJusqua = strtotime($utilisateur['bloque_jusqua']);
                if ($bloqueJusqua > time()) {
                    jsonResponse(false, 'Email ou mot de passe incorrect.');
                } else {
                    $this->resetLoginAttempts($utilisateur['id']);
                }
            }

            if (!password_verify($password, $utilisateur['mot_de_passe'])) {
                $this->incrementLoginAttempts($utilisateur);
                $this->rateLimiter->recordFailure('login', $clientIp);
                jsonResponse(false, 'Email ou mot de passe incorrect.');
            }

            if (password_needs_rehash($utilisateur['mot_de_passe'], PASSWORD_ALGO, ['cost' => PASSWORD_COST])) {
                $this->updatePasswordHash($utilisateur['id'], $password);
            }

            $this->handleSuccessfulLogin($utilisateur, $remember);

            logActivity('Connexion', 'utilisateurs', $utilisateur['id'], 'Connexion réussie');

            $redirect = BASE_URL . 'dashboard';

            jsonResponse(true, 'Connexion réussie. Redirection en cours...', [
                'redirect' => $redirect
            ]);

        } catch (Exception $e) {
            error_log('Erreur login : ' . $e->getMessage());
            jsonResponse(false, 'Une erreur technique est survenue.');
        }
    }

    // ============================================================
    // REGISTER
    // ============================================================

    /**
     * Traite l'inscription d'un nouvel utilisateur
     */
    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Méthode non autorisée.', [], 405);
        }

        $csrfToken = $_POST[CSRF_TOKEN_NAME] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(false, 'Session expirée. Veuillez rafraîchir la page.');
        }

        // Récupérer les champs
        $nom             = post('nom');
        $prenom          = post('prenom');
        $email           = post('email');
        $telephone       = post('telephone');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $roleId          = (int) post('role_id', 0);
        $terms           = isset($_POST['terms']);

        // Validation
        $errors = [];

        // Nom
        if (isBlank($nom)) {
            $errors['nom'] = 'Le nom est obligatoire.';
        } elseif (mb_strlen($nom) < 2) {
            $errors['nom'] = 'Minimum 2 caractères.';
        } elseif (mb_strlen($nom) > 100) {
            $errors['nom'] = 'Maximum 100 caractères.';
        } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s\'\-]+$/u', $nom)) {
            $errors['nom'] = 'Caractères non autorisés.';
        }

        // Prénom
        if (isBlank($prenom)) {
            $errors['prenom'] = 'Le prénom est obligatoire.';
        } elseif (mb_strlen($prenom) < 2) {
            $errors['prenom'] = 'Minimum 2 caractères.';
        } elseif (mb_strlen($prenom) > 100) {
            $errors['prenom'] = 'Maximum 100 caractères.';
        } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s\'\-]+$/u', $prenom)) {
            $errors['prenom'] = 'Caractères non autorisés.';
        }

        // Email
        if (isBlank($email)) {
            $errors['email'] = 'L\'adresse email est obligatoire.';
        } elseif (!isValidEmail($email)) {
            $errors['email'] = 'Adresse email invalide.';
        } elseif (mb_strlen($email) > 150) {
            $errors['email'] = 'Maximum 150 caractères.';
        } else {
            // Vérifier l'unicité de l'email
            $emailExists = $this->accountService->emailExists(mb_strtolower(trim((string) $email)));
            if ($emailExists) {
                $errors['email'] = 'Cette adresse email est déjà utilisée.';
            }
        }

        // Téléphone (optionnel mais validé si fourni)
        if (!isBlank($telephone)) {
            if (!isValidPhone($telephone)) {
                $errors['telephone'] = 'Numéro de téléphone invalide.';
            }
        }

        // Mot de passe
        if (isBlank($password)) {
            $errors['password'] = 'Le mot de passe est obligatoire.';
        } elseif (mb_strlen($password) < 8) {
            $errors['password'] = 'Minimum 8 caractères.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Au moins une lettre majuscule.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors['password'] = 'Au moins une lettre minuscule.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Au moins un chiffre.';
        } elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'\"\\|,.<>\/?~`]/', $password)) {
            $errors['password'] = 'Au moins un caractère spécial.';
        }

        // Confirmation
        if (isBlank($confirmPassword)) {
            $errors['confirm_password'] = 'Veuillez confirmer le mot de passe.';
        } elseif ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }

        // Rôle
        $allowedRoles = [4, 6]; // Enseignant (4) ou Parent (6) seulement à l'inscription
        if (!in_array($roleId, $allowedRoles)) {
            $errors['role_id'] = 'Rôle invalide.';
        }

        // Conditions
        if (!$terms) {
            $errors['terms'] = 'Vous devez accepter les conditions d\'utilisation.';
        }

        // Retourner les erreurs s'il y en a
        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        // Créer l'utilisateur
        try {
            // Générer le login et confier la persistance transactionnelle au service.
            $login = $this->generateUniqueLogin($nom, $prenom);
            $hashedPassword = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);

            $userId = $this->accountService->createRegisteredUser([
                'nom'       => formatNom($nom),
                'prenom'    => formatNom($prenom),
                'email'     => mb_strtolower(trim($email)),
                'telephone' => $telephone ?: null,
                'login'     => $login,
                'password'  => $hashedPassword,
                'role_id'   => $roleId
            ]);

            // Journaliser
            logActivity('Création', 'utilisateurs', $userId, "Inscription de {$nom} {$prenom}");

            // Message de succès
            $roleNom = $roleId === 4 ? 'Enseignant' : 'Parent';
            jsonResponse(true, "Inscription réussie ! Votre compte {$roleNom} a été créé. Vous pouvez maintenant vous connecter.", [
                'redirect' => '/gestion_scolaire/login?created=1'
            ]);

        } catch (Exception $e) {
            error_log('Erreur inscription : ' . $e->getMessage());

            // Vérifier si c'est une erreur de doublon (login)
            if (str_contains($e->getMessage(), 'login')) {
                jsonResponse(false, 'Ce login est déjà utilisé. Veuillez réessayer.');
            }

            jsonResponse(false, 'Une erreur technique est survenue lors de l\'inscription.');
        }
    }

    // ============================================================
    // LOGOUT
    // ============================================================

    /**
     * Déconnecte l'utilisateur
     */
    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            redirect(BASE_URL . 'dashboard');
        }
        if (isLoggedIn()) {
            Security::getInstance()->validateCsrf();
        }

        // 1. Enregistrer l'activité si connecté
        if (function_exists('isLoggedIn') && isLoggedIn()) {
            if (function_exists('logActivity')) {
                logActivity('Déconnexion', 'utilisateurs', $_SESSION['utilisateur_id'] ?? 0, 'Déconnexion réussie');
            }
        }

        // 2. Vider la session
        $_SESSION = [];

        // 3. Détruire le cookie de session
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // 4. Détruire la session côté serveur
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // 5. Calculer la route de redirection propre (s'adapte à InfinityFree et Local)
        $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $isLocalHost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']);
        
        if (!$isLocalHost) {
            // Sur InfinityFree, on retire le préfixe s'il existe
            $baseUrl = str_replace('/gestion_scolaire', '', $baseUrl);
        }

        // 6. REDIRECTION VERS LA ROUTE (et non vers le fichier physique)
        $redirectUrl = $baseUrl . '/login?logout=success';

        // Utilisation de votre fonction redirect si elle existe, sinon header classique
        if (function_exists('redirect')) {
            redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    private function handleSuccessfulLogin(array $utilisateur, bool $remember): void
    {
        $this->resetLoginAttempts($utilisateur['id']);
        $this->rateLimiter->clear('login', AuthRateLimitService::clientIp());
        $this->accountService->recordSuccessfulLogin(
            (int) $utilisateur['id'],
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
        );

        session_regenerate_id(true);

        $_SESSION['utilisateur_id']       = $utilisateur['id'];
        $_SESSION['utilisateur_nom']      = $utilisateur['nom'];
        $_SESSION['utilisateur_prenom']   = $utilisateur['prenom'];
        $_SESSION['utilisateur_email']    = $utilisateur['email'];
        $_SESSION['utilisateur_login']    = $utilisateur['login'];
        $_SESSION['utilisateur_role_id'] = $utilisateur['role_id']; // Correction clé : enregistrement de l'ID du rôle pour Security.php
        $_SESSION['utilisateur_role']     = $utilisateur['role_nom'];
        $_SESSION['utilisateur_photo']    = $utilisateur['photo'];
        $_SESSION['utilisateur_niveau']   = $utilisateur['niveau_acces'];
        $_SESSION['session_time']         = time();

        if ($remember) {
            $this->setRememberMeCookie($utilisateur['id']);
        }
    }

    private function incrementLoginAttempts(array $utilisateur): void
    {
        $tentatives = $utilisateur['tentatives_echecs'] + 1;
        $bloqueJusqua = null;

        if ($tentatives >= MAX_LOGIN_ATTEMPTS) {
            $bloqueJusqua = date('Y-m-d H:i:s', time() + LOGIN_BLOCK_DURATION);
        }

        $this->accountService->recordFailedLogin(
            (int) $utilisateur['id'],
            $tentatives,
            $bloqueJusqua
        );

        logActivity('Tentative échouée', 'utilisateurs', $utilisateur['id'],
            "Tentative {$tentatives}/" . MAX_LOGIN_ATTEMPTS);
    }

    private function resetLoginAttempts(int $userId): void
    {
        $this->accountService->resetLoginAttempts($userId);
    }

    private function updatePasswordHash(int $userId, string $password): void
    {
        $this->accountService->updatePasswordHash($userId, $password);
    }

    private function logFailedAttempt(?int $userId, string $email): void
    {
        try {
            $this->accountService->logFailedAttempt(
                $userId,
                $email,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Inconnu'
            );
        } catch (Exception $e) {
            error_log('Erreur logFailedAttempt: ' . $e->getMessage());
        }
    }

    /**
     * Traite le retour d'authentification Google OAuth
     */
    public function handleGoogleCallback(array $googleUser): void
    {
        $baseUrl = preg_replace('#/auth/google-callback2?$#', '', GOOGLE_REDIRECT_URI);
        $baseUrl = rtrim((string) $baseUrl, '/');
        $loginUrl = $baseUrl . '/login';
        $dashboardUrl = $baseUrl . '/dashboard';

        // Extraction des données Google
        $googleId = is_string($googleUser['sub'] ?? null) ? trim($googleUser['sub']) : '';
        $email = is_string($googleUser['email'] ?? null)
            ? mb_strtolower(trim($googleUser['email']))
            : '';
        $nom = is_string($googleUser['family_name'] ?? null) ? $googleUser['family_name'] : 'Utilisateur';
        $prenom = is_string($googleUser['given_name'] ?? null) ? $googleUser['given_name'] : 'Google';
        $picture = is_string($googleUser['picture'] ?? null) ? $googleUser['picture'] : null;

        if (
            $googleId === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($googleUser['email_verified'] ?? false) !== true
        ) {
            header('Location: ' . $loginUrl . '?error=google_data_invalid');
            exit();
        }

        try {
            // 1. Chercher si l'utilisateur existe déjà via son google_id
            $utilisateur = $this->accountService->findByGoogleId($googleId);

            // 2. Si non trouvé par google_id, vérifier par adresse email
            if (!$utilisateur) {
                $utilisateur = $this->accountService->findByEmailForGoogle($email);

                if ($utilisateur) {
                    if (empty($utilisateur['est_actif'])) {
                        header('Location: ' . $loginUrl . '?error=account_disabled');
                        exit();
                    }
                    if (!empty($utilisateur['google_id']) && !hash_equals((string) $utilisateur['google_id'], $googleId)) {
                        header('Location: ' . $loginUrl . '?error=google_account_conflict');
                        exit();
                    }
                    $this->accountService->linkGoogleId((int) $utilisateur['id'], $googleId);
                } else {
                    // 3. Aucun compte n'existe : CRÉATION AUTOMATIQUE (Inscription)
                    $login  = $this->generateUniqueLogin($nom, $prenom);
                    $roleId = 6; // ID du rôle par défaut

                    $userId = $this->accountService->createGoogleUserTransactional([
                        'nom'       => formatNom($nom),
                        'prenom'    => formatNom($prenom),
                        'email'     => $email,
                        'login'     => $login,
                        'google_id' => $googleId,
                        'photo'     => $picture,
                        'role_id'   => $roleId,
                    ]);

                    logActivity('Création via Google', 'utilisateurs', $userId, "Nouvelle inscription Google : {$email}");

                    // Récupérer les données complètes avec le rôle
                    $utilisateur = $this->accountService->findByGoogleId($googleId);
                }
            }

            // Vérification de la validité du compte
            if (isset($utilisateur['est_actif']) && !$utilisateur['est_actif']) {
                header('Location: ' . $loginUrl . '?error=account_disabled');
                exit();
            }

            // Connexion effective (Remplissage de la SESSION)
            $this->handleSuccessfulLogin($utilisateur, true);
            logActivity('Connexion Google', 'utilisateurs', $utilisateur['id'], 'Connexion réussie via Google OAuth');

            // Redirection propre vers la ROUTE du tableau de bord (/dashboard)
            header('Location: ' . $dashboardUrl);
            exit();

        } catch (Exception $e) {
            error_log('Erreur handleGoogleCallback : ' . $e->getMessage());
            header('Location: ' . $loginUrl . '?error=technical');
            exit();
        }
    }

    private function setRememberMeCookie(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expire = time() + (30 * 24 * 3600);

        setcookie(
            'remember_token',
            $userId . ':' . $token,
            [
                'expires'  => $expire,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Génère un login unique à partir du nom et prénom
     */
    private function generateUniqueLogin(string $nom, string $prenom): string
    {
        // Nettoyer et formater
        $nomClean    = slugify($nom);
        $prenomClean = slugify($prenom);

        // Base du login : première lettre prénom + nom
        $baseLogin = mb_substr($prenomClean, 0, 1) . $nomClean;

        // Tronquer à 20 caractères max
        $baseLogin = mb_substr($baseLogin, 0, 20);

        // Vérifier l'unicité et ajouter un suffixe si nécessaire
        $login = $baseLogin;
        $counter = 1;

        while ($this->accountService->loginExists($login)) {
            $login = $baseLogin . $counter;
            $counter++;
        }

        return $login;
    }

    /**
     * Redirige l'utilisateur vers la page d'authentification de Google
     */
    public function redirectToGoogle(): void
    {
        require_once SRC_PATH . 'services/GoogleAuthService.php';
        $googleService = new GoogleAuthService();
        try {
            $authUrl = $googleService->getAuthUrl();
        } catch (Throwable $e) {
            error_log('Google OAuth is not configured correctly: ' . $e->getMessage());
            redirect(BASE_URL . 'login?error=google_not_configured');
        }

        header('Location: ' . $authUrl, true, 302);
        exit();
    }
}
