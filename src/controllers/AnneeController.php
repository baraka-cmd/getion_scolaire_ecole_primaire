<?php
/**
 * Fichier : src/controllers/AnneeController.php
 * Rôle    : Gestion complète des années scolaires
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/AnneeScolaireService.php';

class AnneeController
{
    private Database $db;
    private ?AnneeScolaireService $anneeService = null;

    public function __construct(?AnneeScolaireService $anneeService = null)
    {
        $this->db = Database::getInstance();
        $this->anneeService = $anneeService ?? new AnneeScolaireService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('parametres.consulter')) {
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
            case 'getAnnee':
                $this->getAnnee();
                break;
            case 'creer':
                $this->creer();
                break;
            case 'modifier':
                $this->modifier();
                break;
            case 'activer':
                $this->activer();
                break;
            case 'cloturer':
                $this->cloturer();
                break;
            case 'supprimer':
                $this->supprimer();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Liste de toutes les années scolaires
     */
    private function getListe(): void
    {
        $annees = $this->getAnneeService()->getListForAjax();

        jsonResponse(true, 'Liste récupérée.', ['annees' => $annees]);
    }

    /**
     * Récupère une année par ID
     */
    private function getAnnee(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $details = $this->getAnneeService()->getDetailsForAjax($id);
        if ($details === null) jsonResponse(false, 'Année introuvable.', [], 404);

        jsonResponse(true, 'Année récupérée.', [
            'annee'      => $details['annee'],
            'trimestres' => $details['trimestres']
        ]);
    }

    /**
     * Crée une nouvelle année scolaire avec ses 3 trimestres
     */
    private function creer(): void
    {
        requirePermission('parametres.modifier');

        $libelle    = post('libelle', '');
        $dateDebut  = post('date_debut', '');
        $dateFin    = post('date_fin', '');
        $creerTrimestres = (int) post('creer_trimestres', 1);
        $trimestresInput = is_array($_POST['trimestres'] ?? null) ? $_POST['trimestres'] : [];

        $errors = [];

        if (isBlank($libelle)) {
            $errors['libelle'] = 'Le libellé est obligatoire.';
        } elseif (!preg_match('/^\d{4}-\d{4}$/', $libelle)) {
            $errors['libelle'] = 'Format invalide (ex: 2026-2027).';
        } else {
            $existe = $this->getAnneeService()->countLabel($libelle);
            if ($existe > 0) $errors['libelle'] = 'Cette année scolaire existe déjà.';
        }

        if (isBlank($dateDebut)) {
            $errors['date_debut'] = 'La date de début est obligatoire.';
        } elseif (!isValidDate($dateDebut)) {
            $errors['date_debut'] = 'Date invalide.';
        }

        if (isBlank($dateFin)) {
            $errors['date_fin'] = 'La date de fin est obligatoire.';
        } elseif (!isValidDate($dateFin)) {
            $errors['date_fin'] = 'Date invalide.';
        }

        if (!isBlank($dateDebut) && !isBlank($dateFin) && $dateDebut >= $dateFin) {
            $errors['date_fin'] = 'La date de fin doit être après la date de début.';
        }

        $trimestresValides = [];
        if ($creerTrimestres === 1 && empty($errors)) {
            if ($trimestresInput === []) {
                $trimestresValides = $this->repartirTrimestres($dateDebut, $dateFin);
            } else {
                $validationTrimestres = $this->validerDatesTrimestres($trimestresInput, $dateDebut, $dateFin);
                $errors = array_merge($errors, $validationTrimestres['errors']);
                $trimestresValides = $validationTrimestres['dates'];
            }
        }

        // Vérifier le chevauchement avec d'autres années
        if (!isBlank($dateDebut) && !isBlank($dateFin) && empty($errors)) {
            $chevauche = $this->getAnneeService()->countOverlappingDates($dateDebut, $dateFin);
            if ($chevauche > 0) {
                $errors['date_debut'] = 'Cette période chevauche une année existante.';
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->beginTransaction();

            // Créer l'année (désactivée par défaut)
            $anneeId = $this->anneeService->createYear([
                'libelle' => $libelle, 'debut' => $dateDebut, 'fin' => $dateFin
            ]);

            // Créer les 3 trimestres automatiquement
            if ($creerTrimestres) {
                $this->enregistrerTrimestres($anneeId, $trimestresValides);
            }

            $this->db->commit();

            logActivity('Création', 'annees_scolaires', $anneeId, "Année créée : {$libelle}");

            jsonResponse(true, 'Année scolaire créée avec succès.', ['id' => $anneeId]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur création année: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création.');
        }
    }

    /**
     * Modifie une année scolaire
     */
    private function modifier(): void
    {
        requirePermission('parametres.modifier');

        $id         = (int) post('id', 0);
        $libelle    = post('libelle', '');
        $dateDebut  = post('date_debut', '');
        $dateFin    = post('date_fin', '');
        $trimestresInput = $_POST['trimestres'] ?? null;

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $annee = $this->getAnneeService()->getById($id);
        if (!$annee) jsonResponse(false, 'Année introuvable.', [], 404);

        // Ne pas modifier une année clôturée
        if ($annee['est_cloturee']) {
            jsonResponse(false, 'Impossible de modifier une année clôturée.', [], 403);
        }

        $errors = [];

        if (isBlank($libelle)) $errors['libelle'] = 'Le libellé est obligatoire.';
        elseif (!preg_match('/^\d{4}-\d{4}$/', $libelle)) $errors['libelle'] = 'Format invalide.';
        else {
            $existe = $this->getAnneeService()->countLabel($libelle, $id);
            if ($existe > 0) $errors['libelle'] = 'Cette année existe déjà.';
        }

        if (isBlank($dateDebut)) $errors['date_debut'] = 'Date obligatoire.';
        elseif (!isValidDate($dateDebut)) $errors['date_debut'] = 'Date invalide.';
        if (isBlank($dateFin)) $errors['date_fin'] = 'Date obligatoire.';
        elseif (!isValidDate($dateFin)) $errors['date_fin'] = 'Date invalide.';
        if (!isBlank($dateDebut) && !isBlank($dateFin) && $dateDebut >= $dateFin) {
            $errors['date_fin'] = 'La date de fin doit être après la date de début.';
        }

        $trimestresValides = [];
        if ($trimestresInput !== null && empty($errors)) {
            $validationTrimestres = $this->validerDatesTrimestres($trimestresInput, $dateDebut, $dateFin);
            $errors = array_merge($errors, $validationTrimestres['errors']);
            $trimestresValides = $validationTrimestres['dates'];
        }

        // Vérifier le chevauchement
        if (!isBlank($dateDebut) && !isBlank($dateFin) && empty($errors)) {
            $chevauche = $this->getAnneeService()->countOverlappingDates($dateDebut, $dateFin, $id);
            if ($chevauche > 0) $errors['date_debut'] = 'Cette période chevauche une autre année.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->beginTransaction();
            $this->anneeService->updateYear([
                'libelle' => $libelle, 'debut' => $dateDebut, 'fin' => $dateFin, 'id' => $id
            ]);
            if ($trimestresInput !== null) {
                $this->enregistrerTrimestres($id, $trimestresValides);
            }
            $this->db->commit();

            logActivity('Modification', 'annees_scolaires', $id, "Année modifiée : {$libelle}");

            jsonResponse(true, 'Année modifiée avec succès.');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur modification année: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    /**
     * Active une année (désactive les autres)
     */
    private function activer(): void
    {
        requirePermission('parametres.modifier');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        try {
            $this->db->beginTransaction();

            $configuration = $this->getAnneeService()->findSchoolYearConfigurationForUpdate();
            if (!$configuration) {
                $this->db->rollback();
                jsonResponse(false, 'Configuration de l’année active introuvable.', [], 500);
            }

            $annee = $this->getAnneeService()->findForUpdate($id);
            if (!$annee) {
                $this->db->rollback();
                jsonResponse(false, 'Année introuvable.', [], 404);
            }
            if ($annee['est_cloturee']) {
                $this->db->rollback();
                jsonResponse(false, 'Impossible d\'activer une année clôturée.', [], 403);
            }

            // Désactiver toutes les années
            $this->anneeService->deactivateAllYears();

            // Activer celle-ci
            $this->anneeService->activateYear($id);

            // Mettre à jour parametres_ecole
            $this->anneeService->setActiveYearInSchoolSettings($id);

            $this->db->commit();

            logActivity('Activation', 'annees_scolaires', $id, "Année activée : {$annee['libelle']}");

            jsonResponse(true, "Année {$annee['libelle']} activée avec succès.");

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur activation année: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de l\'activation.');
        }
    }

    /**
     * Clôture une année
     */
    private function cloturer(): void
    {
        requirePermission('parametres.modifier');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        try {
            $this->db->beginTransaction();

            $configuration = $this->getAnneeService()->findSchoolYearConfigurationForUpdate();
            if (!$configuration) {
                $this->db->rollback();
                jsonResponse(false, 'Configuration de l’année active introuvable. Aucune clôture effectuée.', [], 409);
            }

            $annee = $this->getAnneeService()->findForUpdate($id);
            if (!$annee) {
                $this->db->rollback();
                jsonResponse(false, 'Année introuvable.', [], 404);
            }
            if ($annee['est_cloturee']) {
                $this->db->rollback();
                jsonResponse(false, 'Cette année est déjà clôturée.', [], 409);
            }

            $anneeActiveId = (int) $configuration['annee_scolaire_active_id'];
            if ($anneeActiveId === $id || !empty($annee['est_active'])) {
                $this->db->rollback();
                jsonResponse(
                    false,
                    'Clôture refusée : activez d’abord une autre année scolaire. L’année active ne peut pas être clôturée.',
                    [],
                    409
                );
            }

            $anneeActive = $anneeActiveId > 0
                ? $this->anneeService->findValidActiveYearForUpdate($anneeActiveId)
                : null;
            if (!$anneeActive) {
                $this->db->rollback();
                jsonResponse(
                    false,
                    'Clôture refusée : aucune année active valide n’est configurée. Activez d’abord l’année à utiliser.',
                    [],
                    409
                );
            }

            $this->anneeService->closeYear($id);
            $this->db->commit();

            logActivity('Clôture', 'annees_scolaires', $id, "Année clôturée : {$annee['libelle']}");

            jsonResponse(true, "Année {$annee['libelle']} clôturée avec succès.");
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur clôture année: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la clôture.');
        }
    }

    /**
     * Supprime une année (si aucune inscription)
     */
    private function supprimer(): void
    {
        requirePermission('parametres.modifier');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $annee = $this->getAnneeService()->getById($id);
        if (!$annee) jsonResponse(false, 'Année introuvable.', [], 404);

        // Vérifier les inscriptions
        $nbInscriptions = $this->getAnneeService()->countEnrollments($id);
        if ($nbInscriptions > 0) {
            jsonResponse(false, "Impossible de supprimer : {$nbInscriptions} élève(s) inscrit(s).", [], 409);
        }

        // Vérifier les classes
        $nbClasses = $this->getAnneeService()->countClasses($id);
        if ($nbClasses > 0) {
            jsonResponse(false, "Impossible de supprimer : {$nbClasses} classe(s) liée(s).", [], 409);
        }

        try {
            // Supprimer les trimestres
            $this->getAnneeService()->deleteTerms($id);
            // Supprimer l'année
            $this->getAnneeService()->deleteYear($id);

            logActivity('Suppression', 'annees_scolaires', $id, "Année supprimée : {$annee['libelle']}");

            jsonResponse(true, 'Année supprimée avec succès.');

        } catch (Exception $e) {
            error_log('Erreur suppression année: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la suppression.');
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Crée automatiquement les 3 trimestres pour une année
     */
    public function validerDatesTrimestres(array $input, string $dateDebut, string $dateFin): array
    {
        return $this->getAnneeService()->validateTermDates($input, $dateDebut, $dateFin);
    }

    private function repartirTrimestres(string $dateDebut, string $dateFin): array
    {
        return $this->getAnneeService()->distributeTerms($dateDebut, $dateFin);
    }

    public function enregistrerTrimestres(int $anneeId, array $trimestres): void
    {
        $this->getAnneeService()->saveTerms($anneeId, $trimestres);
    }

    public function getTrimestresByAnnee(int $anneeId): array
    {
        return $this->getAnneeService()->getTerms($anneeId);
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère toutes les années
     */
    public function getAllAnnees(): array
    {
        return $this->getAnneeService()->getAll();
    }

    /**
     * Récupère l'année active
     */
    public function getAnneeActive(): ?array
    {
        return $this->getAnneeService()->getActive();
    }

    /**
     * Récupère une année par ID
     */
    public function getAnneeById(int $id): ?array
    {
        return $this->getAnneeService()->getById($id);
    }

    private function getAnneeService(): AnneeScolaireService
    {
        if ($this->anneeService === null) {
            $this->anneeService = new AnneeScolaireService();
        }
        return $this->anneeService;
    }
}