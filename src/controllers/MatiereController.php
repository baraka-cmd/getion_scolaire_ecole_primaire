<?php
/**
 * Fichier : src/controllers/MatiereController.php
 * Rôle    : Gestion complète des matières, groupes, sous-matières et maxima
 * Système : Bulletin congolais — Groupes → Sous-matières → Maxima par période
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/MatiereService.php';

class MatiereController
{
    private MatiereService $matiereService;

    public function __construct(?MatiereService $matiereService = null)
    {
        $this->matiereService = $matiereService ?? new MatiereService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('matieres.consulter')) {
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
            case 'getMatiere':
                $this->getMatiere();
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
            case 'getSousMatieres':
                $this->getSousMatieres();
                break;
            case 'getClasses':
                $this->getClasses();
                break;
            case 'associerClasse':
                $this->associerClasse();
                break;
            case 'dissocierClasse':
                $this->dissocierClasse();
                break;
            case 'updateMaxima':
                $this->updateMaxima();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // LISTE HIÉRARCHIQUE : GROUPES → SOUS-MATIÈRES
    // ============================================================

    /**
     * Liste paginée : d'abord les groupes, puis leurs sous-matières
     */
    private function getListe(): void
    {
        $page       = max(1, (int) post('page', 1));
        $recherche  = post('recherche', '');
        $parPage    = ITEMS_PER_PAGE;

        $offset = ($page - 1) * $parPage;
        $liste = $this->matiereService->getPaginatedGroupHierarchy(
            (string) $recherche,
            (int) $parPage,
            (int) $offset
        );
        $total = $liste['total'];
        $groupes = $liste['groupes'];

        jsonResponse(true, 'Liste récupérée.', [
            'groupes' => $groupes,
            'total'   => $total,
            'page'    => $page,
            'pages'   => ceil($total / $parPage)
        ]);
    }

    // ============================================================
    // RÉCUPÉRATION D'UNE MATIÈRE (GROUPE OU SOUS-MATIÈRE)
    // ============================================================

    private function getMatiere(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $details = $this->matiereService->getForAjax($id);
        if ($details === null) jsonResponse(false, 'Matière introuvable.', [], 404);

        jsonResponse(true, 'Matière récupérée.', [
            'matiere'       => $details['matiere'],
            'sous_matieres' => $details['sous_matieres'],
            'groupes'       => $details['groupes']
        ]);
    }

    // ============================================================
    // RÉCUPÉRATION DES SOUS-MATIÈRES D'UN GROUPE
    // ============================================================

    private function getSousMatieres(): void
    {
        $groupeId = (int) post('groupe_id', 0);

        $sousMatieres = $this->matiereService->getSubSubjectsForAjax($groupeId);

        jsonResponse(true, 'Sous-matières récupérées.', ['sous_matieres' => $sousMatieres]);
    }

    // ============================================================
    // CRÉATION D'UNE MATIÈRE (GROUPE OU SOUS-MATIÈRE)
    // ============================================================

    private function creer(): void
{
    requirePermission('matieres.creer');

    $code        = post('code', '');
    $libelle     = post('libelle', '');
    $description = post('description', '');
    $maxima      = (int) post('maxima', 20);
    $estGroupeRaw = post('est_groupe');
    $estGroupe   = in_array($estGroupeRaw, ['0', '1'], true) ? (int) $estGroupeRaw : -1;
    $parentId    = (int) post('parent_id', 0);

    $errors = [];

    if ($estGroupe === -1) {
        $errors['est_groupe'] = 'Choisissez un type de matière valide.';
    }

    if (isBlank($libelle)) {
        $errors['libelle'] = 'Le libellé est obligatoire.';
    } elseif (mb_strlen($libelle) < 2) {
        $errors['libelle'] = 'Minimum 2 caractères.';
    } elseif (mb_strlen($libelle) > 150) {
        $errors['libelle'] = 'Maximum 150 caractères.';
    } else {
        $existe = $this->matiereService->countActiveLabel(trim($libelle));
        if ($existe > 0) $errors['libelle'] = 'Cette matière existe déjà.';
    }

    if (!isBlank($code)) {
        if (mb_strlen($code) > 20) {
            $errors['code'] = 'Maximum 20 caractères.';
        } else {
            $codeExiste = $this->matiereService->countCode(mb_strtoupper(trim($code)));
            if ($codeExiste > 0) $errors['code'] = 'Ce code est déjà utilisé.';
        }
    }

    // Validation du maxima UNIQUEMENT pour les sous-matières
    if (!$estGroupe) {
        if ($maxima < 1 || $maxima > 1000) {
            $errors['maxima'] = 'Le maxima doit être entre 1 et 1000.';
        }
    }

    // Si sous-matière, vérifier le parent
    if (!$estGroupe && $parentId <= 0) {
        $errors['parent_id'] = 'Veuillez sélectionner le groupe parent.';
    } elseif (!$estGroupe && $parentId > 0) {
        $parentExiste = $this->matiereService->isActiveGroup($parentId);
        if (!$parentExiste) $errors['parent_id'] = 'Groupe parent invalide.';
    }

    if (!empty($errors)) {
        jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
    }

    try {
        // Le service garantit l'atomicité de la création et des associations.
        $matiereId = $this->matiereService->createSubject(
            [
                'code'        => !empty($code) ? mb_strtoupper(trim($code)) : null,
                'libelle'     => formatNom($libelle),
                'description' => $description ?: null,
                'maxima'      => $estGroupe ? 0 : $maxima,
                'est_groupe'  => $estGroupe,
                'parent_id'   => $estGroupe ? null : ($parentId > 0 ? $parentId : null),
            ],
            !$estGroupe && is_array($_POST['classes'] ?? null) ? $_POST['classes'] : []
        );

        logActivity('Création', 'matieres', $matiereId, "Matière créée : {$libelle}");

        $typeMsg = $estGroupe ? 'Groupe' : 'Sous-matière';
        jsonResponse(true, "{$typeMsg} créé(e) avec succès.", ['id' => $matiereId]);

    } catch (Exception $e) {
        error_log('Erreur création matière: ' . $e->getMessage());
        jsonResponse(false, 'Erreur lors de la création.');
    }
}


    // ============================================================
    // MODIFICATION D'UNE MATIÈRE
    // ============================================================

    private function modifier(): void
    {
        requirePermission('matieres.modifier');

        $id          = (int) post('id', 0);
        $code        = post('code', '');
        $libelle     = post('libelle', '');
        $description = post('description', '');
        $maxima      = (int) post('maxima', 20);
        $parentId    = (int) post('parent_id', 0);

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $existant = $this->matiereService->getDetail($id);
        if (!$existant) jsonResponse(false, 'Matière introuvable.', [], 404);

        $errors = [];

        if (isBlank($libelle)) $errors['libelle'] = 'Le libellé est obligatoire.';
        elseif (mb_strlen($libelle) < 2) $errors['libelle'] = 'Minimum 2 caractères.';
        else {
            $existe = $this->matiereService->countActiveLabel(trim($libelle), $id);
            if ($existe > 0) $errors['libelle'] = 'Cette matière existe déjà.';
        }

        if (!isBlank($code)) {
            $codeExiste = $this->matiereService->countCode(mb_strtoupper(trim($code)), $id);
            if ($codeExiste > 0) $errors['code'] = 'Ce code est déjà utilisé.';
        }

        if ($maxima < 1 || $maxima > 1000) {
            $errors['maxima'] = 'Le maxima doit être entre 1 et 1000.';
        }

        // Ne pas permettre de changer est_groupe
        // Si c'est une sous-matière, vérifier le parent
        if (!$existant['est_groupe'] && $parentId <= 0) {
            $errors['parent_id'] = 'Veuillez sélectionner le groupe parent.';
        } elseif (!$existant['est_groupe']) {
            if (!$this->matiereService->isActiveGroup($parentId)) {
                $errors['parent_id'] = 'Groupe parent invalide.';
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $updateData = [
                'code'       => !empty($code) ? mb_strtoupper(trim($code)) : null,
                'libelle'    => formatNom($libelle),
                'desc'       => $description ?: null,
                'maxima'     => $maxima,
                'id'         => $id
            ];

            $updateParent = !$existant['est_groupe'] && $parentId > 0;
            if ($updateParent) {
                $updateData['parent_id'] = $parentId;
            }

            $this->matiereService->updateSubject($updateData, $updateParent);

            logActivity('Modification', 'matieres', $id, "Matière modifiée : {$libelle}");

            jsonResponse(true, 'Matière modifiée avec succès.');

        } catch (Exception $e) {
            error_log('Erreur modification matière: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    // ============================================================
    // SUPPRESSION
    // ============================================================

    private function supprimer(): void
    {
        requirePermission('matieres.supprimer');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $matiere = $this->matiereService->getDetail($id);
        if (!$matiere) jsonResponse(false, 'Matière introuvable.', [], 404);

        // Si c'est un groupe, vérifier qu'il n'a pas de sous-matières
        if ($matiere['est_groupe']) {
            $nbSousMatieres = $this->matiereService->countActiveChildren($id);
            if ($nbSousMatieres > 0) {
                jsonResponse(false, "Impossible de supprimer ce groupe : {$nbSousMatieres} sous-matière(s) active(s).", [], 409);
            }
        }

        // Vérifier les évaluations
        $nbEvaluations = $this->matiereService->countEvaluationsForSubject($id);
        if ($nbEvaluations > 0) {
            jsonResponse(false, "Impossible de supprimer : {$nbEvaluations} évaluation(s) liée(s).", [], 409);
        }

        // Vérifier l'emploi du temps
        $nbEDT = $this->matiereService->countTimetableEntriesForSubject($id);
        if ($nbEDT > 0) {
            jsonResponse(false, "Impossible de supprimer : utilisée dans l'emploi du temps.", [], 409);
        }

        try {
            $this->matiereService->deleteSubjectWithAssociations($id);

            logActivity('Suppression', 'matieres', $id, "Matière supprimée : {$matiere['libelle']}");

            jsonResponse(true, 'Matière supprimée avec succès.');

        } catch (Exception $e) {
            error_log('Erreur suppression matière: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la suppression.');
        }
    }

    // ============================================================
    // GESTION DES CLASSES (ASSOCIATION)
    // ============================================================

    private function getClasses(): void
    {
        $matiereId = (int) post('matiere_id', 0);

        $details = $this->matiereService->getClassesForSubject($matiereId);

        jsonResponse(true, 'Classes récupérées.', [
            'classes'       => $details['classes'],
            'maxima_global' => $details['maxima_global']
        ]);
    }

    private function associerClasse(): void
    {
        requirePermission('matieres.modifier');

        $matiereId = (int) post('matiere_id', 0);
        $classeId  = (int) post('classe_id', 0);
        $maxima    = post('maxima_override', null);

        if ($matiereId <= 0 || $classeId <= 0) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }

        $maximaValue = ($maxima !== '' && $maxima !== null) ? (int) $maxima : null;

        if ($maximaValue !== null && ($maximaValue < 1 || $maximaValue > 1000)) {
            jsonResponse(false, 'Maxima invalide (1-1000).', [], 422);
        }

        $this->matiereService->associateClass($matiereId, $classeId, $maximaValue);

        logActivity('Association', 'classe_matieres', null, "Matière {$matiereId} ↔ Classe {$classeId}");
        jsonResponse(true, 'Association réussie.');
    }

    private function dissocierClasse(): void
    {
        requirePermission('matieres.modifier');

        $matiereId = (int) post('matiere_id', 0);
        $classeId  = (int) post('classe_id', 0);

        if ($matiereId <= 0 || $classeId <= 0) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }

        // Vérifier les évaluations
        $hasEvaluations = $this->matiereService->dissociateClass($matiereId, $classeId);

        if ($hasEvaluations) {
            jsonResponse(true, 'Matière désactivée pour cette classe (évaluations existantes conservées).');
        } else {
            jsonResponse(true, 'Association supprimée.');
        }

        logActivity('Dissociation', 'classe_matieres', null, "Matière {$matiereId} ↮ Classe {$classeId}");
    }

    private function updateMaxima(): void
    {
        requirePermission('matieres.modifier');

        $matiereId = (int) post('matiere_id', 0);
        $classeId  = (int) post('classe_id', 0);
        $maxima    = post('maxima_override', null);

        if ($matiereId <= 0 || $classeId <= 0) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }

        $maximaValue = ($maxima !== '' && $maxima !== null) ? (int) $maxima : null;

        if ($maximaValue !== null && ($maximaValue < 1 || $maximaValue > 1000)) {
            jsonResponse(false, 'Maxima invalide (1-1000).', [], 422);
        }

        $this->matiereService->updateClassMaxima($matiereId, $classeId, $maximaValue);

        jsonResponse(true, 'Maxima mis à jour.');
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère tous les groupes actifs
     */
    public function getGroupes(): array
    {
        return $this->matiereService->getGroups();
    }

    /**
     * Récupère les sous-matières d'un groupe
     */
    public function getSousMatieresByGroupe(int $groupeId): array
    {
        return $this->matiereService->getSubSubjectsByGroup($groupeId);
    }

    /**
     * Récupère une matière par ID
     */
    public function getMatiereDetail(int $id): ?array
    {
        return $this->matiereService->getDetail($id);
    }

    /**
     * Récupère les classes actives
     */
    public function getClassesActives(): array
    {
        return $this->matiereService->getActiveClasses();
    }

    /**
     * Calcule le total des maxima pour une classe (1ère période)
     * Somme de tous les maxima de toutes les sous-matières actives
     */
    public function getTotalMaximaClasse(int $classeId): int
    {
        return $this->matiereService->getTotalMaximaForClass($classeId);
    }

    /**
     * Calcule la moyenne d'un élève pour une sous-matière sur un trimestre
     * Formule congolaise : Total = Note P1 + Note P2 + Note Examen
     * Pourcentage = (Total obtenu / Total maxima) × 100
     */
    public function calculerMoyenneSousMatiere(int $eleveId, int $matiereId, int $trimestreId): ?array
    {
        return $this->matiereService->calculateSubSubjectScore($eleveId, $matiereId, $trimestreId);
    }
}