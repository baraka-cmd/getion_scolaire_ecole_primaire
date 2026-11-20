<?php
/**
 * Fichier : src/controllers/ParametreController.php
 * Rôle    : Gestion des paramètres de l'école
 * Système : École primaire congolaise — Administration
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

class ParametreController
{
    private Database $db;
    private string $uploadDir;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->uploadDir = UPLOADS_PATH . 'logos' . DIRECTORY_SEPARATOR;
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
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
            case 'getParametres':
                $this->getParametres();
                break;
            case 'sauvegarder':
                $this->sauvegarder();
                break;
            case 'uploadLogo':
                $this->uploadLogo();
                break;
            case 'getAnnees':
                $this->getAnnees();
                break;
            case 'getTrimestres':
                $this->getTrimestres();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // 1. RÉCUPÉRER LES PARAMÈTRES ACTUELS
    // ============================================================

    private function getParametres(): void
    {
        $params = $this->db->fetchOne("SELECT * FROM parametres_ecole WHERE id = 1");

        if (!$params) {
            // Valeurs par défaut si la table est vide
            $params = [
                'id'                        => 1,
                'nom_ecole'                 => defined('ECOLE_NOM') ? ECOLE_NOM : 'École Primaire',
                'devise'                    => defined('ECOLE_DEVIS') ? ECOLE_DEVIS : 'Discipline - Travail - Excellence',
                'adresse'                   => '',
                'telephone'                 => '',
                'email'                     => '',
                'site_web'                  => '',
                'logo'                      => '',
                'pays'                      => 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO',
                'province'                  => '',
                'ministere'                 => "MINISTÈRE DE L'ENSEIGNEMENT PRIMAIRE, SECONDAIRE ET PROFESSIONNEL",
                'ville'                     => '',
                'commune_territoire'        => '',
                'code_etablissement'        => '',
                'annee_scolaire_active_id'  => null,
                'trimestre_actif_id'        => null,
                'bareme_note_max'           => 20,
                'moyenne_passage'           => 10,
                'pourcentage_interro'       => 30,
                'pourcentage_devoir'        => 30,
                'pourcentage_examen'        => 40,
                'format_matricule'          => 'EL-{ANNEE}-{ID}',
            ];
        }

        // Récupérer le libellé de l'année active
        if ($params['annee_scolaire_active_id']) {
            $annee = $this->db->fetchOne("SELECT libelle FROM annees_scolaires WHERE id = :id", ['id' => $params['annee_scolaire_active_id']]);
            $params['annee_active_libelle'] = $annee ? $annee['libelle'] : '';
        }

        // Récupérer le libellé du trimestre actif
        if ($params['trimestre_actif_id']) {
            $trimestre = $this->db->fetchOne("SELECT libelle FROM trimestres WHERE id = :id", ['id' => $params['trimestre_actif_id']]);
            $params['trimestre_actif_libelle'] = $trimestre ? $trimestre['libelle'] : '';
        }

        jsonResponse(true, 'Paramètres récupérés.', ['parametres' => $params]);
    }

    // ============================================================
    // 2. SAUVEGARDER LES PARAMÈTRES
    // ============================================================

    private function sauvegarder(): void
    {
        requirePermission('parametres.modifier');
        global $security;
        $security->validateCsrf();

        $nomEcole            = post('nom_ecole', '');
        $devise              = post('devise', '');
        $adresse             = post('adresse', '');
        $telephone           = post('telephone', '');
        $email               = post('email', '');
        $siteWeb             = post('site_web', '');
        $pays                = post('pays', '');
        $province            = trim((string) post('province', ''));
        $ministere           = post('ministere', '');
        $ville               = post('ville', '');
        $commune             = post('commune_territoire', '');
        $codeEtablissement   = post('code_etablissement', '');
        $themeInterface      = trim((string) post('theme_interface', 'sapphire'));
        $tauxCdfParUsd       = (float) post('taux_cdf_par_usd', 2500);
        $anneeActiveId       = (int) post('annee_scolaire_active_id', 0);
        $trimestreActifId    = (int) post('trimestre_actif_id', 0);
        $formatMatricule     = post('format_matricule', 'EL-{ANNEE}-{ID}');

        // Validation
        $errors = [];
        if (isBlank($nomEcole)) $errors['nom_ecole'] = 'Le nom de l\'école est obligatoire.';
        if (mb_strlen($province) > 100) $errors['province'] = 'La province ne peut pas dépasser 100 caractères.';
        if (!array_key_exists($themeInterface, APP_COLOR_THEMES)) {
            $errors['theme_interface'] = 'Veuillez sélectionner une palette de couleurs valide.';
        }
        if (!is_finite($tauxCdfParUsd) || $tauxCdfParUsd <= 0) {
            $errors['taux_cdf_par_usd'] = 'Le taux de change doit être supérieur à zéro.';
        }
        if ($anneeActiveId <= 0) $errors['annee_scolaire_active_id'] = 'Veuillez sélectionner une année scolaire active.';
        if ($trimestreActifId <= 0) $errors['trimestre_actif_id'] = 'Veuillez sélectionner un trimestre actif.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Veuillez saisir une adresse e-mail valide.';
        if ($siteWeb !== '') {
            $url = preg_match('/^https?:\/\//i', $siteWeb) ? $siteWeb : 'https://' . $siteWeb;
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors['site_web'] = 'Veuillez saisir une adresse de site web valide.';
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        $annee = $this->db->fetchOne(
            "SELECT id FROM annees_scolaires WHERE id = :id",
            ['id' => $anneeActiveId]
        );
        $trimestre = $this->db->fetchOne(
            "SELECT id FROM trimestres WHERE id = :id AND annee_scolaire_id = :annee_id",
            ['id' => $trimestreActifId, 'annee_id' => $anneeActiveId]
        );
        if (!$annee || !$trimestre) {
            jsonResponse(false, 'Le trimestre sélectionné ne correspond pas à l’année scolaire choisie.', [], 422);
        }

        try {
            $this->db->beginTransaction();
            $paramsExistants = $this->db->fetchOne("SELECT * FROM parametres_ecole WHERE id = 1");
            $baremeNoteMax = (float) ($paramsExistants['bareme_note_max'] ?? 20);
            $moyennePassage = (float) ($paramsExistants['moyenne_passage'] ?? 10);
            $pourcentageInterro = (float) ($paramsExistants['pourcentage_interro'] ?? 30);
            $pourcentageDevoir = (float) ($paramsExistants['pourcentage_devoir'] ?? 30);
            $pourcentageExamen = (float) ($paramsExistants['pourcentage_examen'] ?? 40);

            // Vérifier si la ligne existe
            $existe = $paramsExistants !== null;

            if ($existe) {
                // Mettre à jour
                $this->db->update(
                    "UPDATE parametres_ecole SET 
                        nom_ecole = :nom_ecole,
                        devise = :devise,
                        taux_cdf_par_usd = :taux_cdf_par_usd,
                        adresse = :adresse,
                        telephone = :telephone,
                        email = :email,
                        site_web = :site_web,
                        pays = :pays,
                        province = :province,
                        ministere = :ministere,
                        ville = :ville,
                        commune_territoire = :commune,
                        code_etablissement = :code_etab,
                        theme_interface = :theme_interface,
                        annee_scolaire_active_id = :annee_id,
                        trimestre_actif_id = :trim_id,
                        bareme_note_max = :bareme,
                        moyenne_passage = :moyenne,
                        pourcentage_interro = :pct_interro,
                        pourcentage_devoir = :pct_devoir,
                        pourcentage_examen = :pct_examen,
                        format_matricule = :format_mat
                     WHERE id = 1",
                    [
                        'nom_ecole'   => $nomEcole,
                        'devise'      => $devise,
                        'taux_cdf_par_usd' => $tauxCdfParUsd,
                        'adresse'     => $adresse ?: null,
                        'telephone'   => $telephone ?: null,
                        'email'       => $email ?: null,
                        'site_web'    => $siteWeb ?: null,
                        'pays'        => $pays ?: null,
                        'province'    => $province ?: null,
                        'ministere'   => $ministere ?: null,
                        'ville'       => $ville ?: null,
                        'commune'     => $commune ?: null,
                        'code_etab'   => $codeEtablissement ?: null,
                        'theme_interface' => $themeInterface,
                        'annee_id'    => $anneeActiveId,
                        'trim_id'     => $trimestreActifId,
                        'bareme'      => $baremeNoteMax,
                        'moyenne'     => $moyennePassage,
                        'pct_interro' => $pourcentageInterro,
                        'pct_devoir'  => $pourcentageDevoir,
                        'pct_examen'  => $pourcentageExamen,
                        'format_mat'  => $formatMatricule
                    ]
                );
            } else {
                // Créer
                $this->db->insert(
                    "INSERT INTO parametres_ecole (nom_ecole, devise, taux_cdf_par_usd, adresse, telephone, email, site_web,
                     pays, province, ministere, ville, commune_territoire, code_etablissement,
                     theme_interface, annee_scolaire_active_id, trimestre_actif_id, bareme_note_max, moyenne_passage,
                     pourcentage_interro, pourcentage_devoir, pourcentage_examen, format_matricule)
                     VALUES (:nom_ecole, :devise, :taux_cdf_par_usd, :adresse, :telephone, :email, :site_web,
                     :pays, :province, :ministere, :ville, :commune, :code_etab,
                     :theme_interface, :annee_id, :trim_id, :bareme, :moyenne, :pct_interro, :pct_devoir, :pct_examen, :format_mat)",
                    [
                        'nom_ecole'   => $nomEcole,
                        'devise'      => $devise,
                        'taux_cdf_par_usd' => $tauxCdfParUsd,
                        'adresse'     => $adresse ?: null,
                        'telephone'   => $telephone ?: null,
                        'email'       => $email ?: null,
                        'site_web'    => $siteWeb ?: null,
                        'pays'        => $pays ?: null,
                        'province'    => $province ?: null,
                        'ministere'   => $ministere ?: null,
                        'ville'       => $ville ?: null,
                        'commune'     => $commune ?: null,
                        'code_etab'   => $codeEtablissement ?: null,
                        'theme_interface' => $themeInterface,
                        'annee_id'    => $anneeActiveId,
                        'trim_id'     => $trimestreActifId,
                        'bareme'      => $baremeNoteMax,
                        'moyenne'     => $moyennePassage,
                        'pct_interro' => $pourcentageInterro,
                        'pct_devoir'  => $pourcentageDevoir,
                        'pct_examen'  => $pourcentageExamen,
                        'format_mat'  => $formatMatricule
                    ]
                );
            }

            // Mettre à jour l'année active dans la table annees_scolaires
            $this->db->update("UPDATE annees_scolaires SET est_active = 0");
            $this->db->update("UPDATE annees_scolaires SET est_active = 1 WHERE id = :id", ['id' => $anneeActiveId]);

            // Mettre à jour le trimestre actif
            $this->db->update(
                "UPDATE trimestres SET est_actif = 0 WHERE annee_scolaire_id = :annee_id",
                ['annee_id' => $anneeActiveId]
            );
            $this->db->update("UPDATE trimestres SET est_actif = 1 WHERE id = :id", ['id' => $trimestreActifId]);

            $this->db->commit();
            logActivity('Modification', 'parametres_ecole', 1, 'Paramètres de l\'école mis à jour; thème: ' . $themeInterface);

            jsonResponse(true, 'Paramètres sauvegardés avec succès.');

        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Erreur sauvegarde paramètres: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la sauvegarde des paramètres.');
        }
    }

    // ============================================================
    // 3. UPLOAD DU LOGO
    // ============================================================

    private function uploadLogo(): void
    {
        requirePermission('parametres.modifier');
        global $security;
        $security->validateCsrf();

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Aucun fichier sélectionné ou erreur d\'upload.', [], 400);
        }

        $fichier = $_FILES['logo'];
        $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        $typesAutorises = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];

        if (!isset($typesAutorises[$extension])) {
            jsonResponse(false, 'Format d\'image non autorisé (JPG, PNG, GIF uniquement).', [], 400);
        }

        if ((int) $fichier['size'] > 2 * 1024 * 1024) {
            jsonResponse(false, 'Le logo ne doit pas dépasser 2 Mo.', [], 400);
        }

        $imageInfo = @getimagesize($fichier['tmp_name']);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if (!$imageInfo || $finfo->file($fichier['tmp_name']) !== $typesAutorises[$extension]) {
            jsonResponse(false, 'Le fichier sélectionné n’est pas une image valide.', [], 400);
        }

        $params = $this->db->fetchOne("SELECT logo FROM parametres_ecole WHERE id = 1");
        if (!$params) {
            jsonResponse(false, 'Enregistrez d’abord les paramètres de l’école avant d’ajouter un logo.', [], 409);
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            jsonResponse(false, 'Le dossier de stockage du logo est indisponible.', [], 500);
        }

        $nomFichier = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $cheminRelatif = 'uploads/logos/' . $nomFichier;
        $cheminAbsolu = $this->uploadDir . $nomFichier;
        if (!move_uploaded_file($fichier['tmp_name'], $cheminAbsolu)) {
            jsonResponse(false, 'Erreur lors de la sauvegarde du logo.', [], 500);
        }

        try {
            $this->db->update(
                "UPDATE parametres_ecole SET logo = :logo WHERE id = 1",
                ['logo' => $cheminRelatif]
            );
        } catch (Throwable $e) {
            if (file_exists($cheminAbsolu)) {
                unlink($cheminAbsolu);
            }
            error_log('Erreur mise à jour logo: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la mise à jour du logo.', [], 500);
        }

        $ancienLogo = (string) ($params['logo'] ?? '');
        if (strpos($ancienLogo, 'uploads/logos/') === 0) {
            $ancienChemin = $this->uploadDir . basename($ancienLogo);
            if (is_file($ancienChemin) && $ancienChemin !== $cheminAbsolu) {
                unlink($ancienChemin);
            }
        }

        logActivity('Modification', 'parametres_ecole', 1, 'Logo de l’école mis à jour');
        jsonResponse(true, 'Logo mis à jour avec succès.', ['logo_url' => ASSETS_URL . $cheminRelatif]);
    }

    // ============================================================
    // 4. LISTE DES ANNÉES SCOLAIRES
    // ============================================================

    private function getAnnees(): void
    {
        $annees = $this->db->fetchAll("SELECT * FROM annees_scolaires ORDER BY date_debut DESC");
        jsonResponse(true, 'Années récupérées.', ['annees' => $annees]);
    }

    // ============================================================
    // 5. LISTE DES TRIMESTRES
    // ============================================================

    private function getTrimestres(): void
    {
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        }

        $trimestres = $this->db->fetchAll(
            "SELECT * FROM trimestres WHERE annee_scolaire_id = :aid ORDER BY numero",
            ['aid' => $anneeId]
        );

        jsonResponse(true, 'Trimestres récupérés.', ['trimestres' => $trimestres]);
    }
}