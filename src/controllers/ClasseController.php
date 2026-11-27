<?php
/**
 * Fichier : src/controllers/ClasseController.php
 * Rôle    : Gestion complète des classes (CRUD)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/ClasseService.php';

class ClasseController
{
    private ClasseService $classeService;

    public function __construct(?ClasseService $classeService = null)
    {
        $this->classeService = $classeService ?? new ClasseService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('classes.consulter')) {
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
            case 'getClasse':
                $this->getClasse();
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
            case 'getEleves':
                $this->getEleves();
                break;
            case 'changerTitulaire':
                $this->changerTitulaire();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Liste paginée des classes
     */
    private function getListe(): void
    {
        $page       = max(1, (int) post('page', 1));
        $recherche  = post('recherche', '');
        $anneeId    = (int) post('annee_id', 0);
        $ordre      = post('ordre', 'niveau');
        $direction  = post('direction', 'ASC');
        $parPage    = ITEMS_PER_PAGE;

        $colonnesAutorisees = ['id', 'nom', 'niveau', 'salle', 'capacite_max', 'nb_eleves'];
        if (!in_array($ordre, $colonnesAutorisees)) $ordre = 'niveau';
        if (!in_array(strtoupper($direction), ['ASC', 'DESC'])) $direction = 'ASC';

        $offset = ($page - 1) * $parPage;
        $liste = $this->classeService->getPaginatedList(
            (string) $recherche,
            $anneeId,
            (string) $ordre,
            (string) $direction,
            (int) $parPage,
            (int) $offset
        );
        $total = $liste['total'];
        $classes = $liste['classes'];

        jsonResponse(true, 'Liste récupérée.', [
            'classes' => $classes,
            'total'   => $total,
            'page'    => $page,
            'pages'   => ceil($total / $parPage)
        ]);
    }

    /**
     * Récupère une classe par son ID
     */
    private function getClasse(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $details = $this->classeService->getForAjax($id);
        if ($details === null) jsonResponse(false, 'Classe introuvable.', [], 404);

        jsonResponse(true, 'Classe récupérée.', [
            'classe'      => $details['classe'],
            'enseignants' => $details['enseignants']
        ]);
    }

    /**
     * Crée une nouvelle classe
     */
    private function creer(): void
    {
        requirePermission('classes.creer');

        $nom          = post('nom', '');
        $niveau       = (int) post('niveau', 0);
        $salle        = post('salle', '');
        $capaciteMax  = (int) post('capacite_max', 50);
        $anneeId      = (int) post('annee_scolaire_id', 0);
        $description  = post('description', '');
        $titulaireId  = (int) post('titulaire_id', 0);

        $errors = [];

        if (isBlank($nom)) {
            $errors['nom'] = 'Le nom de la classe est obligatoire.';
        } elseif (mb_strlen($nom) < 2) {
            $errors['nom'] = 'Minimum 2 caractères.';
        } elseif (mb_strlen($nom) > 50) {
            $errors['nom'] = 'Maximum 50 caractères.';
        } else {
            // Vérifier l'unicité pour la même année
            $existe = $this->classeService->countNameInYear(trim($nom), $anneeId);
            if ($existe > 0) $errors['nom'] = 'Cette classe existe déjà pour cette année scolaire.';
        }

        if ($niveau < 1 || $niveau > 12) {
            $errors['niveau'] = 'Niveau invalide (1-12).';
        }

        if (isBlank($salle)) {
            $errors['salle'] = 'La salle est obligatoire.';
        } elseif (mb_strlen($salle) > 20) {
            $errors['salle'] = 'Maximum 20 caractères.';
        } else {
            // Vérifier que la salle n'est pas déjà utilisée pour cette année
            $salleExistante = $this->classeService->countRoomInYear(trim($salle), $anneeId);
            if ($salleExistante > 0) $errors['salle'] = 'Cette salle est déjà attribuée pour cette année.';
        }

        if ($capaciteMax < 1 || $capaciteMax > 200) {
            $errors['capacite_max'] = 'Capacité invalide (1-200).';
        }

        if ($anneeId <= 0) {
            $errors['annee_scolaire_id'] = 'L\'année scolaire est obligatoire.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
$classeId=$this->classeService->createWithHolder(['nom'=>formatNom($nom),'niveau'=>$niveau,'salle'=>mb_strtoupper(trim($salle)),'capacite'=>$capaciteMax,'annee'=>$anneeId,'description'=>$description ?: null],$titulaireId);
        } catch (Exception $e) {
            error_log('Erreur création classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création de la classe.');
        }
    }

    /**
     * Modifie une classe existante
     */
    private function modifier(): void
    {
        requirePermission('classes.modifier');

        $id           = (int) post('id', 0);
        $nom          = post('nom', '');
        $niveau       = (int) post('niveau', 0);
        $salle        = post('salle', '');
        $capaciteMax  = (int) post('capacite_max', 50);
        $anneeId      = (int) post('annee_scolaire_id', 0);
        $description  = post('description', '');
        $titulaireId  = (int) post('titulaire_id', 0);

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        // Vérifier que la classe existe
        $classe = $this->classeService->findBasic($id);
        if (!$classe) jsonResponse(false, 'Classe introuvable.', [], 404);

        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        elseif (mb_strlen($nom) < 2) $errors['nom'] = 'Minimum 2 caractères.';
        else {
            $existe = $this->classeService->countNameInYear(trim($nom), $anneeId, $id);
            if ($existe > 0) $errors['nom'] = 'Ce nom existe déjà pour cette année.';
        }

        if ($niveau < 1 || $niveau > 12) $errors['niveau'] = 'Niveau invalide (1-12).';
        if (isBlank($salle)) $errors['salle'] = 'La salle est obligatoire.';
        else {
            $salleExistante = $this->classeService->countRoomInYear(trim($salle), $anneeId, $id);
            if ($salleExistante > 0) $errors['salle'] = 'Cette salle est déjà attribuée.';
        }

        if ($capaciteMax < 1 || $capaciteMax > 200) $errors['capacite_max'] = 'Capacité invalide (1-200).';
        if ($anneeId <= 0) $errors['annee_scolaire_id'] = 'L\'année scolaire est obligatoire.';

        // Vérifier que la capacité n'est pas inférieure au nombre d'élèves actuels
        $nbEleves = $this->classeService->countActiveStudents($id);
        if ($capaciteMax < $nbEleves) {
            $errors['capacite_max'] = "Capacité insuffisante ({$nbEleves} élèves inscrits).";
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
$this->classeService->updateWithHolder($id,['nom'=>formatNom($nom),'niveau'=>$niveau,'salle'=>mb_strtoupper(trim($salle)),'capacite'=>$capaciteMax,'annee'=>$anneeId,'description'=>$description ?: null,'id'=>$id],$titulaireId);
        } catch (Exception $e) {
            error_log('Erreur modification classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    /**
     * Supprime une classe
     */
    private function supprimer(): void
    {
        requirePermission('classes.supprimer');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $classe = $this->classeService->findBasic($id);
        if (!$classe) jsonResponse(false, 'Classe introuvable.', [], 404);

        // Vérifier les élèves inscrits
        $nbEleves = $this->classeService->countStudents($id);
        if ($nbEleves > 0) {
            jsonResponse(false, "Impossible de supprimer cette classe : {$nbEleves} élève(s) y sont inscrits.", [], 409);
        }

        // Vérifier les évaluations
        $nbEvaluations = $this->classeService->countEvaluations($id);
        if ($nbEvaluations > 0) {
            jsonResponse(false, "Impossible de supprimer cette classe : des évaluations y sont liées.", [], 409);
        }

        try {
$this->classeService->deleteWithSchedule($id);
        } catch (Exception $e) {
            error_log('Erreur suppression classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la suppression.');
        }
    }

    /**
     * Récupère la liste des élèves d'une classe
     */
    private function getEleves(): void
    {
        $classeId = (int) post('classe_id', 0);
        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);

        $eleves = $this->classeService->getStudentsForAjax($classeId);

        jsonResponse(true, 'Élèves récupérés.', ['eleves' => $eleves]);
    }

    /**
     * Change le titulaire d'une classe
     */
    private function changerTitulaire(): void
    {
        requirePermission('classes.modifier');

        $classeId    = (int) post('classe_id', 0);
        $titulaireId = (int) post('titulaire_id', 0);

        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);

        try {
            $this->classeService->changeHolder($classeId, $titulaireId);

            logActivity('Modification', 'classes', $classeId, 'Changement de titulaire');
            jsonResponse(true, 'Titulaire mis à jour avec succès.');

        } catch (Exception $e) {
            error_log('Erreur changement titulaire: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du changement de titulaire.');
        }
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère toutes les années scolaires (pour les formulaires)
     */
    public function getAnneesScolaires(): array
    {
        return $this->classeService->getSchoolYears();
    }

    /**
     * Récupère les enseignants disponibles (pour les formulaires)
     */
    public function getEnseignantsDisponibles(?int $classeId = null): array
    {
        return $this->classeService->getAvailableTeachers($classeId);
    }

    /**
     * Récupère une classe complète par ID (pour la vue détail)
     */
    public function getClasseDetail(int $id): ?array
    {
        return $this->classeService->getDetail($id);
    }

    /**
     * Récupère les élèves d'une classe
     */
    public function getElevesByClasse(int $classeId): array
    {
        return $this->classeService->getStudents($classeId);
    }
}