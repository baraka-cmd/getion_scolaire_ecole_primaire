<?php
/**
 * Fichier : src/controllers/UtilisateurController.php
 * Rôle    : Gestion complète des utilisateurs (CRUD)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/UserAccountService.php';

class UtilisateurController
{
    private Database $db;
    private Security $security;
    private UserAccountService $accountService;

    public function __construct(?UserAccountService $accountService = null)
    {
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
        $this->accountService = $accountService ?? new UserAccountService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('utilisateurs.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');
        if (
            in_array($action, ['creer', 'modifier', 'supprimer', 'toggleStatut', 'changerMotDePasse'], true)
            && !verifyCsrfToken(csrfTokenFromRequest())
        ) {
            jsonResponse(false, 'Session expirée. Veuillez actualiser la page.', [], 419);
        }

        switch ($action) {
            case 'liste':
                $this->getListe();
                break;
            case 'getUtilisateur':
                $this->getUtilisateur();
                break;
            case 'creer':
                $this->creer();
                break;
            case 'modifier':
                $this->modifier();
                break;
            case 'supprimer':
                $this->supprimer();
                break;
            case 'toggleStatut':
                $this->toggleStatut();
                break;
            case 'changerMotDePasse':
                $this->changerMotDePasse();
                break;
            case 'verifierEmail':
                $this->verifierEmail();
                break;
            case 'verifierLogin':
                $this->verifierLogin();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Liste paginée avec recherche et filtres
     */
    private function getListe(): void
    {
        $page = max(1, (int) post('page', 1));
        $recherche = post('recherche', '');
        $role = post('role', '');
        $statut = post('statut', '');
        $ordre = post('ordre', 'nom');
        $direction = post('direction', 'ASC');
        $parPage = ITEMS_PER_PAGE;

        $result = $this->accountService->getAdminList(
            $page,
            $parPage,
            $recherche,
            $role,
            $statut,
            $ordre,
            $direction
        );

        jsonResponse(true, 'Liste récupérée.', [
            'utilisateurs' => $result['utilisateurs'],
            'total'        => $result['total'],
            'page'         => $page,
            'par_page'     => $parPage,
            'pages'        => ceil($result['total'] / $parPage)
        ]);
    }

    /**
     * Récupère un utilisateur par son ID
     */
    private function getUtilisateur(): void
    {
        $id = (int) post('id', 0);

        if ($id <= 0) {
            jsonResponse(false, 'ID invalide.', [], 400);
        }

        $utilisateur = $this->accountService->getAdminDetails($id);

        if (!$utilisateur) {
            jsonResponse(false, 'Utilisateur introuvable.', [], 404);
        }

        $historique = $this->accountService->getAuthenticationHistory($id);

        jsonResponse(true, 'Utilisateur récupéré.', [
            'utilisateur' => $utilisateur,
            'historique'  => $historique
        ]);
    }

    /**
     * Crée un nouvel utilisateur
     */
    private function creer(): void
    {
        requirePermission('utilisateurs.creer');

        $nom       = post('nom', '');
        $prenom    = post('prenom', '');
        $email     = post('email', '');
        $telephone = post('telephone', '');
        $login     = post('login', '');
        $password  = $_POST['password'] ?? '';
        $roleId    = (int) post('role_id', 0);

        // Validation
        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        elseif (mb_strlen($nom) < 2) $errors['nom'] = 'Minimum 2 caractères.';
        elseif (mb_strlen($nom) > 100) $errors['nom'] = 'Maximum 100 caractères.';

        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        elseif (mb_strlen($prenom) < 2) $errors['prenom'] = 'Minimum 2 caractères.';
        elseif (mb_strlen($prenom) > 100) $errors['prenom'] = 'Maximum 100 caractères.';

        if (isBlank($email)) $errors['email'] = 'L\'email est obligatoire.';
        elseif (!isValidEmail($email)) $errors['email'] = 'Email invalide.';
        elseif ($this->emailExiste($email)) $errors['email'] = 'Cet email est déjà utilisé.';

        if (!isBlank($telephone) && !isValidPhone($telephone)) {
            $errors['telephone'] = 'Numéro invalide.';
        }

        if (isBlank($login)) $errors['login'] = 'Le login est obligatoire.';
        elseif (mb_strlen($login) < 3) $errors['login'] = 'Minimum 3 caractères.';
        elseif (mb_strlen($login) > 50) $errors['login'] = 'Maximum 50 caractères.';
        elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) $errors['login'] = 'Caractères non autorisés.';
        elseif ($this->loginExiste($login)) $errors['login'] = 'Ce login est déjà utilisé.';

        if (isBlank($password)) $errors['password'] = 'Le mot de passe est obligatoire.';
        elseif (mb_strlen($password) < 8) $errors['password'] = 'Minimum 8 caractères.';
        elseif (!preg_match('/[A-Z]/', $password)) $errors['password'] = 'Au moins une majuscule.';
        elseif (!preg_match('/[a-z]/', $password)) $errors['password'] = 'Au moins une minuscule.';
        elseif (!preg_match('/[0-9]/', $password)) $errors['password'] = 'Au moins un chiffre.';
        elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'\"\\|,.<>\/?~`]/', $password)) $errors['password'] = 'Au moins un caractère spécial.';

        if ($roleId <= 0 || !$this->security->canAssignRole($roleId)) {
            $errors['role_id'] = 'Vous ne pouvez pas attribuer ce rôle.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->beginTransaction();

            $hashedPassword = password_hash($password, PASSWORD_ALGO, ['cost' => PASSWORD_COST]);

            $userId = $this->accountService->create([
                'nom'       => formatNom($nom),
                'prenom'    => formatNom($prenom),
                'email'     => mb_strtolower(trim($email)),
                'telephone' => $telephone ?: null,
                'login'     => mb_strtolower(trim($login)),
                'password'  => $hashedPassword,
                'role_id'   => $roleId,
            ]);

            $this->db->commit();

            logActivity('Création', 'utilisateurs', $userId, "Création de l'utilisateur {$nom} {$prenom}");

            jsonResponse(true, 'Utilisateur créé avec succès.', ['id' => $userId]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur création utilisateur: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création de l\'utilisateur.');
        }
    }

    /**
     * Modifie un utilisateur existant
     */
    private function modifier(): void
    {
        requirePermission('utilisateurs.modifier');

        $id        = (int) post('id', 0);
        $nom       = post('nom', '');
        $prenom    = post('prenom', '');
        $email     = post('email', '');
        $telephone = post('telephone', '');
        $login     = post('login', '');
        $roleId    = (int) post('role_id', 0);

        if ($id <= 0) {
            jsonResponse(false, 'ID invalide.', [], 400);
        }

        // Vérifier que l'utilisateur existe
        $existant = $this->accountService->findIdentity($id);
        if (!$existant) {
            jsonResponse(false, 'Utilisateur introuvable.', [], 404);
        }
        if (
            !$this->security->canAssignRole((int) $existant['role_id'], $id)
            || !$this->security->canAssignRole($roleId, $id)
        ) {
            jsonResponse(false, 'Vous ne pouvez pas modifier ce compte ou lui attribuer ce rôle.', [], 403);
        }

        // Validation
        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        elseif (mb_strlen($nom) < 2) $errors['nom'] = 'Minimum 2 caractères.';

        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        elseif (mb_strlen($prenom) < 2) $errors['prenom'] = 'Minimum 2 caractères.';

        if (isBlank($email)) $errors['email'] = 'L\'email est obligatoire.';
        elseif (!isValidEmail($email)) $errors['email'] = 'Email invalide.';
        elseif ($this->emailExiste($email, $id)) $errors['email'] = 'Cet email est déjà utilisé.';

        if (!isBlank($telephone) && !isValidPhone($telephone)) {
            $errors['telephone'] = 'Numéro invalide.';
        }

        if (isBlank($login)) $errors['login'] = 'Le login est obligatoire.';
        elseif ($this->loginExiste($login, $id)) $errors['login'] = 'Ce login est déjà utilisé.';

        if ($roleId <= 0) $errors['role_id'] = 'Le rôle est obligatoire.';

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->accountService->update($id, [
                'nom'       => formatNom($nom),
                'prenom'    => formatNom($prenom),
                'email'     => mb_strtolower(trim($email)),
                'telephone' => $telephone ?: null,
                'login'     => mb_strtolower(trim($login)),
                'role_id'   => $roleId,
            ]);

            logActivity('Modification', 'utilisateurs', $id, "Modification de l'utilisateur {$nom} {$prenom}");

            jsonResponse(true, 'Utilisateur modifié avec succès.');

        } catch (Exception $e) {
            error_log('Erreur modification utilisateur: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    /**
     * Supprime un utilisateur
     */
    private function supprimer(): void
    {
        requirePermission('utilisateurs.supprimer');

        $id = (int) post('id', 0);

        if ($id <= 0) {
            jsonResponse(false, 'ID invalide.', [], 400);
        }

        // Empêcher la suppression de son propre compte
        if ($id === (int) ($_SESSION['utilisateur_id'] ?? 0)) {
            jsonResponse(false, 'Vous ne pouvez pas supprimer votre propre compte.', [], 403);
        }

        // Vérifier que l'utilisateur existe
        $utilisateur = $this->accountService->findIdentity($id);

        if (!$utilisateur) {
            jsonResponse(false, 'Utilisateur introuvable.', [], 404);
        }

        try {
            $this->accountService->delete($id);

            logActivity('Suppression', 'utilisateurs', $id, 
                "Suppression de l'utilisateur {$utilisateur['nom']} {$utilisateur['prenom']}");

            jsonResponse(true, 'Utilisateur supprimé avec succès.');

        } catch (Exception $e) {
            error_log('Erreur suppression utilisateur: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la suppression. Vérifiez les dépendances.');
        }
    }

    /**
     * Active/Désactive un utilisateur
     */
    private function toggleStatut(): void
    {
        requirePermission('utilisateurs.activer');

        $id = (int) post('id', 0);

        if ($id <= 0) {
            jsonResponse(false, 'ID invalide.', [], 400);
        }

        if ($id === (int) ($_SESSION['utilisateur_id'] ?? 0)) {
            jsonResponse(false, 'Vous ne pouvez pas désactiver votre propre compte.', [], 403);
        }

        $utilisateur = $this->accountService->findIdentity($id);

        if (!$utilisateur) {
            jsonResponse(false, 'Utilisateur introuvable.', [], 404);
        }

        $toggle = $this->accountService->toggleActive($id);
        $nouveauStatut = $toggle['active'];

        $action = $nouveauStatut ? 'Activé' : 'Désactivé';
        logActivity($action, 'utilisateurs', $id, 
            "{$action} : {$utilisateur['nom']} {$utilisateur['prenom']}");

        jsonResponse(true, "Utilisateur {$action} avec succès.", ['est_actif' => $nouveauStatut]);
    }

    /**
     * Change le mot de passe (par un admin)
     */
    private function changerMotDePasse(): void
    {
        $id = (int) post('id', 0);
        $password = $_POST['password'] ?? '';

        if ($id <= 0) {
            jsonResponse(false, 'ID invalide.', [], 400);
        }

        // Seul l'utilisateur lui-même ou un admin peut changer le mot de passe
        if ($id !== (int) ($_SESSION['utilisateur_id'] ?? 0) && !isAdmin()) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (isBlank($password)) {
            jsonResponse(false, 'Le mot de passe est obligatoire.', [], 422);
        }
        if (mb_strlen($password) < 8) {
            jsonResponse(false, 'Minimum 8 caractères.', [], 422);
        }

        $this->accountService->setPassword($id, $password);

        logActivity('Réinitialisation mot de passe', 'utilisateurs', $id);

        jsonResponse(true, 'Mot de passe modifié avec succès.');
    }

    /**
     * Vérifie si un email existe déjà
     */
    private function verifierEmail(): void
    {
        $email = post('email', '');
        $excludeId = (int) post('exclude_id', 0);

        if (empty($email)) {
            jsonResponse(false, 'Email requis.', [], 400);
        }

        $existe = $this->emailExiste($email, $excludeId > 0 ? $excludeId : null);

        jsonResponse(true, 'Vérification terminée.', ['existe' => $existe]);
    }

    /**
     * Vérifie si un login existe déjà
     */
    private function verifierLogin(): void
    {
        $login = post('login', '');
        $excludeId = (int) post('exclude_id', 0);

        if (empty($login)) {
            jsonResponse(false, 'Login requis.', [], 400);
        }

        $existe = $this->loginExiste($login, $excludeId > 0 ? $excludeId : null);

        jsonResponse(true, 'Vérification terminée.', ['existe' => $existe]);
    }

    /**
     * Récupère tous les rôles (pour les formulaires)
     */
    public function getRoles(): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom, description FROM roles WHERE est_actif = 1 ORDER BY niveau_acces DESC"
        );
    }

    public function getAssignableRoles(?int $targetUserId = null): array
    {
        return $this->security->getAssignableRoles($targetUserId);
    }

    /**
     * Récupère les logs d'un utilisateur
     */
    public function getLogs(int $userId, int $limit = 50): array
    {
        return $this->accountService->getLogs($userId, $limit);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    private function emailExiste(string $email, ?int $excludeId = null): bool
    {
        return $this->accountService->emailExists($email, $excludeId);
    }

    private function loginExiste(string $login, ?int $excludeId = null): bool
    {
        return $this->accountService->loginExists($login, $excludeId);
    }
}
