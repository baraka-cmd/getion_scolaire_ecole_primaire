<?php
/**
 * Fichier : src/controllers/EleveController.php
 * Rôle    : Gestion complète des élèves (CRUD + import/export)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/ParentStudentService.php';
require_once __DIR__ . '/../services/StudentRecordService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class EleveController
{
    private Database $db;
    private ParentStudentService $parentStudentService;
    private StudentRecordService $studentRecordService;
    private StudentAccessPolicy $studentAccessPolicy;

    public function __construct(
        ?ParentStudentService $parentStudentService = null,
        ?StudentAccessPolicy $studentAccessPolicy = null,
        ?StudentRecordService $studentRecordService = null
    )
    {
        $this->db = Database::getInstance();
        $this->parentStudentService = $parentStudentService ?? new ParentStudentService();
        $this->studentRecordService = $studentRecordService ?? new StudentRecordService();
        $this->studentAccessPolicy = $studentAccessPolicy ?? new StudentAccessPolicy($this->db);
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('eleves.consulter')) {
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
            case 'getEleve':
                $this->getEleve();
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
            case 'getParents':
                $this->getParents();
                break;
            case 'importer':
                $this->importer();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    /**
     * Liste paginée avec filtres
     */
    private function getListe(): void
    {
        $page = max(1, (int) post('page', 1));
        $filters = [
            'recherche' => post('recherche', ''),
            'classe_id' => (int) post('classe_id', 0),
            'sexe' => post('sexe', ''),
            'statut' => post('statut', ''),
            'ordre' => post('ordre', 'nom'),
            'direction' => post('direction', 'ASC'),
        ];

        $role = hasRole('Parent') ? 'Parent' : (hasRole('Enseignant') ? 'Enseignant' : '');
        $result = $this->studentRecordService->paginate(
            $filters,
            $page,
            ITEMS_PER_PAGE,
            $role,
            (int) ($_SESSION['utilisateur_id'] ?? 0)
        );

        jsonResponse(true, 'Liste récupérée.', [
            'eleves' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => ceil($result['total'] / ITEMS_PER_PAGE)
        ]);
    }

    /**
     * Récupère un élève par ID avec toutes ses infos
     */
    private function getEleve(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);
        if (!$this->canCurrentUserAccessStudent($id)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        $eleve = $this->studentRecordService->findRecord($id);
        if (!$eleve) jsonResponse(false, 'Élève introuvable.', [], 404);

        $parents = $this->parentStudentService->getStudentParentsForDetail($id);
        $history = $this->studentRecordService->getAcademicAndAttendanceHistory($id);

        jsonResponse(true, 'Élève récupéré.', [
            'eleve' => $eleve,
            'parents' => $parents,
            'notes' => $history['notes'],
            'absences' => $history['absences'],
            'paiements' => $history['paiements']
        ]);
    }

    /**
     * Crée un nouvel élève + inscription
     */
    private function creer(): void
    {
        requirePermission('eleves.creer');

        $nom              = post('nom', '');
        $prenom           = post('prenom', '');
        $dateNaissance    = post('date_naissance', '');
        $lieuNaissance    = post('lieu_naissance', '');
        $sexe             = post('sexe', '');
        $adresse          = post('adresse', '');
        $telephone        = post('telephone', '');
        $email            = post('email', '');
        $groupeSanguin    = post('groupe_sanguin', '');
        $allergies        = post('allergies', '');
        $maladies         = post('maladies', '');
        $classeId         = (int) post('classe_id', 0);
        $parent1Nom       = post('parent1_nom', '');
        $parent1Prenom    = post('parent1_prenom', '');
        $parent1Lien      = post('parent1_lien', '');
        $parent1Tel       = post('parent1_telephone', '');
        $parent1Email     = post('parent1_email', '');
        $parent1Prof      = post('parent1_profession', '');
        $parent2Nom       = post('parent2_nom', '');
        $parent2Prenom    = post('parent2_prenom', '');
        $parent2Lien      = post('parent2_lien', '');
        $parent2Tel       = post('parent2_telephone', '');

        // Validation
        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        elseif (mb_strlen($nom) < 2) $errors['nom'] = 'Minimum 2 caractères.';

        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        elseif (mb_strlen($prenom) < 2) $errors['prenom'] = 'Minimum 2 caractères.';

        if (isBlank($dateNaissance)) $errors['date_naissance'] = 'La date de naissance est obligatoire.';
        elseif (!isValidDate($dateNaissance)) $errors['date_naissance'] = 'Date invalide.';
        elseif (calculerAge($dateNaissance) > 25) $errors['date_naissance'] = 'Âge maximum : 25 ans.';
        elseif (calculerAge($dateNaissance) < 3) $errors['date_naissance'] = 'Âge minimum : 3 ans.';

        if (!in_array($sexe, ['M', 'F'])) $errors['sexe'] = 'Sexe invalide.';

        if (!isBlank($email) && !isValidEmail($email)) $errors['email'] = 'Email invalide.';

        if (!isBlank($telephone) && !isValidPhone($telephone)) $errors['telephone'] = 'Téléphone invalide.';

        if ($classeId <= 0) $errors['classe_id'] = 'La classe est obligatoire.';
        else {
            // Vérifier la capacité de la classe
            $capacite = (int) $this->db->fetchColumn(
                "SELECT capacite_max FROM classes WHERE id = :cid",
                ['cid' => $classeId]
            );
            $nbEleves = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
                ['cid' => $classeId]
            );
            if ($nbEleves >= $capacite) {
                $errors['classe_id'] = "Classe complète ({$capacite} élèves maximum).";
            }
        }

        // Vérifier les doublons (même nom, prénom, date de naissance)
        if (empty($errors)) {
            $doublon = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM eleves WHERE nom = :nom AND prenom = :prenom AND date_naissance = :dn",
                ['nom' => formatNom($nom), 'prenom' => formatNom($prenom), 'dn' => $dateNaissance]
            );
            if ($doublon > 0) $errors['doublon'] = 'Un élève avec le même nom, prénom et date de naissance existe déjà.';
        }

        // Validation parent 1 (obligatoire)
        if (isBlank($parent1Nom)) $errors['parent1_nom'] = 'Le nom du parent/tuteur est obligatoire.';
        if (isBlank($parent1Prenom)) $errors['parent1_prenom'] = 'Le prénom est obligatoire.';
        if (isBlank($parent1Tel)) $errors['parent1_telephone'] = 'Le téléphone est obligatoire.';
        if (isBlank($parent1Lien)) $errors['parent1_lien'] = 'Le lien est obligatoire.';

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->beginTransaction();

            // Générer le matricule
            $matricule = $this->generateMatricule();

            // Insérer l'élève
            $eleveId = $this->db->insert(
                "INSERT INTO eleves (matricule, nom, prenom, date_naissance, lieu_naissance, sexe, 
                     adresse, telephone, email, groupe_sanguin, allergies, maladies, date_inscription, est_actif)
                 VALUES (:matricule, :nom, :prenom, :dn, :ln, :sexe, :adresse, :tel, :email, 
                         :gs, :allergies, :maladies, CURDATE(), 1)",
                [
                    'matricule'  => $matricule,
                    'nom'        => formatNom($nom),
                    'prenom'     => formatNom($prenom),
                    'dn'         => $dateNaissance,
                    'ln'         => $lieuNaissance ?: null,
                    'sexe'       => $sexe,
                    'adresse'    => $adresse ?: null,
                    'tel'        => $telephone ?: null,
                    'email'      => $email ?: null,
                    'gs'         => $groupeSanguin ?: null,
                    'allergies'  => $allergies ?: null,
                    'maladies'   => $maladies ?: null
                ]
            );

            // Inscrire dans la classe
            $anneeActive = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
            $this->db->query(
                "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                 VALUES (:eid, :cid, :aid, CURDATE(), 'Actif')",
                ['eid' => $eleveId, 'cid' => $classeId, 'aid' => $anneeActive]
            );

            // Gérer le parent 1
            $parent1Id = $this->creerOuTrouverParent(
                $parent1Nom, $parent1Prenom, $parent1Lien, $parent1Tel, $parent1Email, $parent1Prof
            );
            $this->lierParent($eleveId, $parent1Id, true);

            // Gérer le parent 2 (optionnel)
            if (!isBlank($parent2Nom) && !isBlank($parent2Tel)) {
                $parent2Id = $this->creerOuTrouverParent(
                    $parent2Nom, $parent2Prenom, $parent2Lien, $parent2Tel
                );
                $this->lierParent($eleveId, $parent2Id, false);
            }

            $this->db->commit();

            logActivity('Création', 'eleves', $eleveId, "Élève créé : {$nom} {$prenom}");

            jsonResponse(true, 'Élève créé avec succès.', ['id' => $eleveId, 'matricule' => $matricule]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur création élève: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création de l\'élève.');
        }
    }

    /**
     * Modifie un élève existant
     */
    private function modifier(): void
    {
        requirePermission('eleves.modifier');

        $id               = (int) post('id', 0);
        $nom              = post('nom', '');
        $prenom           = post('prenom', '');
        $dateNaissance    = post('date_naissance', '');
        $lieuNaissance    = post('lieu_naissance', '');
        $sexe             = post('sexe', '');
        $adresse          = post('adresse', '');
        $telephone        = post('telephone', '');
        $email            = post('email', '');
        $groupeSanguin    = post('groupe_sanguin', '');
        $allergies        = post('allergies', '');
        $maladies         = post('maladies', '');

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $existant = $this->db->fetchOne("SELECT id FROM eleves WHERE id = :id", ['id' => $id]);
        if (!$existant) jsonResponse(false, 'Élève introuvable.', [], 404);

        $errors = [];

        if (isBlank($nom)) $errors['nom'] = 'Le nom est obligatoire.';
        if (isBlank($prenom)) $errors['prenom'] = 'Le prénom est obligatoire.';
        if (isBlank($dateNaissance)) $errors['date_naissance'] = 'La date de naissance est obligatoire.';
        elseif (!isValidDate($dateNaissance)) $errors['date_naissance'] = 'Date invalide.';
        if (!in_array($sexe, ['M', 'F'])) $errors['sexe'] = 'Sexe invalide.';

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $this->db->update(
                "UPDATE eleves 
                 SET nom = :nom, prenom = :prenom, date_naissance = :dn, lieu_naissance = :ln,
                     sexe = :sexe, adresse = :adresse, telephone = :tel, email = :email,
                     groupe_sanguin = :gs, allergies = :allergies, maladies = :maladies
                 WHERE id = :id",
                [
                    'nom'        => formatNom($nom),
                    'prenom'     => formatNom($prenom),
                    'dn'         => $dateNaissance,
                    'ln'         => $lieuNaissance ?: null,
                    'sexe'       => $sexe,
                    'adresse'    => $adresse ?: null,
                    'tel'        => $telephone ?: null,
                    'email'      => $email ?: null,
                    'gs'         => $groupeSanguin ?: null,
                    'allergies'  => $allergies ?: null,
                    'maladies'   => $maladies ?: null,
                    'id'         => $id
                ]
            );

            logActivity('Modification', 'eleves', $id, "Élève modifié : {$nom} {$prenom}");

            jsonResponse(true, 'Élève modifié avec succès.');

        } catch (Exception $e) {
            error_log('Erreur modification élève: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    /**
     * Désactive un élève (soft delete)
     */
    private function supprimer(): void
    {
        requirePermission('eleves.supprimer');

        $id = (int) post('id', 0);
        $motif = post('motif', '');

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $eleve = $this->db->fetchOne("SELECT id, nom, prenom FROM eleves WHERE id = :id", ['id' => $id]);
        if (!$eleve) jsonResponse(false, 'Élève introuvable.', [], 404);

        try {
            // Désactiver l'élève
            $this->db->update("UPDATE eleves SET est_actif = 0 WHERE id = :id", ['id' => $id]);

            // Mettre fin à l'inscription active
            $this->db->update(
                "UPDATE inscriptions SET statut = :statut, motif_statut = :motif, date_changement_statut = CURDATE()
                 WHERE eleve_id = :eid AND statut = 'Actif'",
                ['statut' => 'Abandon', 'motif' => $motif ?: 'Désactivation', 'eid' => $id]
            );

            logActivity('Suppression', 'eleves', $id, "Élève désactivé : {$eleve['nom']} {$eleve['prenom']}");

            jsonResponse(true, 'Élève désactivé avec succès.');

        } catch (Exception $e) {
            error_log('Erreur suppression élève: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la désactivation.');
        }
    }

    /**
     * Change la classe d'un élève
     */
    private function changerClasse(): void
    {
        requirePermission('inscriptions.modifier');

        $eleveId       = (int) post('eleve_id', 0);
        $ancienneClasseId = (int) post('ancienne_classe_id', 0);
        $nouvelleClasseId  = (int) post('nouvelle_classe_id', 0);
        $motif         = post('motif', '');

        if ($eleveId <= 0 || $nouvelleClasseId <= 0) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }

        // Vérifier la capacité
        $capacite = (int) $this->db->fetchColumn(
            "SELECT capacite_max FROM classes WHERE id = :cid", ['cid' => $nouvelleClasseId]
        );
        $nbEleves = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
            ['cid' => $nouvelleClasseId]
        );
        if ($nbEleves >= $capacite) {
            jsonResponse(false, "Classe complète ({$capacite} élèves max).", [], 409);
        }

        try {
            $this->db->beginTransaction();

            // Terminer l'ancienne inscription
            $this->db->update(
                "UPDATE inscriptions SET statut = 'Transféré', motif_statut = :motif, date_changement_statut = CURDATE()
                 WHERE eleve_id = :eid AND classe_id = :cid AND statut = 'Actif'",
                ['motif' => $motif ?: 'Changement de classe', 'eid' => $eleveId, 'cid' => $ancienneClasseId]
            );

            // Créer la nouvelle inscription
            $anneeActive = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
            $this->db->query(
                "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                 VALUES (:eid, :cid, :aid, CURDATE(), 'Actif')",
                ['eid' => $eleveId, 'cid' => $nouvelleClasseId, 'aid' => $anneeActive]
            );

            $this->db->commit();

            logActivity('Modification', 'eleves', $eleveId, "Changement de classe");

            jsonResponse(true, 'Classe changée avec succès.');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur changement classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du changement de classe.');
        }
    }

    /**
     * Récupère les parents d'un élève
     */
    private function getParents(): void
    {
        $eleveId = (int) post('eleve_id', 0);
        if ($eleveId <= 0) jsonResponse(false, 'ID invalide.', [], 400);
        if (!$this->canCurrentUserAccessStudent($eleveId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        $parents = $this->parentStudentService->getStudentParentsForAjax($eleveId);

        jsonResponse(true, 'Parents récupérés.', ['parents' => $parents]);
    }

    /**
     * Import CSV d'élèves
     */
    private function importer(): void
    {
        requirePermission('eleves.importer');

        if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Fichier invalide ou manquant.', [], 400);
        }

        $file = $_FILES['fichier'];
        if (
            !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])
            || (int) ($file['size'] ?? 0) <= 0
            || (int) $file['size'] > 5 * 1024 * 1024
        ) {
            jsonResponse(false, 'Le fichier CSV est invalide ou dépasse la limite de 5 Mo.', [], 400);
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            jsonResponse(false, 'Seuls les fichiers CSV sont acceptés.', [], 400);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) jsonResponse(false, 'Impossible de lire le fichier.', [], 500);

        $ligne = 0;
        $importes = 0;
        $erreurs = [];
        $anneeActive = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");

        // Lire l'en-tête
        $headers = fgetcsv($handle, 0, ';');
        if (!$headers) {
            fclose($handle);
            jsonResponse(false, 'Fichier CSV vide.', [], 400);
        }
        $headers = array_map(static fn($header) => trim((string) $header), $headers);

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $ligne++;
            if (count($data) !== count($headers)) {
                $erreurs[] = "Ligne {$ligne} : données insuffisantes.";
                continue;
            }

            $row = array_combine($headers, $data);
            if (!$row) {
                $erreurs[] = "Ligne {$ligne} : format invalide.";
                continue;
            }

            $nom           = trim($row['nom'] ?? '');
            $prenom        = trim($row['prenom'] ?? '');
            $dateNaissance = trim($row['date_naissance'] ?? '');
            $sexe          = strtoupper(trim($row['sexe'] ?? ''));
            $classeNom     = trim($row['classe'] ?? '');
            $parentNom     = trim($row['parent_nom'] ?? '');
            $parentTel     = trim($row['parent_telephone'] ?? '');

            // Validation
            $rowErrors = [];
            if (empty($nom)) $rowErrors[] = 'Nom manquant';
            if (empty($prenom)) $rowErrors[] = 'Prénom manquant';
            if (empty($dateNaissance) || !isValidDate($dateNaissance)) $rowErrors[] = 'Date invalide';
            if (!in_array($sexe, ['M', 'F'])) $rowErrors[] = 'Sexe invalide';

            if (!empty($rowErrors)) {
                $erreurs[] = "Ligne {$ligne} : " . implode(', ', $rowErrors);
                continue;
            }

            try {
                $this->db->beginTransaction();

                $matricule = $this->generateMatricule();

                $eleveId = $this->db->insert(
                    "INSERT INTO eleves (matricule, nom, prenom, date_naissance, sexe, date_inscription, est_actif)
                     VALUES (:mat, :nom, :prenom, :dn, :sexe, CURDATE(), 1)",
                    ['mat' => $matricule, 'nom' => formatNom($nom), 'prenom' => formatNom($prenom), 'dn' => $dateNaissance, 'sexe' => $sexe]
                );

                // Trouver la classe
                $classeId = $this->db->fetchColumn(
                    "SELECT id FROM classes WHERE nom = :nom AND annee_scolaire_id = :aid",
                    ['nom' => $classeNom, 'aid' => $anneeActive]
                );

                if ($classeId) {
                    $this->db->query(
                        "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                         VALUES (:eid, :cid, :aid, CURDATE(), 'Actif')",
                        ['eid' => $eleveId, 'cid' => $classeId, 'aid' => $anneeActive]
                    );
                }

                // Parent
                if (!empty($parentNom) && !empty($parentTel)) {
                    $parentId = $this->creerOuTrouverParent($parentNom, '', 'Père/Mère', $parentTel);
                    $this->lierParent($eleveId, $parentId, true);
                }

                $this->db->commit();
                $importes++;

            } catch (Exception $e) {
                $this->db->rollback();
                error_log("Erreur import élève ligne {$ligne}: " . $e->getMessage());
                $erreurs[] = "Ligne {$ligne} : enregistrement impossible.";
            }
        }

        fclose($handle);

        jsonResponse(true, "Import terminé : {$importes} élève(s) importé(s).", [
            'importes' => $importes,
            'erreurs'  => $erreurs
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Génère un matricule unique
     */
    private function generateMatricule(): string
    {
        $annee = date('y');
        $prefix = "EL-{$annee}-";
        $lastId = (int) $this->db->fetchColumn("SELECT MAX(id) FROM eleves") + 1;
        return $prefix . str_pad($lastId, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Crée un parent ou le retrouve s'il existe déjà
     */
    private function creerOuTrouverParent(string $nom, string $prenom, string $lien, string $tel, ?string $email = null, ?string $profession = null): int
    {
        return $this->parentStudentService->createOrFindParent(
            $nom,
            $prenom,
            $lien,
            $tel,
            $email,
            $profession
        );
    }

    /**
     * Lie un parent à un élève
     */
    private function lierParent(int $eleveId, int $parentId, bool $estPrincipal): void
    {
        $this->parentStudentService->linkIfMissing($eleveId, $parentId, $estPrincipal);
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère toutes les classes pour les formulaires
     */
    public function getClasses(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau
             FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    /**
     * Récupère un élève complet par ID
     */
    public function canCurrentUserAccessStudent(int $studentId): bool
    {
        return $this->studentAccessPolicy->canCurrentUserAccessStudent($studentId);
    }

    public function getEleveDetail(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT e.*, 
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format,
                    TIMESTAMPDIFF(YEAR, e.date_naissance, CURDATE()) AS age,
                    c.nom AS classe_nom, c.id AS classe_id, c.niveau AS classe_niveau,
                    i.date_inscription AS date_inscription_classe,
                    i.statut AS statut_inscription,
                    CONCAT(COALESCE(ens.prenom, ''), ' ', COALESCE(ens.nom, '')) AS titulaire_nom
             FROM eleves e
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             LEFT JOIN classes c ON i.classe_id = c.id
             LEFT JOIN enseignants ens ON c.id = ens.classe_id AND ens.est_actif = 1
             WHERE e.id = :id",
            ['id' => $id]
        );
    }

    /**
     * Récupère les parents d'un élève
     */
    public function getParentsEleve(int $eleveId): array
    {
        return $this->parentStudentService->getStudentParents($eleveId);
    }

    /**
     * Récupère l'historique des inscriptions
     */
    public function getHistoriqueInscriptions(int $eleveId): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, c.nom AS classe_nom, a.libelle AS annee_libelle
             FROM inscriptions i
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN annees_scolaires a ON i.annee_scolaire_id = a.id
             WHERE i.eleve_id = :eid
             ORDER BY i.date_inscription DESC",
            ['eid' => $eleveId]
        );
    }
}