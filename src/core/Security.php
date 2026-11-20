<?php
/**
 * Fichier : src/core/Security.php
 * Rôle    : Gestion de la sécurité, permissions et accès
 */

defined('ACCESS') or die('Accès interdit');

class Security
{
    private static ?Security $instance = null;
    private Database $db;
    private ?array $userPermissions = null;

    private function __construct()
    {
        $this->db = Database::getInstance();
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
        return $this->db->fetchAll(
            "SELECT p.id, p.code, p.libelle, p.module, p.action, p.description
             FROM permissions p
             INNER JOIN role_permission rp ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
             ORDER BY p.module, p.action",
            ['role_id' => $roleId]
        );
    }

    public function getAllPermissionsGrouped(): array
    {
        $permissions = $this->db->fetchAll(
            "SELECT id, code, libelle, module, action, description
             FROM permissions
             ORDER BY module, action"
        );

        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = ['module' => $module, 'permissions' => []];
            }
            $grouped[$module]['permissions'][] = $perm;
        }

        return array_values($grouped);
    }

    public function getAllRoles(): array
    {
        return $this->db->fetchAll(
            "SELECT r.id, r.nom, r.description, r.niveau_acces, r.est_actif,
                    COUNT(rp.id) as nb_permissions
             FROM roles r
             LEFT JOIN role_permission rp ON r.id = rp.role_id
             GROUP BY r.id, r.nom, r.description, r.niveau_acces, r.est_actif
             ORDER BY r.niveau_acces DESC"
        );
    }

    public function getRoleById(int $roleId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, nom, description, niveau_acces, est_actif FROM roles WHERE id = :id",
            ['id' => $roleId]
        );
    }

    public function canAssignRole(int $roleId, ?int $targetUserId = null): bool
    {
        $actorId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        if ($actorId <= 0 || $roleId <= 0 || ($targetUserId !== null && $targetUserId === $actorId)) {
            return false;
        }

        $actor = $this->db->fetchOne(
            "SELECT u.role_id, r.niveau_acces
             FROM utilisateurs u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.est_actif = 1",
            ['id' => $actorId]
        );
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
        $roles = $this->db->fetchAll(
            "SELECT id, nom, description FROM roles WHERE est_actif = 1 ORDER BY niveau_acces DESC"
        );

        return array_values(array_filter(
            $roles,
            fn(array $role): bool => $this->canAssignRole((int) $role['id'], $targetUserId)
        ));
    }

    public function assignPermission(int $roleId, int $permissionId): bool
    {
        try {
            $this->db->query(
                "INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES (:role_id, :perm_id)",
                ['role_id' => $roleId, 'perm_id' => $permissionId]
            );
            return true;
        } catch (Exception $e) {
            error_log('Erreur assignPermission: ' . $e->getMessage());
            return false;
        }
    }

    public function revokePermission(int $roleId, int $permissionId): bool
    {
        try {
            $this->db->query(
                "DELETE FROM role_permission WHERE role_id = :role_id AND permission_id = :perm_id",
                ['role_id' => $roleId, 'perm_id' => $permissionId]
            );
            return true;
        } catch (Exception $e) {
            error_log('Erreur revokePermission: ' . $e->getMessage());
            return false;
        }
    }

    public function updateRolePermissions(int $roleId, array $permissionIds): bool
    {
        try {
            $this->db->query("DELETE FROM role_permission WHERE role_id = :role_id", ['role_id' => $roleId]);

            if (!empty($permissionIds)) {
                foreach ($permissionIds as $permId) {
                    $this->db->query(
                        "INSERT INTO role_permission (role_id, permission_id) VALUES (:role_id, :perm_id)",
                        ['role_id' => $roleId, 'perm_id' => $permId]
                    );
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('Erreur updateRolePermissions: ' . $e->getMessage());
            return false;
        }
    }

    public function createRole(string $nom, string $description, int $niveauAcces = 1): ?int
    {
        try {
            return $this->db->insert(
                "INSERT INTO roles (nom, description, niveau_acces, est_actif) VALUES (:nom, :desc, :niveau, 1)",
                ['nom' => $nom, 'desc' => $description, 'niveau' => $niveauAcces]
            );
        } catch (Exception $e) {
            error_log('Erreur createRole: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteRole(int $roleId): bool
    {
        if ($roleId <= 6) return false;

        try {
            $this->db->query("DELETE FROM roles WHERE id = :id", ['id' => $roleId]);
            return true;
        } catch (Exception $e) {
            error_log('Erreur deleteRole: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    private function loadUserPermissions(): void
    {
        $roleId = $_SESSION['utilisateur_role_id'] ?? 0;

        if ($roleId === 0) {
            $this->userPermissions = [];
            return;
        }

        if ((int) $roleId === 1) {
            $this->userPermissions = ['*'];
            return;
        }

        $this->userPermissions = $this->db->fetchAll(
            "SELECT p.code
             FROM permissions p
             INNER JOIN role_permission rp ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id",
            ['role_id' => $roleId]
        );

        $this->userPermissions = array_column($this->userPermissions, 'code');
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