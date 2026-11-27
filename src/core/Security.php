<?php
/**
 * Fichier : src/core/Security.php
 * Rôle    : Gestion de la sécurité, permissions et accès
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/RoleRepository.php';

class Security
{
    private static ?Security $instance = null;
    private Database $db;
    private RoleRepository $roleRepository;
    private ?array $userPermissions = null;

    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->roleRepository = new RoleRepository($this->db);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ============================================================
    // VÉRIFICATION DES PERMISSIONS
    // ============================================================

    public function can(string $permissionCode): bool
    {
        if (isAdmin()) return true;

        if ($this->userPermissions === null) {
            $this->loadUserPermissions();
        }

        return in_array($permissionCode, $this->userPermissions ?? []);
    }

    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) return false;
        }
        return true;
    }

    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) return true;
        }
        return false;
    }

    public function requirePermission(string $permissionCode): void
    {
        if (!$this->can($permissionCode)) {
            $this->denyAccess('Vous n\'avez pas la permission nécessaire : ' . $permissionCode);
        }
    }

    public function requireAllPermissions(array $permissions): void
    {
        if (!$this->canAll($permissions)) {
            $this->denyAccess('Permissions insuffisantes.');
        }
    }

    public function requireAnyPermission(array $permissions): void
    {
        if (!$this->canAny($permissions)) {
            $this->denyAccess('Permissions insuffisantes.');
        }
    }

    public function canAccessModule(string $module): bool
    {
        return $this->can($module . '.consulter');
    }

    public function canCreate(string $module): bool
    {
        return $this->can($module . '.creer');
    }

    public function canEdit(string $module): bool
    {
        return $this->can($module . '.modifier');
    }

    public function canDelete(string $module): bool
    {
        return $this->can($module . '.supprimer');
    }

    // ============================================================
    // GESTION DES RÔLES
    // ============================================================

    public function getRolePermissions(int $roleId): array
    {
        return $this->roleRepository->getRolePermissions($roleId);
    }

    public function getAllPermissionsGrouped(): array
    {
        $permissions = $this->roleRepository->getAllPermissions();
        $grouped = [];
        foreach ($permissions as $permission) {
            $module = $permission['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = ['module' => $module, 'permissions' => []];
            }
            $grouped[$module]['permissions'][] = $permission;
        }
        return array_values($grouped);
    }

    public function getAllRoles(): array
    {
        return $this->roleRepository->getAllRolesWithPermissionCounts();
    }

    public function getRoleById(int $roleId): ?array
    {
        return $this->roleRepository->findById($roleId);
    }

    public function canAssignRole(int $roleId, ?int $targetUserId = null): bool
    {
        $actorId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        if ($actorId <= 0 || $roleId <= 0 || ($targetUserId !== null && $targetUserId === $actorId)) {
            return false;
        }

        $actor = $this->roleRepository->findActorRoleLevel($actorId);
        $targetRole = $this->getRoleById($roleId);
        if (!$actor || !$targetRole || !(int) $targetRole['est_actif']) {
            return false;
        }

        if ((int) $actor['role_id'] === 1) {
            return true;
        }

        return (int) $targetRole['id'] !== 1
            && (int) $targetRole['niveau_acces'] < (int) $actor['niveau_acces'];
    }

    public function getAssignableRoles(?int $targetUserId = null): array
    {
        return array_values(array_filter(
            $this->roleRepository->getActiveRoles(),
            fn(array $role): bool => $this->canAssignRole((int) $role['id'], $targetUserId)
        ));
    }

    public function assignPermission(int $roleId, int $permissionId): bool
    {
        $success = $this->roleRepository->assignPermission($roleId, $permissionId);
        if ($success && (int) ($_SESSION['utilisateur_role_id'] ?? 0) === $roleId) {
            $this->refreshPermissions();
        }
        return $success;
    }

    public function revokePermission(int $roleId, int $permissionId): bool
    {
        $success = $this->roleRepository->revokePermission($roleId, $permissionId);
        if ($success && (int) ($_SESSION['utilisateur_role_id'] ?? 0) === $roleId) {
            $this->refreshPermissions();
        }
        return $success;
    }

    public function updateRolePermissions(int $roleId, array $permissionIds): bool
    {
        $success = $this->roleRepository->replacePermissions($roleId, $permissionIds);
        if ($success && (int) ($_SESSION['utilisateur_role_id'] ?? 0) === $roleId) {
            $this->refreshPermissions();
        }
        return $success;
    }

    public function createRole(string $nom, string $description, int $niveauAcces = 1): ?int
    {
        return $this->roleRepository->createRole($nom, $description, $niveauAcces);
    }

    public function deleteRole(int $roleId): bool
    {
        if ($roleId <= 6) {
            return false;
        }
        return $this->roleRepository->deleteRole($roleId);
    }

    private function loadUserPermissions(): void
    {
        $roleId = (int) ($_SESSION['utilisateur_role_id'] ?? 0);

        if ($roleId === 0) {
            $this->userPermissions = [];
            return;
        }

        if ($roleId === 1) {
            $this->userPermissions = ['*'];
            return;
        }

        $permissions = $this->roleRepository->getRolePermissions($roleId);
        $this->userPermissions = array_column($permissions, 'code');
    }

    public function refreshPermissions(): void
    {
        $this->userPermissions = null;
        $this->loadUserPermissions();
    }

    private function denyAccess(string $message = 'Accès refusé.'): void
    {
        if (isAjax()) {
            jsonResponse(false, $message, [], 403);
        }

        logActivity('Accès refusé', null, null, $message);

        setFlash('error', $message);
        redirect(BASE_URL . 'views/dashboard/');
    }

    // ============================================================
    // SÉCURITÉ GÉNÉRALE
    // ============================================================

    public static function cleanXSS(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Vérifie le token CSRF — version tolérante pour mobile
     */
    public function validateCsrf(): void
    {
        $token = csrfTokenFromRequest();

        if (!verifyCsrfToken($token)) {
            // Régénérer un nouveau token pour la prochaine requête
            generateCsrfToken();

            if (isAjax()) {
                jsonResponse(false, 'Session expirée. Veuillez rafraîchir la page.', [], 419);
            }

            setFlash('error', 'Session expirée. Veuillez vous reconnecter.');
            redirect(BASE_URL . 'views/auth/login.php?session_expired=1');
        }
    }

    /**
     * Vérifie le timeout de session
     */
    public function checkSessionTimeout(): void
    {
        if (!isLoggedIn()) return;

        $sessionTime = (int) ($_SESSION['session_time'] ?? 0);
        if ($sessionTime <= 0 || time() - $sessionTime > SESSION_TIMEOUT) {
            $this->terminateSession('Session expirée.');
        }

        $user = $this->db->fetchOne(
            "SELECT u.role_id, r.nom AS role_nom
             FROM utilisateurs u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.est_actif = 1 AND r.est_actif = 1",
            ['id' => (int) $_SESSION['utilisateur_id']]
        );
        if (!$user) {
            $this->terminateSession('Votre compte n’est plus actif. Veuillez vous reconnecter.');
        }

        if (
            (int) ($_SESSION['utilisateur_role_id'] ?? 0) !== (int) $user['role_id']
            || (string) ($_SESSION['utilisateur_role'] ?? '') !== (string) $user['role_nom']
        ) {
            $_SESSION['utilisateur_role_id'] = (int) $user['role_id'];
            $_SESSION['utilisateur_role'] = (string) $user['role_nom'];
            $this->refreshPermissions();
        }

        $_SESSION['session_time'] = time();
    }

    private function terminateSession(string $message): void
    {
        $_SESSION = [];
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
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (isAjax()) {
            jsonResponse(false, $message, [], 401);
        }

        setFlash('warning', $message);
        redirect(BASE_URL . 'login?session_expired=1');
    }

    /**
     * Vérifie l'intégrité de la session — DÉSACTIVÉ pour mobiles
     */
    public function validateSessionIntegrity(): void
    {
        if (!isLoggedIn()) return;

        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionIp = $_SESSION['session_ip'] ?? '';
        $sessionAgent = $_SESSION['session_agent'] ?? '';

        // Stocker au premier chargement
        if (empty($sessionIp)) {
            $_SESSION['session_ip'] = $currentIp;
            $_SESSION['session_agent'] = $currentAgent;
            return;
        }

        // Vérification IP désactivée (problèmes mobile 4G/WiFi)
        // Vérification User-Agent désactivée (problèmes navigateurs mobiles)
        // On garde juste le stockage sans validation stricte
    }

    /**
     * Détruit la session pour raison de sécurité
     */
    private function destroySession(string $reason): void
    {
        logActivity('Session détruite', 'utilisateurs', $_SESSION['utilisateur_id'] ?? null, $reason);

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        redirect(BASE_URL . 'views/auth/login.php?session_expired=1');
    }
}