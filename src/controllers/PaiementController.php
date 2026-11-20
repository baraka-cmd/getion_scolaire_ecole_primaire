<?php
/**
 * Fichier : src/controllers/PaiementController.php
 * Rôle    : Gestion financière — Paiements, reçus, impayés
 * Système : École primaire congolaise
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/FinanceService.php';
require_once __DIR__ . '/EleveController.php';

class PaiementController
{
    private Database $db;
    private FinanceService $finance;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->finance = new FinanceService($this->db);
    }

    /**
 * Point d'entrée pour les requêtes AJAX — AJOUTER CES 3 CASES
 */
public function handleAjax(): void
{
    if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Requête invalide.', [], 400);
    }

    $action = post('action', '');
    $permissions = [
        'getListePaiements' => 'paiements.consulter',
        'getRegistrePaiements' => 'paiements.consulter',
        'getCreancesEleve' => 'paiements.enregistrer',
        'genererCreancesAnnee' => 'paiements.modifier',
        'enregistrerPaiement' => 'paiements.enregistrer',
        'getImpayes' => 'paiements.consulter',
        'getRecu' => 'paiements.consulter',
        'getStatsPaiements' => 'paiements.consulter',
        'rechercherEleve' => 'paiements.enregistrer',
        'annulerPaiement' => 'paiements.supprimer',
        'enregistrerSuiviFinancier' => 'paiements.modifier',
        'getListeFrais' => 'paiements.consulter',
        'ajouterFrais' => 'paiements.modifier',
        'modifierFrais' => 'paiements.modifier',
        'supprimerFrais' => 'paiements.modifier',
        'toggleStatutFrais' => 'paiements.modifier',
    ];
    if (isset($permissions[$action]) && !can($permissions[$action])) {
        jsonResponse(false, 'Accès refusé.', [], 403);
    }

    switch ($action) {
        case 'getListePaiements':
            $this->getListePaiements();
            break;
        case 'getRegistrePaiements':
            $this->getRegistrePaiements();
            break;
        case 'getCreancesEleve':
            $this->getCreancesEleve();
            break;
        case 'genererCreancesAnnee':
            $this->genererCreancesAnnee();
            break;
        case 'enregistrerPaiement':
            $this->enregistrerPaiement();
            break;
        case 'getImpayes':
            $this->getImpayes();
            break;
        case 'getRecu':
            $this->getRecu();
            break;
        case 'getStatsPaiements':
            $this->getStatsPaiements();
            break;
        case 'rechercherEleve':
            $this->rechercherEleve();
            break;
        case 'annulerPaiement':
            $this->annulerPaiement();
            break;
        case 'enregistrerSuiviFinancier':
            $this->enregistrerSuiviFinancier();
            break;
        // ============================================================
        // FRAIS SCOLAIRES
        // ============================================================
        case 'getListeFrais':
            $this->getListeFrais();
            break;
        case 'ajouterFrais':
            $this->ajouterFrais();
            break;
        case 'modifierFrais':
            $this->modifierFrais();
            break;
        case 'supprimerFrais':
            $this->supprimerFrais();
            break;
        case 'toggleStatutFrais':
            $this->toggleStatutFrais();
            break;
        default:
            jsonResponse(false, 'Action inconnue.', [], 400);
    }
}

// ============================================================
// MÉTHODES FRAIS SCOLAIRES
// ============================================================

/**
 * Liste des frais scolaires
 */
public function getListeFrais(): void
{
    $anneeId = (int) post('annee_id', 0);
    if ($anneeId <= 0) {
        $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
    }

    $frais = $this->db->fetchAll(
        "SELECT f.*, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance,
                (SELECT COUNT(*) FROM finance_creances c WHERE c.frais_scolaire_id = f.id) AS nb_creances,
                (SELECT COALESCE(SUM(a.montant_affecte), 0)
                 FROM finance_affectations a
                 INNER JOIN finance_creances c ON c.id = a.creance_id
                 INNER JOIN finance_versements v ON v.id = a.versement_id
                 LEFT JOIN finance_annulations an ON an.versement_id = v.id
                 WHERE c.frais_scolaire_id = f.id AND an.id IS NULL) AS total_encaisse
         FROM frais_scolaires f
         INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
         WHERE f.annee_scolaire_id = :aid
         ORDER BY f.est_obligatoire DESC, f.libelle",
        ['aid' => $anneeId]
    );

    $totalFrais = count($frais);
    $totalEncaisse = array_sum(array_column($frais, 'total_encaisse'));

    jsonResponse(true, 'Liste récupérée.', [
        'frais'          => $frais,
        'total_frais'    => $totalFrais,
        'total_encaisse' => $totalEncaisse
    ]);
}

/**
 * Ajouter un nouveau frais scolaire
 */
public function ajouterFrais(): void
{
    requirePermission('paiements.modifier');

    $libelle       = post('libelle', '');
    $description   = post('description', '');
    $montant       = (float) post('montant', 0);
    $estObligatoire = (int) post('est_obligatoire', 1);
    $classeId      = (int) post('classe_id', 0);
    $anneeId       = (int) post('annee_scolaire_id', 0);
    $categorie     = trim((string) post('categorie', 'Scolarité'));
    $devise        = (string) post('devise', 'USD');
    $periodicite   = (string) post('periodicite', 'annuel');
    $jourEcheance  = (int) post('jour_echeance', 10);

    if (isBlank($libelle)) jsonResponse(false, 'Le libellé est obligatoire.', [], 422);
    if (!is_finite($montant) || $montant <= 0) jsonResponse(false, 'Le montant doit être supérieur à 0.', [], 422);

    if ($anneeId <= 0) {
        $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
    }
    if ($categorie === '' || !in_array($devise, ['USD', 'CDF'], true)
        || !in_array($periodicite, ['unique', 'annuel', 'trimestriel', 'mensuel'], true)
        || $jourEcheance < 1 || $jourEcheance > 31) {
        jsonResponse(false, 'Vérifiez la catégorie, la devise et l’échéancier du frais.', [], 422);
    }
    if (FinanceService::estFraisScolarite($categorie)) {
        $devise = 'USD';
    }

    $existe = $this->db->fetchColumn(
        "SELECT COUNT(*) FROM frais_scolaires
         WHERE libelle = :libelle AND annee_scolaire_id = :aid
           AND COALESCE(classe_id, 0) = :classe",
        ['libelle' => trim($libelle), 'aid' => $anneeId, 'classe' => $classeId]
    );
    if ($existe > 0) jsonResponse(false, 'Ce frais existe déjà pour cette année et cette classe.', [], 409);

    try {
        $this->db->beginTransaction();
        $fraisId = $this->db->insert(
            "INSERT INTO frais_scolaires
                (libelle, description, montant, classe_id, annee_scolaire_id, est_obligatoire, est_actif)
             VALUES (:libelle, :desc, :montant, :classe, :annee, :obligatoire, 1)",
            [
                'libelle' => formatNom($libelle),
                'desc' => $description ?: null,
                'montant' => $montant,
                'classe' => $classeId > 0 ? $classeId : null,
                'annee' => $anneeId,
                'obligatoire' => $estObligatoire,
            ]
        );
        $this->db->insert(
            "INSERT INTO finance_frais_config (frais_scolaire_id, categorie, devise, periodicite, jour_echeance)
             VALUES (:id, :categorie, :devise, :periodicite, :jour)",
            [
                'id' => $fraisId,
                'categorie' => $categorie,
                'devise' => $devise,
                'periodicite' => $periodicite,
                'jour' => $jourEcheance,
            ]
        );
        $this->db->commit();

        logActivity('Création', 'frais_scolaires', $fraisId, "Frais créé : {$libelle}");
        jsonResponse(true, 'Frais créé avec succès.', ['id' => $fraisId]);

    } catch (Throwable $e) {
        $this->db->rollback();
        error_log('Erreur création frais: ' . $e->getMessage());
        jsonResponse(false, 'Erreur lors de la création du frais.');
    }
}

/**
 * Modifier un frais scolaire existant
 */
public function modifierFrais(): void
{
    requirePermission('paiements.modifier');

    // Récupérer les données directement depuis $_POST
    $fraisId        = (int) ($_POST['frais_id'] ?? 0);
    $libelle        = trim($_POST['libelle'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $montant        = (float) ($_POST['montant'] ?? 0);
    $estObligatoire = isset($_POST['est_obligatoire']) ? 1 : 0;
    $classeId       = (int) ($_POST['classe_id'] ?? 0);
    $categorie      = trim((string) ($_POST['categorie'] ?? 'Scolarité'));
    $devise         = (string) ($_POST['devise'] ?? 'USD');
    $periodicite    = (string) ($_POST['periodicite'] ?? 'annuel');
    $jourEcheance   = (int) ($_POST['jour_echeance'] ?? 10);

    if ($fraisId <= 0) jsonResponse(false, 'ID frais invalide.', [], 400);
    if (empty($libelle)) jsonResponse(false, 'Le libellé est obligatoire.', [], 422);
    if (!is_finite($montant) || $montant <= 0) jsonResponse(false, 'Le montant doit être supérieur à 0.', [], 422);
    if ($categorie === '' || !in_array($devise, ['USD', 'CDF'], true)
        || !in_array($periodicite, ['unique', 'annuel', 'trimestriel', 'mensuel'], true)
        || $jourEcheance < 1 || $jourEcheance > 31) {
        jsonResponse(false, 'Vérifiez la catégorie, la devise et l’échéancier du frais.', [], 422);
    }
    if (FinanceService::estFraisScolarite($categorie)) {
        $devise = 'USD';
    }

    $frais = $this->db->fetchOne("SELECT id, annee_scolaire_id FROM frais_scolaires WHERE id = :id", ['id' => $fraisId]);
    if (!$frais) jsonResponse(false, 'Frais introuvable.', [], 404);

    $existe = $this->db->fetchColumn(
        "SELECT COUNT(*) FROM frais_scolaires
         WHERE libelle = :libelle AND annee_scolaire_id = :aid AND id != :id
           AND COALESCE(classe_id, 0) = :classe",
        [
            'libelle' => $libelle,
            'aid' => $frais['annee_scolaire_id'],
            'id' => $fraisId,
            'classe' => $classeId,
        ]
    );
    if ($existe > 0) jsonResponse(false, 'Ce libellé existe déjà.', [], 409);

    try {
        $this->db->beginTransaction();
        $this->db->update(
            "UPDATE frais_scolaires 
             SET libelle = :libelle, description = :desc, montant = :montant, 
                 classe_id = :classe, est_obligatoire = :obligatoire
             WHERE id = :id",
            [
                'libelle'     => $libelle,
                'desc'        => $description ?: null,
                'montant'     => $montant,
                'classe'      => $classeId > 0 ? $classeId : null,
                'obligatoire' => $estObligatoire,
                'id'          => $fraisId
            ]
        );
        $this->db->update(
            "UPDATE finance_frais_config
             SET categorie = :categorie, devise = :devise, periodicite = :periodicite, jour_echeance = :jour
             WHERE frais_scolaire_id = :id",
            [
                'categorie' => $categorie,
                'devise' => $devise,
                'periodicite' => $periodicite,
                'jour' => $jourEcheance,
                'id' => $fraisId,
            ]
        );
        $this->db->commit();

        logActivity('Modification', 'frais_scolaires', $fraisId, "Frais modifié : {$libelle}");
        jsonResponse(true, 'Frais modifié. Les créances déjà générées conservent leur montant et leur devise d’origine.');

    } catch (Throwable $e) {
        $this->db->rollback();
        error_log('Erreur modification frais: ' . $e->getMessage());
        jsonResponse(false, 'Erreur lors de la modification du frais.');
    }
}

/**
 * Supprimer un frais scolaire
 */
public function supprimerFrais(): void
{
    requirePermission('paiements.modifier');

    $fraisId = (int) post('frais_id', 0);
    if ($fraisId <= 0) jsonResponse(false, 'ID frais invalide.', [], 400);

    // Vérifier si des paiements sont liés
    $nbPaiements = (int) $this->db->fetchColumn(
        "SELECT COUNT(*) FROM paiements WHERE frais_scolaire_id = :fid",
        ['fid' => $fraisId]
    );
    $nbCreances = (int) $this->db->fetchColumn(
        "SELECT COUNT(*) FROM finance_creances WHERE frais_scolaire_id = :fid",
        ['fid' => $fraisId]
    );

    if ($nbPaiements > 0 || $nbCreances > 0) {
        jsonResponse(false, 'Ce frais est lié à des paiements ou créances; désactivez-le pour conserver l’historique.', [], 409);
    }

    $this->db->delete("DELETE FROM frais_scolaires WHERE id = :id", ['id' => $fraisId]);
    logActivity('Suppression', 'frais_scolaires', $fraisId, 'Frais supprimé');
    jsonResponse(true, 'Frais supprimé avec succès.');
}

/**
 * Activer/Désactiver un frais
 */
public function toggleStatutFrais(): void
{
    requirePermission('paiements.modifier');

    $fraisId = (int) post('frais_id', 0);
    if ($fraisId <= 0) jsonResponse(false, 'ID frais invalide.', [], 400);

    $frais = $this->db->fetchOne("SELECT id, est_actif, libelle FROM frais_scolaires WHERE id = :id", ['id' => $fraisId]);
    if (!$frais) jsonResponse(false, 'Frais introuvable.', [], 404);

    $nouveauStatut = $frais['est_actif'] ? 0 : 1;

    $this->db->update(
        "UPDATE frais_scolaires SET est_actif = :statut WHERE id = :id",
        ['statut' => $nouveauStatut, 'id' => $fraisId]
    );

    $action = $nouveauStatut ? 'activé' : 'désactivé';
    logActivity('Modification', 'frais_scolaires', $fraisId, "Frais {$action} : {$frais['libelle']}");
    jsonResponse(true, "Frais {$action} avec succès.", ['est_actif' => $nouveauStatut]);
}

    // ============================================================
    // 1. LISTE DES PAIEMENTS
    // ============================================================

    public function getListePaiements(): void
    {
        $page      = max(1, (int) post('page', 1));
        $classeId  = (int) post('classe_id', 0);
        $statut    = post('statut', '');
        $dateDebut = post('date_debut', '');
        $dateFin   = post('date_fin', '');
        $recherche = post('recherche', '');
        $eleveId   = (int) post('eleve_id', 0);
        $anneeId   = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }
        $parPage   = ITEMS_PER_PAGE;

        $where = ['cr.annee_scolaire_id = :annee'];
        $params = ['annee' => $anneeId];

        if ($eleveId > 0) {
            $where[] = 'v.eleve_id = :eleve';
            $params['eleve'] = $eleveId;
        }

        if ($classeId > 0) {
            $where[] = "i.classe_id = :classe";
            $params['classe'] = $classeId;
        }

        if ($statut === 'Annulé') {
            $where[] = 'an.id IS NOT NULL';
        } elseif ($statut === 'Enregistré') {
            $where[] = 'an.id IS NULL';
        }

        if (!empty($dateDebut)) {
            $where[] = "v.date_versement >= :debut";
            $params['debut'] = $dateDebut;
        }

        if (!empty($dateFin)) {
            $where[] = "v.date_versement < DATE_ADD(:fin, INTERVAL 1 DAY)";
            $params['fin'] = $dateFin;
        }

        if (!empty($recherche)) {
            $where[] = "(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3 OR v.numero_recu LIKE :r4)";
            $params['r1'] = "%{$recherche}%";
            $params['r2'] = "%{$recherche}%";
            $params['r3'] = "%{$recherche}%";
            $params['r4'] = "%{$recherche}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $joins = "FROM finance_versements v
             INNER JOIN finance_affectations a ON a.versement_id = v.id
             INNER JOIN finance_creances cr ON cr.id = a.creance_id
             INNER JOIN eleves e ON v.eleve_id = e.id
             INNER JOIN inscriptions i ON i.eleve_id = e.id AND i.annee_scolaire_id = cr.annee_scolaire_id
             INNER JOIN classes c ON c.id = i.classe_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             LEFT JOIN utilisateurs u ON u.id = v.enregistre_par";

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT v.id) {$joins} {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $parPage;

        $paiements = $this->db->fetchAll(
            "SELECT v.id, v.numero_recu, v.montant_recu, v.devise_recue, v.taux_cdf_par_usd,
                    v.mode_paiement, v.reference_paiement, v.date_versement, v.commentaire,
                    e.id AS eleve_id, e.matricule, e.nom, e.prenom, c.nom AS classe_nom,
                    cr.id AS creance_id, cr.periode_code, cr.libelle_snapshot AS frais_libelle,
                    cr.montant_du AS montant_total, cr.devise AS devise_creance,
                    a.montant_affecte AS montant_affecte,
                    DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
                    CASE WHEN an.id IS NULL THEN 'Enregistré' ELSE 'Annulé' END AS statut,
                    an.numero_contre_ecriture, an.motif AS motif_annulation,
                    CONCAT(u.prenom, ' ', u.nom) AS enregistre_par_nom
             {$joins}
             {$whereClause}
             ORDER BY v.date_versement DESC, v.id DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $parPage, 'offset' => $offset])
        );

        jsonResponse(true, 'Liste récupérée.', [
            'paiements' => $paiements,
            'total'     => $total,
            'page'      => $page,
            'pages'     => max(1, (int) ceil($total / $parPage))
        ]);
    }

    public function getRegistrePaiements(): void
    {
        $page = max(1, (int) post('page', 1));
        $classeId = (int) post('classe_id', 0);
        $statut = (string) post('statut', '');
        $dateDebut = (string) post('date_debut', '');
        $dateFin = (string) post('date_fin', '');
        $recherche = trim((string) post('recherche', ''));
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }

        $where = ['va.annee_scolaire_id = :annee'];
        $params = ['annee' => $anneeId];
        if ($classeId > 0) {
            $where[] = 'i.classe_id = :classe';
            $params['classe'] = $classeId;
        }
        if ($statut === 'Annulé' || $statut === 'Remboursé') {
            $where[] = 'an.type = :statut_correction';
            $params['statut_correction'] = $statut === 'Annulé' ? 'annulation' : 'remboursement';
        } elseif ($statut === 'Enregistré') {
            $where[] = 'an.id IS NULL';
        }
        if ($dateDebut !== '') {
            $where[] = 'v.date_versement >= :debut';
            $params['debut'] = $dateDebut;
        }
        if ($dateFin !== '') {
            $where[] = 'v.date_versement < DATE_ADD(:fin, INTERVAL 1 DAY)';
            $params['fin'] = $dateFin;
        }
        if ($recherche !== '') {
            $where[] = '(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3 OR v.numero_recu LIKE :r4)';
            $term = '%' . $recherche . '%';
            $params['r1'] = $term;
            $params['r2'] = $term;
            $params['r3'] = $term;
            $params['r4'] = $term;
        }

        $joins = "FROM finance_versements v
             INNER JOIN (
                 SELECT a.versement_id, MIN(cr.annee_scolaire_id) AS annee_scolaire_id
                 FROM finance_affectations a
                 INNER JOIN finance_creances cr ON cr.id = a.creance_id
                 GROUP BY a.versement_id
             ) va ON va.versement_id = v.id
             INNER JOIN eleves e ON e.id = v.eleve_id
             INNER JOIN inscriptions i ON i.eleve_id = e.id AND i.annee_scolaire_id = va.annee_scolaire_id
             INNER JOIN classes c ON c.id = i.classe_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id";
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT e.id) {$joins} {$whereClause}",
            $params
        );
        $parPage = max(1, (int) ITEMS_PER_PAGE);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $parPage;

        $eleves = $this->db->fetchAll(
            "SELECT e.id AS eleve_id, e.matricule, e.nom, e.prenom, c.nom AS classe_nom,
                    COUNT(DISTINCT v.id) AS nombre_versements,
                    SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END) AS recu_usd,
                    SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END) AS recu_cdf,
                    MAX(v.date_versement) AS dernier_versement,
                    DATE_FORMAT(MAX(v.date_versement), '%d/%m/%Y') AS derniere_date_format
             {$joins}
             {$whereClause}
             GROUP BY e.id, e.matricule, e.nom, e.prenom, c.nom
             ORDER BY MAX(v.date_versement) DESC, e.nom, e.prenom
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $parPage, 'offset' => $offset])
        );

        jsonResponse(true, 'Registre récupéré.', [
            'eleves' => $eleves,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function getCreancesEleve(): void
    {
        $eleveId = (int) post('eleve_id', 0);
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }
        if ($eleveId <= 0 || $anneeId <= 0) {
            jsonResponse(false, 'Élève ou année scolaire invalide.', [], 422);
        }

        $creances = $this->finance->getCreancesEleve($eleveId, $anneeId);
        jsonResponse(true, 'Créances récupérées.', ['creances' => $creances]);
    }

    public function genererCreancesAnnee(): void
    {
        requirePermission('paiements.modifier');
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }
        try {
            $nombre = $this->finance->genererCreancesAnnee(
                $anneeId,
                (int) ($_SESSION['utilisateur_id'] ?? 0)
            );
            logActivity('Génération', 'finance_creances', $anneeId, "{$nombre} créance(s) créée(s).");
            jsonResponse(true, "{$nombre} créance(s) nouvelle(s) générée(s). Les créances existantes n’ont pas été modifiées.", [
                'creances_creees' => $nombre,
            ]);
        } catch (DomainException $e) {
            jsonResponse(false, $e->getMessage(), [], 404);
        } catch (Throwable $e) {
            error_log('Erreur génération des créances: ' . $e->getMessage());
            jsonResponse(false, 'Impossible de générer les créances.');
        }
    }

    // ============================================================
    // 2. ENREGISTRER UN PAIEMENT
    // ============================================================

    public function enregistrerPaiement(): void
    {
        requirePermission('paiements.enregistrer');

        $eleveId = (int) post('eleve_id', 0);
        $creanceId = (int) post('creance_id', 0);
        $montant = (float) post('montant_recu', 0);
        $devise = (string) post('devise_recue', '');
        $taux = (float) post('taux_cdf_par_usd', 0);
        $mode = (string) post('mode_paiement', 'Espèces');
        $reference = trim((string) post('reference_paiement', ''));
        $commentaire = trim((string) post('commentaire', ''));
        $date = (string) post('date_versement', date('Y-m-d'));

        if ($eleveId <= 0 || $creanceId <= 0 || $montant <= 0) {
            jsonResponse(false, 'Sélectionnez un élève, une créance et un montant valide.', [], 422);
        }
        if (!in_array($devise, ['USD', 'CDF'], true)) {
            jsonResponse(false, 'Choisissez une devise valide.', [], 422);
        }

        try {
            $versement = $this->finance->enregistrerVersement(
                $creanceId,
                $eleveId,
                $montant,
                $devise,
                $taux,
                $mode,
                $reference !== '' ? $reference : null,
                $commentaire !== '' ? $commentaire : null,
                $date,
                (int) ($_SESSION['utilisateur_id'] ?? 0)
            );
            logActivity(
                'Versement',
                'finance_versements',
                $versement['id'],
                "{$versement['montant_recu']} {$versement['devise_recue']} — reçu {$versement['numero_recu']}"
            );
            jsonResponse(true, 'Versement enregistré. Un reçu distinct a été créé.', $versement);
        } catch (DomainException | InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Erreur enregistrement versement: ' . $e->getMessage());
            jsonResponse(false, 'Impossible d’enregistrer ce versement.');
        }
    }

    // ============================================================
    // 3. LISTE DES IMPAYÉS
    // ============================================================

    public function getImpayes(): void
    {
        $classeId = (int) post('classe_id', 0);
        $recherche = trim((string) post('recherche', ''));
        $echeance = (string) post('echeance', '');
        $page = max(1, (int) post('page', 1));
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }

        $where = ['cr.annee_scolaire_id = :annee', 'e.est_actif = 1'];
        $params = ['annee' => $anneeId];

        if ($classeId > 0) {
            $where[] = "i.classe_id = :classe";
            $params['classe'] = $classeId;
        }

        if (!empty($recherche)) {
            $where[] = "(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)";
            $params['r1'] = "%{$recherche}%";
            $params['r2'] = "%{$recherche}%";
            $params['r3'] = "%{$recherche}%";
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $having = 'HAVING SUM(GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)) > 0';
        if ($echeance === 'retard') {
            $having .= ' AND SUM(CASE WHEN cr.date_echeance < CURDATE()
                THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END) > 0';
        }

        $joins = "FROM finance_creances cr
             INNER JOIN inscriptions i ON i.eleve_id = cr.eleve_id
                 AND i.annee_scolaire_id = cr.annee_scolaire_id
             INNER JOIN eleves e ON e.id = cr.eleve_id
             INNER JOIN classes c ON c.id = i.classe_id
             LEFT JOIN (
                 SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
                 FROM finance_affectations a
                 INNER JOIN finance_versements v ON v.id = a.versement_id
                 LEFT JOIN finance_annulations an ON an.versement_id = v.id
                 WHERE an.id IS NULL
                 GROUP BY a.creance_id
             ) p ON p.creance_id = cr.id";
        $baseQuery = "{$joins} {$whereClause}
             GROUP BY e.id, e.matricule, e.nom, e.prenom, e.sexe, c.nom, c.id";
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM (
                 SELECT e.id {$baseQuery} {$having}
             ) AS eleves_avec_solde",
            $params
        );
        $parPage = max(1, (int) ITEMS_PER_PAGE);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $parPage;
        $impayes = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom, c.id AS classe_id,
                    SUM(CASE WHEN cr.devise = 'USD'
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END) AS solde_usd,
                    SUM(CASE WHEN cr.devise = 'CDF'
                        THEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) ELSE 0 END) AS solde_cdf,
                    SUM(CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN 1 ELSE 0 END) AS nombre_creances,
                    SUM(CASE WHEN cr.date_echeance < CURDATE()
                        AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0 THEN 1 ELSE 0 END) AS nombre_en_retard,
                    MIN(CASE WHEN cr.date_echeance < CURDATE()
                        AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                        THEN cr.date_echeance END) AS plus_ancienne_echeance,
                    (SELECT MIN(s.date_relance) FROM finance_suivis s
                     WHERE s.eleve_id = e.id AND s.annee_scolaire_id = :annee_relance
                       AND s.statut = 'à_faire' AND s.date_relance >= CURDATE()) AS prochaine_relance
             {$baseQuery}
             {$having}
             ORDER BY SUM(CASE WHEN cr.date_echeance < CURDATE()
                          AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                          THEN 1 ELSE 0 END) DESC,
                      MIN(CASE WHEN cr.date_echeance < CURDATE()
                          AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                          THEN cr.date_echeance END) IS NULL,
                      MIN(CASE WHEN cr.date_echeance < CURDATE()
                          AND GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
                          THEN cr.date_echeance END), e.nom, e.prenom
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['annee_relance' => $anneeId, 'limit' => $parPage, 'offset' => $offset])
        );

        jsonResponse(true, 'Impayés récupérés.', [
            'impayes' => $impayes,
            'total'   => $total,
            'page' => $page,
            'pages' => $pages,
            'annee_id' => $anneeId,
        ]);
    }

    // ============================================================
    // 4. RÉCUPÉRER UN REÇU
    // ============================================================

    public function getRecu(): void
    {
        $versementId = (int) post('versement_id', 0);
        $factureId = (int) post('facture_id', 0);
        $paiementId = (int) post('paiement_id', 0);

        if ($versementId > 0) {
            $recu = $this->db->fetchOne(
                "SELECT v.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom, e.sexe,
                        c.nom AS classe_nom, c.niveau AS classe_niveau,
                        DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
                        TIME_FORMAT(v.date_versement, '%H:%i') AS heure_format,
                        cr.libelle_snapshot AS frais_libelle, cr.periode_code,
                        cr.montant_du AS montant_total, cr.devise AS devise_creance,
                        a.montant_affecte,
                        CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom,
                        an.type AS type_correction, an.motif AS motif_correction,
                        an.numero_contre_ecriture
                 FROM finance_versements v
                 INNER JOIN finance_affectations a ON a.versement_id = v.id
                 INNER JOIN finance_creances cr ON cr.id = a.creance_id
                 INNER JOIN eleves e ON e.id = v.eleve_id
                 INNER JOIN inscriptions i ON i.eleve_id = e.id
                     AND i.annee_scolaire_id = cr.annee_scolaire_id
                 INNER JOIN classes c ON c.id = i.classe_id
                 LEFT JOIN utilisateurs u ON u.id = v.enregistre_par
                 LEFT JOIN finance_annulations an ON an.versement_id = v.id
                 WHERE v.id = :id",
                ['id' => $versementId]
            );
            $this->requireReceiptStudentAccess($recu);
            jsonResponse($recu !== null, $recu ? 'Reçu récupéré.' : 'Reçu introuvable.', ['recu' => $recu], $recu ? 200 : 404);
        } elseif ($factureId > 0) {
            $facture = $this->db->fetchOne(
                "SELECT f.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom,
                        c.nom AS classe_nom,
                        DATE_FORMAT(f.date_emission, '%d/%m/%Y') AS date_format,
                        fl.libelle AS frais_libelle,
                        p.mode_paiement, p.montant_total, p.montant_paye,
                        CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom
                 FROM factures f
                 INNER JOIN eleves e ON f.eleve_id = e.id
                 INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
                 INNER JOIN classes c ON i.classe_id = c.id
                 INNER JOIN paiements p ON f.paiement_id = p.id
                 INNER JOIN frais_scolaires fl ON p.frais_scolaire_id = fl.id
                 LEFT JOIN utilisateurs u ON f.emise_par = u.id
                 WHERE f.id = :id",
                ['id' => $factureId]
            );
            $this->requireReceiptStudentAccess($facture);
            jsonResponse($facture !== null, $facture ? 'Reçu récupéré.' : 'Reçu introuvable.', ['recu' => $facture], $facture ? 200 : 404);
        } elseif ($paiementId > 0) {
            $facture = $this->db->fetchOne(
                "SELECT f.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom,
                        c.nom AS classe_nom,
                        DATE_FORMAT(f.date_emission, '%d/%m/%Y') AS date_format,
                        fl.libelle AS frais_libelle,
                        p.mode_paiement, p.montant_total, p.montant_paye,
                        CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom
                 FROM factures f
                 INNER JOIN eleves e ON f.eleve_id = e.id
                 INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
                 INNER JOIN classes c ON i.classe_id = c.id
                 INNER JOIN paiements p ON f.paiement_id = p.id
                 INNER JOIN frais_scolaires fl ON p.frais_scolaire_id = fl.id
                 LEFT JOIN utilisateurs u ON f.emise_par = u.id
                 WHERE f.paiement_id = :pid
                 ORDER BY f.date_emission DESC LIMIT 1",
                ['pid' => $paiementId]
            );
            $this->requireReceiptStudentAccess($facture);
            jsonResponse($facture !== null, $facture ? 'Reçu récupéré.' : 'Reçu introuvable.', ['recu' => $facture], $facture ? 200 : 404);
        } else {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }
    }

    private function requireReceiptStudentAccess(?array $receipt): void
    {
        if (
            !$receipt
            || empty($receipt['eleve_id'])
            || !(new EleveController())->canCurrentUserAccessStudent((int) $receipt['eleve_id'])
        ) {
            jsonResponse(false, 'Reçu introuvable.', [], 404);
        }
    }

    // ============================================================
    // 5. STATISTIQUES DES PAIEMENTS
    // ============================================================

    public function getStatsPaiements(): void
    {
        $totalAttendu = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(f.montant), 0)
             FROM frais_scolaires f
             WHERE f.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             AND f.est_obligatoire = 1 AND f.est_actif = 1"
        ) * (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1) AND statut = 'Actif'"
        );

        $totalEncaissement = (float) $this->db->fetchColumn("SELECT COALESCE(SUM(montant_paye), 0) FROM paiements");
        $totalImpayes = $totalAttendu - $totalEncaissement;
        $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaissement / $totalAttendu) * 100, 1) : 0;

        $nbPayes = (int) $this->db->fetchColumn("SELECT COUNT(DISTINCT eleve_id) FROM paiements WHERE statut = 'Payé'");
        $nbPartiels = (int) $this->db->fetchColumn("SELECT COUNT(DISTINCT eleve_id) FROM paiements WHERE statut = 'Partiel'");
        $nbImpayes = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT i.eleve_id)
             FROM inscriptions i
             WHERE i.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             AND i.statut = 'Actif'
             AND i.eleve_id NOT IN (SELECT DISTINCT eleve_id FROM paiements WHERE statut = 'Payé')"
        );

        jsonResponse(true, 'Stats récupérées.', [
            'total_attendu'      => $totalAttendu,
            'total_encaissement' => $totalEncaissement,
            'total_impayes'      => $totalImpayes,
            'taux_recouvrement'  => $tauxRecouvrement,
            'nb_payes'           => $nbPayes,
            'nb_partiels'        => $nbPartiels,
            'nb_impayes'         => $nbImpayes
        ]);
    }

    // ============================================================
    // 6. RECHERCHER UN ÉLÈVE
    // ============================================================

    public function rechercherEleve(): void
    {
        $recherche = post('recherche', '');
        $anneeId = (int) post('annee_id', 0);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }

        if (trim((string) $recherche) === '') {
            jsonResponse(false, 'Veuillez entrer un nom, prénom ou matricule.', [], 400);
        }

        $eleves = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom
             FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.annee_scolaire_id = :annee
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE e.est_actif = 1
             AND (e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3)
             ORDER BY e.nom, e.prenom
             LIMIT 10",
            [
                'annee' => $anneeId,
                'r1' => "%{$recherche}%",
                'r2' => "%{$recherche}%",
                'r3' => "%{$recherche}%",
            ]
        );

        jsonResponse(true, 'Élèves trouvés.', ['eleves' => $eleves]);
    }

    // ============================================================
    // 7. ANNULER UN PAIEMENT
    // ============================================================

    public function annulerPaiement(): void
    {
        requirePermission('paiements.supprimer');

        $versementId = (int) post('versement_id', 0);
        $type = (string) post('type_correction', 'annulation');
        $motif = trim((string) post('motif', ''));
        if ($versementId <= 0 || $motif === '') {
            jsonResponse(false, 'Versement et motif obligatoires.', [], 422);
        }

        try {
            $numero = $this->finance->annulerVersement(
                $versementId,
                $type,
                $motif,
                (int) ($_SESSION['utilisateur_id'] ?? 0)
            );
            logActivity('Contre-écriture', 'finance_versements', $versementId, "{$numero} — {$motif}");
            jsonResponse(true, 'Contre-écriture enregistrée; le reçu d’origine est conservé.', [
                'numero_contre_ecriture' => $numero,
            ]);
        } catch (DomainException | InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log('Erreur contre-écriture financière: ' . $e->getMessage());
            jsonResponse(false, 'Impossible d’enregistrer la contre-écriture.');
        }
    }

    public function enregistrerSuiviFinancier(): void
    {
        requirePermission('paiements.modifier');
        $eleveId = (int) post('eleve_id', 0);
        $anneeId = (int) post('annee_id', 0);
        $type = (string) post('type_suivi', '');
        $motif = trim((string) post('motif', ''));
        $contact = trim((string) post('contact_parent', ''));
        $dateRelance = trim((string) post('date_relance', ''));
        $suite = trim((string) post('suite_donnee', ''));
        $statut = (string) post('statut', 'effectué');

        if ($eleveId <= 0 || $anneeId <= 0 || $motif === '') {
            jsonResponse(false, 'Élève, année et motif sont obligatoires.', [], 422);
        }
        if (!in_array($type, ['appel', 'sms', 'rendez-vous', 'renvoi', 'autre'], true)
            || !in_array($statut, ['à_faire', 'effectué', 'sans_suite'], true)) {
            jsonResponse(false, 'Type ou état de suivi invalide.', [], 422);
        }
        if ($dateRelance !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateRelance);
            if (!$date || $date->format('Y-m-d') !== $dateRelance) {
                jsonResponse(false, 'La date de relance est invalide.', [], 422);
            }
        }

        $inscrit = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions
             WHERE eleve_id = :eleve AND annee_scolaire_id = :annee",
            ['eleve' => $eleveId, 'annee' => $anneeId]
        );
        if (!(int) $inscrit) {
            jsonResponse(false, 'Cet élève n’est pas inscrit pour cette année scolaire.', [], 404);
        }

        $id = $this->db->insert(
            "INSERT INTO finance_suivis
                (eleve_id, annee_scolaire_id, type_suivi, contact_parent, date_relance,
                 motif, suite_donnee, statut, saisi_par)
             VALUES (:eleve, :annee, :type, :contact, :relance, :motif, :suite, :statut, :user)",
            [
                'eleve' => $eleveId,
                'annee' => $anneeId,
                'type' => $type,
                'contact' => $contact !== '' ? $contact : null,
                'relance' => $dateRelance !== '' ? $dateRelance : null,
                'motif' => $motif,
                'suite' => $suite !== '' ? $suite : null,
                'statut' => $statut,
                'user' => (int) ($_SESSION['utilisateur_id'] ?? 0),
            ]
        );
        logActivity('Suivi financier', 'finance_suivis', $id, "{$type} — {$motif}");
        jsonResponse(true, 'Suivi financier enregistré.', ['id' => $id]);
    }

    // ============================================================
    // 8. MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    public function getClassesActives(?int $anneeId = null): array
    {
        if ($anneeId === null || $anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn(
                "SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1"
            );
        }
        return $this->db->fetchAll(
            "SELECT c.* FROM classes c
             WHERE c.annee_scolaire_id = :annee
             ORDER BY c.niveau",
            ['annee' => $anneeId]
        );
    }

    public function getFraisActifs(): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             WHERE f.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             AND f.est_actif = 1
             ORDER BY f.est_obligatoire DESC, f.libelle"
        );
    }

    public function getStatsDashboard(): array
    {
        $totalEncaissement = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(montant_paye), 0) FROM paiements"
        );
        $paiementsMois = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(montant_paye), 0) FROM paiements WHERE MONTH(date_paiement) = MONTH(CURDATE()) AND YEAR(date_paiement) = YEAR(CURDATE())"
        );
        $nbImpayes = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT i.eleve_id)
             FROM inscriptions i
             WHERE i.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             AND i.statut = 'Actif'
             AND i.eleve_id NOT IN (SELECT DISTINCT eleve_id FROM paiements WHERE statut = 'Payé')"
        );

        return [
            'total_encaissement' => $totalEncaissement,
            'paiements_mois'     => $paiementsMois,
            'nb_impayes'         => $nbImpayes
        ];
    }
}