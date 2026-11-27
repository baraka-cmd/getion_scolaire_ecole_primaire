<?php
/**
 * Fichier : src/controllers/EnseignantController.php
 * Rôle    : Gestion complète des enseignants (CRUD + affectations)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/EnseignantService.php';

class EnseignantController
{
    private Database $db;
    private EnseignantService $enseignantService;

    public function __construct(?EnseignantService $enseignantService = null)
    {
        $this->db = Database::getInstance();
        $this->enseignantService = $enseignantService ?? new EnseignantService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('enseignants.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            case 'liste':
                $this->getListe();
                break;
            case 'getEnseignant':
                $this->getEnseignant();
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
            case 'changerClasse':
                $this->changerClasse();
                break;
            case 'getClassesDisponibles':
                $this->getClassesDisponibles();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Liste paginée des enseignants
     */
    private function getListe(): void
    {
        $page = max(1, (int) post('page', 1));
        $parPage = ITEMS_PER_PAGE;
        $result = $this->enseignantService->paginate([
            'recherche' => post('recherche', ''),
            'specialite' => post('specialite', ''),
            'statut' => post('statut', ''),
            'ordre' => post('ordre', 'nom'),
            'direction' => post('direction', 'ASC'),
        ], $page, $parPage);

        jsonResponse(true, 'Liste récupérée.', [
            'enseignants' => $result['enseignants'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => ceil($result['total'] / $parPage)
        ]);
    }

    /**
     * Récupère un enseignant par ID avec détails
     */
    private function getEnseignant(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $details = $this->enseignantService->getForAjax($id);
        if ($details === null) jsonResponse(false, 'Enseignant introuvable.', [], 404);

        jsonResponse(true, 'Enseignant récupéré.', [
            'enseignant'     => $details['enseignant'],
            'eleves'         => $details['eleves'],
            'emploiDuTemps'  => $details['emploiDuTemps']
        ]);
    }

    /**
     * Crée un nouvel enseignant
     */
    private function creer(): void
    {
        requirePermission('enseignants.creer');

        $nom              = post('nom', '');
        $prenom           = post('prenom', '');
        $dateNaissance    = post('date_naissance', '');
        $sexe             = post('sexe', '');
        $adresse          = post('adresse', '');
        $telephone        = post('telephone', '');
        $email            = post('email', '');
        $diplome          = post('diplome', '');
        $specialite       = post('specialite', '');
        $dateEmbauche     = post('date_embauche', '');
        $classeId         = (int) post('classe_id', 0);
        $creerCompte      = (int) post('creer_compte', 0);

        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        elseif (mb_strlen($nom) < 2) $errors['nom'] = 'Minimum 2 caractères.';

        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        elseif (mb_strlen($prenom) < 2) $errors['prenom'] = 'Minimum 2 caractères.';

        if (isBlank($dateNaissance)) $errors['date_naissance'] = 'La date de naissance est obligatoire.';
        elseif (!isValidDate($dateNaissance)) $errors['date_naissance'] = 'Date invalide.';

        if (!in_array($sexe, ['M', 'F'])) $errors['sexe'] = 'Sexe invalide.';

        if (!isBlank($telephone) && !isValidPhone($telephone)) $errors['telephone'] = 'Format invalide.';
        else {
            $telExiste = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE telephone = :tel",
                ['tel' => $telephone]
            );
            if ($telExiste > 0) $errors['telephone'] = 'Ce numéro est déjà utilisé.';
        }

        if (!isBlank($email)) {
            if (!isValidEmail($email)) $errors['email'] = 'Email invalide.';
            else {
                $emailExiste = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM enseignants WHERE email = :email",
                    ['email' => $email]
                );
                if ($emailExiste > 0) $errors['email'] = 'Cet email est déjà utilisé.';
            }
        }

        // Vérifier la classe (une seule classe par enseignant)
        if ($classeId > 0) {
            $classeDejaPrise = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE classe_id = :cid AND est_actif = 1",
                ['cid' => $classeId]
            );
            if ($classeDejaPrise > 0) $errors['classe_id'] = 'Cette classe a déjà un titulaire.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->beginTransaction();

            // Générer le matricule
            $matricule = 'ENS-' . date('y') . '-' . str_pad(((int) $this->db->fetchColumn("SELECT MAX(id) FROM enseignants") + 1), 3, '0', STR_PAD_LEFT);

            // Insérer l'enseignant
            $enseignantId = $this->db->insert(
                "INSERT INTO enseignants (matricule, nom, prenom, date_naissance, sexe, adresse, telephone, 
                     email, diplome, specialite, date_embauche, classe_id, est_actif)
                 VALUES (:mat, :nom, :prenom, :dn, :sexe, :adr, :tel, :email, :diplome, :spe, :de, :cid, 1)",
                [
                    'mat'     => $matricule,
                    'nom'     => formatNom($nom),
                    'prenom'  => formatNom($prenom),
                    'dn'      => $dateNaissance,
                    'sexe'    => $sexe,
                    'adr'     => $adresse ?: null,
                    'tel'     => $telephone ?: null,
                    'email'   => $email ?: null,
                    'diplome' => $diplome ?: null,
                    'spe'     => $specialite ?: null,
                    'de'      => $dateEmbauche ?: date('Y-m-d'),
                    'cid'     => $classeId > 0 ? $classeId : null
                ]
            );

            // Créer un compte utilisateur si demandé
            if ($creerCompte && !empty($email)) {
                $login = 'ens.' . slugify($prenom) . '.' . slugify($nom);
                // Vérifier unicité login
                $i = 1;
                $baseLogin = $login;
                while ($this->db->fetchColumn("SELECT COUNT(*) FROM utilisateurs WHERE login = :login", ['login' => $login]) > 0) {
                    $login = $baseLogin . $i;
                    $i++;
                }

                $hashedPassword = generateRandomPasswordHash();

                $userId = $this->db->insert(
                    "INSERT INTO utilisateurs (nom, prenom, email, login, mot_de_passe, role_id, est_actif)
                     VALUES (:nom, :prenom, :email, :login, :pass, 4, 1)",
                    [
                        'nom'    => formatNom($nom),
                        'prenom' => formatNom($prenom),
                        'email'  => $email,
                        'login'  => $login,
                        'pass'   => $hashedPassword
                    ]
                );

                $this->db->update(
                    "UPDATE enseignants SET utilisateur_id = :uid WHERE id = :eid",
                    ['uid' => $userId, 'eid' => $enseignantId]
                );
            }

            $this->db->commit();

            logActivity('Création', 'enseignants', $enseignantId, "Enseignant créé : {$nom} {$prenom}");

            $msg = 'Enseignant créé avec succès.';
            if ($creerCompte && !empty($email)) {
                $msg .= " Compte créé (login : {$login}). Le titulaire doit utiliser « Mot de passe oublié » pour définir son accès.";
            }

            jsonResponse(true, $msg, ['id' => $enseignantId, 'matricule' => $matricule]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur création enseignant: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création de l\'enseignant.');
        }
    }

    /**
     * Modifie un enseignant existant
     */
    private function modifier(): void
    {
        requirePermission('enseignants.modifier');

        $id               = (int) post('id', 0);
        $nom              = post('nom', '');
        $prenom           = post('prenom', '');
        $dateNaissance    = post('date_naissance', '');
        $sexe             = post('sexe', '');
        $adresse          = post('adresse', '');
        $telephone        = post('telephone', '');
        $email            = post('email', '');
        $diplome          = post('diplome', '');
        $specialite       = post('specialite', '');
        $dateEmbauche     = post('date_embauche', '');
        $classeId         = (int) post('classe_id', 0);

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $existant = $this->db->fetchOne("SELECT id FROM enseignants WHERE id = :id", ['id' => $id]);
        if (!$existant) jsonResponse(false, 'Enseignant introuvable.', [], 404);

        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        if (isBlank($dateNaissance)) $errors['date_naissance'] = 'Date obligatoire.';
        if (!in_array($sexe, ['M', 'F'])) $errors['sexe'] = 'Sexe invalide.';

        if (!isBlank($telephone) && !isValidPhone($telephone)) $errors['telephone'] = 'Format invalide.';
        else {
            $telExiste = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE telephone = :tel AND id != :id",
                ['tel' => $telephone, 'id' => $id]
            );
            if ($telExiste > 0) $errors['telephone'] = 'Ce numéro est déjà utilisé.';
        }

        if (!isBlank($email) && !isValidEmail($email)) $errors['email'] = 'Email invalide.';
        else {
            $emailExiste = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE email = :email AND id != :id",
                ['email' => $email, 'id' => $id]
            );
            if ($emailExiste > 0) $errors['email'] = 'Cet email est déjà utilisé.';
        }

        // Vérifier la classe
        if ($classeId > 0) {
            $classeDejaPrise = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE classe_id = :cid AND id != :eid AND est_actif = 1",
                ['cid' => $classeId, 'eid' => $id]
            );
            if ($classeDejaPrise > 0) $errors['classe_id'] = 'Cette classe a déjà un autre titulaire.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->update(
                "UPDATE enseignants 
                 SET nom = :nom, prenom = :prenom, date_naissance = :dn, sexe = :sexe,
                     adresse = :adr, telephone = :tel, email = :email,
                     diplome = :diplome, specialite = :spe, date_embauche = :de, classe_id = :cid
                 WHERE id = :id",
                [
                    'nom'     => formatNom($nom),
                    'prenom'  => formatNom($prenom),
                    'dn'      => $dateNaissance,
                    'sexe'    => $sexe,
                    'adr'     => $adresse ?: null,
                    'tel'     => $telephone ?: null,
                    'email'   => $email ?: null,
                    'diplome' => $diplome ?: null,
                    'spe'     => $specialite ?: null,
                    'de'      => $dateEmbauche ?: null,
                    'cid'     => $classeId > 0 ? $classeId : null,
                    'id'      => $id
                ]
            );

            logActivity('Modification', 'enseignants', $id, "Enseignant modifié : {$nom} {$prenom}");

            jsonResponse(true, 'Enseignant modifié avec succès.');

        } catch (Exception $e) {
            error_log('Erreur modification enseignant: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    /**
     * Désactive un enseignant
     */
    private function supprimer(): void
    {
        requirePermission('enseignants.supprimer');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $enseignant = $this->db->fetchOne("SELECT id, nom, prenom, classe_id FROM enseignants WHERE id = :id", ['id' => $id]);
        if (!$enseignant) jsonResponse(false, 'Enseignant introuvable.', [], 404);

        // Vérifier si sa classe a des élèves
        if ($enseignant['classe_id']) {
            $nbEleves = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
                ['cid' => $enseignant['classe_id']]
            );
            if ($nbEleves > 0) {
                jsonResponse(false, "Impossible de désactiver : sa classe a {$nbEleves} élève(s). Réassignez la classe d'abord.", [], 409);
            }
        }

        try {
            // Libérer la classe
            $this->db->update("UPDATE enseignants SET classe_id = NULL WHERE id = :id", ['id' => $id]);
            // Désactiver
            $this->db->update("UPDATE enseignants SET est_actif = 0 WHERE id = :id", ['id' => $id]);
            // Désactiver le compte utilisateur
            $this->db->update("UPDATE utilisateurs SET est_actif = 0 WHERE id = (SELECT utilisateur_id FROM enseignants WHERE id = :id)", ['id' => $id]);

            logActivity('Suppression', 'enseignants', $id, "Enseignant désactivé : {$enseignant['nom']} {$enseignant['prenom']}");

            jsonResponse(true, 'Enseignant désactivé avec succès.');

        } catch (Exception $e) {
            error_log('Erreur suppression enseignant: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la désactivation.');
        }
    }

    /**
     * Change rapidement la classe d'un enseignant
     */
    private function changerClasse(): void
    {
        requirePermission('enseignants.modifier');

        $enseignantId = (int) post('enseignant_id', 0);
        $classeId     = (int) post('classe_id', 0);

        if ($enseignantId <= 0) jsonResponse(false, 'ID enseignant invalide.', [], 400);

        try {
            if (!$this->enseignantService->changeClass($enseignantId, $classeId)) {
                jsonResponse(false, 'Cette classe a déjà un titulaire.', [], 409);
            }

            logActivity('Modification', 'enseignants', $enseignantId, 'Changement de classe');
            jsonResponse(true, 'Classe modifiée avec succès.');

        } catch (Exception $e) {
            error_log('Erreur changement classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du changement de classe.');
        }
    }

    /**
     * Récupère les classes disponibles (sans titulaire)
     */
    private function getClassesDisponibles(): void
    {
        $excludeEnseignantId = (int) post('exclude_enseignant_id', 0);

        $classes = $this->enseignantService->getAvailableClasses($excludeEnseignantId);

        jsonResponse(true, 'Classes disponibles.', ['classes' => $classes]);
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère un enseignant complet par ID
     */
    public function getEnseignantDetail(int $id): ?array
    {
        return $this->enseignantService->getDetail($id);
    }

    /**
     * Récupère les élèves de la classe d'un enseignant
     */
    public function getElevesClasse(int $enseignantId): array
    {
        return $this->enseignantService->getStudents($enseignantId);
    }

    /**
     * Récupère toutes les classes (pour formulaires)
     */
    public function getClassesActives(): array
    {
        return $this->enseignantService->getActiveClasses();
    }

    /**
     * Récupère les spécialités distinctes (pour filtres)
     */
    public function getSpecialites(): array
    {
        return $this->enseignantService->getSpecialties();
    }
}