<?php
/**
 * Fichier : src/controllers/InscriptionController.php
 * Rôle    : Gestion complète des inscriptions (CRUD + réinscription massive)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/AcademicResultsService.php';
require_once __DIR__ . '/../services/InscriptionService.php';

class InscriptionController
{
    private Database $db;
    private AcademicResultsService $academicResults;
    private InscriptionService $inscriptionService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->academicResults = new AcademicResultsService();
        $this->inscriptionService = new InscriptionService();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('inscriptions.consulter')) {
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
            case 'getInscription':
                $this->getInscription();
                break;
            case 'inscrire':
                $this->inscrire();
                break;
            case 'changerClasse':
                $this->changerClasse();
                break;
            case 'desinscrire':
                $this->desinscrire();
                break;
            case 'getElevesPourReinscription':
                $this->getElevesPourReinscription();
                break;
            case 'reinscrireMassivement':
                $this->reinscrireMassivement();
                break;
            case 'getHistorique':
                $this->getHistorique();
                break;
            case 'getClassesDisponibles':
                $this->getClassesDisponibles();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // LISTE DES INSCRIPTIONS
    // ============================================================

    private function getListe(): void
    {
$page = max(1, (int) post('page', 1));
        $filters = [
            'recherche' => post('recherche', ''),
            'classe_id' => (int) post('classe_id', 0),
            'annee_id' => (int) post('annee_id', 0),
            'statut' => post('statut', ''),
        ];
        $result = $this->inscriptionService->paginate($filters, $page, ITEMS_PER_PAGE);
        jsonResponse(true, 'Liste récupérée.', [
            'inscriptions' => $result['inscriptions'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => ceil($result['total'] / ITEMS_PER_PAGE)
        ]);
    

    // ============================================================
    // INSCRIPTION INDIVIDUELLE
    // ============================================================

    private function inscrire(): void
    {
        requirePermission('inscriptions.creer');

        $eleveId  = (int) post('eleve_id', 0);
        $classeId = (int) post('classe_id', 0);
        $statut   = post('statut', 'Actif');

        $errors = [];

        if ($eleveId <= 0) $errors['eleve_id'] = 'Veuillez sélectionner un élève.';
        if ($classeId <= 0) $errors['classe_id'] = 'Veuillez sélectionner une classe.';

        // Vérifier que l'élève existe
        $eleve = $this->db->fetchOne("SELECT id, nom, prenom FROM eleves WHERE id = :id AND est_actif = 1", ['id' => $eleveId]);
        if (!$eleve) $errors['eleve_id'] = 'Élève introuvable ou inactif.';

        // Vérifier la capacité de la classe
        if ($classeId > 0) {
            $capacite = (int) $this->db->fetchColumn("SELECT capacite_max FROM classes WHERE id = :id", ['id' => $classeId]);
            $nbInscrits = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
                ['cid' => $classeId]
            );
            if ($nbInscrits >= $capacite) {
                $errors['classe_id'] = "Classe complète ({$capacite} élèves maximum).";
            }
        }

        // Vérifier que l'élève n'est pas déjà inscrit dans une classe active cette année
        $anneeActive = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        $dejaInscrit = $this->db->fetchOne(
            "SELECT i.id, c.nom FROM inscriptions i
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE i.eleve_id = :eid AND i.annee_scolaire_id = :aid AND i.statut = 'Actif'",
            ['eid' => $eleveId, 'aid' => $anneeActive]
        );
        if ($dejaInscrit) {
            $errors['eleve_id'] = "Cet élève est déjà inscrit en {$dejaInscrit['nom']} pour cette année.";
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $inscriptionId = $this->db->insert(
                "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                 VALUES (:eid, :cid, :aid, CURDATE(), :statut)",
                ['eid' => $eleveId, 'cid' => $classeId, 'aid' => $anneeActive, 'statut' => $statut]
            );

            logActivity('Création', 'inscriptions', $inscriptionId,
                "Inscription : {$eleve['nom']} {$eleve['prenom']}");

            jsonResponse(true, 'Élève inscrit avec succès.', ['id' => $inscriptionId]);

        } catch (Exception $e) {
            error_log('Erreur inscription: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de l\'inscription.');
        }
    }

    // ============================================================
    // CHANGEMENT DE CLASSE
    // ============================================================

    private function changerClasse(): void
    {
        requirePermission('inscriptions.modifier');

        $eleveId          = (int) post('eleve_id', 0);
        $ancienneClasseId = (int) post('ancienne_classe_id', 0);
        $nouvelleClasseId = (int) post('nouvelle_classe_id', 0);
        $motif            = post('motif', 'Changement de classe');

        if ($eleveId <= 0 || $nouvelleClasseId <= 0) {
            jsonResponse(false, 'Paramètres invalides.', [], 400);
        }

        // Vérifier la capacité
        $capacite = (int) $this->db->fetchColumn("SELECT capacite_max FROM classes WHERE id = :id", ['id' => $nouvelleClasseId]);
        $nbInscrits = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :cid AND statut = 'Actif'",
            ['cid' => $nouvelleClasseId]
        );
        if ($nbInscrits >= $capacite) {
            jsonResponse(false, "Classe complète ({$capacite} élèves max).", [], 409);
        }

        try {
            $this->db->beginTransaction();

            $anneeActive = $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");

            // Terminer l'ancienne inscription
            $this->db->update(
                "UPDATE inscriptions SET statut = 'Transféré', motif_statut = :motif, date_changement_statut = CURDATE()
                 WHERE eleve_id = :eid AND classe_id = :cid AND annee_scolaire_id = :aid AND statut = 'Actif'",
                ['motif' => $motif, 'eid' => $eleveId, 'cid' => $ancienneClasseId, 'aid' => $anneeActive]
            );

            // Créer la nouvelle inscription
            $this->db->insert(
                "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                 VALUES (:eid, :cid, :aid, CURDATE(), 'Actif')",
                ['eid' => $eleveId, 'cid' => $nouvelleClasseId, 'aid' => $anneeActive]
            );

            $this->db->commit();

            logActivity('Modification', 'inscriptions', null, "Changement de classe élève {$eleveId}");

            jsonResponse(true, 'Changement de classe effectué avec succès.');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur changement classe: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du changement de classe.');
        }
    }

    // ============================================================
    // DÉSINSCRIPTION (ABANDON / EXCLUSION)
    // ============================================================

    private function desinscrire(): void
    {
        requirePermission('inscriptions.modifier');

        $inscriptionId = (int) post('inscription_id', 0);
        $statut        = post('statut', 'Abandon');
        $motif         = post('motif', '');

        if ($inscriptionId <= 0) jsonResponse(false, 'ID inscription invalide.', [], 400);
        if (!in_array($statut, ['Abandon', 'Exclu', 'Diplômé'])) {
            jsonResponse(false, 'Statut invalide.', [], 400);
        }
        if (isBlank($motif)) jsonResponse(false, 'Le motif est obligatoire.', [], 422);

        try {
            $this->db->update(
                "UPDATE inscriptions SET statut = :statut, motif_statut = :motif, date_changement_statut = CURDATE()
                 WHERE id = :id",
                ['statut' => $statut, 'motif' => $motif, 'id' => $inscriptionId]
            );

            logActivity('Modification', 'inscriptions', $inscriptionId, "Désinscription : {$statut}");

            jsonResponse(true, 'Statut mis à jour avec succès.');

        } catch (Exception $e) {
            error_log('Erreur désinscription: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la mise à jour.');
        }
    }

    // ============================================================
    // RÉINSCRIPTION MASSIVE
    // ============================================================

    /**
     * Récupère les élèves d'une classe pour la réinscription
     */
    private function getElevesPourReinscription(): void
    {
        $classeSourceId = (int) post('classe_source_id', 0);
        $anneeSourceId  = (int) post('annee_source_id', 0);
        $anneeDestinationId = (int) post('annee_destination_id', 0);

        if ($classeSourceId <= 0 || $anneeSourceId <= 0 || $anneeDestinationId <= 0) {
            jsonResponse(false, 'Classe, année source et année de destination obligatoires.', [], 400);
        }

        $classeSource = $this->db->fetchOne(
            "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id, a.date_debut AS source_date_debut
             FROM classes c
             INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             WHERE c.id = :cid AND c.annee_scolaire_id = :aid",
            ['cid' => $classeSourceId, 'aid' => $anneeSourceId]
        );
        $anneeDestination = $this->db->fetchOne(
            "SELECT id, date_debut
             FROM annees_scolaires
             WHERE id = :aid",
            ['aid' => $anneeDestinationId]
        );
        if (!$classeSource || !$anneeDestination) {
            jsonResponse(false, 'Classe source ou année de destination introuvable.', [], 404);
        }
        if (strtotime($anneeDestination['date_debut']) <= strtotime($classeSource['source_date_debut'])) {
            jsonResponse(false, 'L’année de destination doit être postérieure à l’année source.', [], 422);
        }

        $eleves = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance,
                    i.id AS inscription_id, i.statut
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
               AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['cid' => $classeSourceId, 'aid' => $anneeSourceId]
        );

        $resultatsAnnuels = $this->academicResults->getAnnualResultsForClass(
            $classeSourceId,
            $anneeSourceId
        );
        $resultatsParEleve = [];
        foreach ($resultatsAnnuels['resultats'] as $resultat) {
            $resultatsParEleve[(int) $resultat['eleve_id']] = $resultat;
        }

        $niveauSource = (int) $classeSource['niveau'];
        foreach ($eleves as &$eleve) {
            $resultat = $resultatsParEleve[(int) $eleve['id']] ?? null;
            $eleve['pourcentage_annuel'] = $resultat['pourcentage'] ?? null;
            $eleve['bulletin_complet'] = (bool) ($resultat['bulletin_complet'] ?? false);
            $eleve['notes_completees'] = (int) ($resultat['notes_completees'] ?? 0);
            $eleve['notes_attendues'] = (int) ($resultat['notes_attendues'] ?? 0);

            if (!$eleve['bulletin_complet']) {
                $eleve['decision_auto'] = 'A_COMPLETER';
            } elseif (!empty($resultat['is_admis'])) {
                $eleve['decision_auto'] = $niveauSource >= 6 ? 'Diplômé' : 'Passe';
            } else {
                $eleve['decision_auto'] = 'Redouble';
            }
        }
        unset($eleve);

        $classesDisponibles = $this->db->fetchAll(
            "SELECT c.id, c.nom, c.niveau, c.capacite_max,
                    (SELECT COUNT(*) FROM inscriptions i
                     WHERE i.classe_id = c.id AND i.annee_scolaire_id = c.annee_scolaire_id
                       AND i.statut IN ('Actif', 'Redoublant')) AS nb_inscrits
             FROM classes c
             WHERE c.annee_scolaire_id = :aid AND c.est_actif = 1
             ORDER BY c.niveau, c.nom",
            ['aid' => $anneeDestinationId]
        );

        jsonResponse(true, 'Élèves récupérés.', [
            'eleves'              => $eleves,
            'classe_source'       => $classeSource,
            'classes_disponibles' => $classesDisponibles,
            'total'               => count($eleves)
        ]);
    }

    /**
     * Exécute la réinscription massive
     */
    private function reinscrireMassivement(): void
    {
        requirePermission('inscriptions.creer');

        $elevesData = post('eleves', '');
        $elevesData = json_decode($elevesData, true);
        $classeSourceId = (int) post('classe_source_id', 0);
        $anneeSourceId = (int) post('annee_source_id', 0);
        $anneeDestinationId = (int) post('annee_destination_id', 0);
        if (
            empty($elevesData)
            || !is_array($elevesData)
            || $classeSourceId <= 0
            || $anneeSourceId <= 0
            || $anneeDestinationId <= 0
        ) {
            jsonResponse(false, 'Aucun élève sélectionné.', [], 400);
        }

        $anneeDestination = $this->db->fetchOne(
            "SELECT id, date_debut
             FROM annees_scolaires
             WHERE id = :aid",
            ['aid' => $anneeDestinationId]
        );
        $classeSource = $this->db->fetchOne(
            "SELECT c.id, c.niveau, c.annee_scolaire_id, a.date_debut AS source_date_debut
             FROM classes c
             INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             WHERE c.id = :cid AND c.annee_scolaire_id = :aid",
            ['cid' => $classeSourceId, 'aid' => $anneeSourceId]
        );
        if (!$classeSource || !$anneeDestination) {
            jsonResponse(false, 'Classe source ou année de destination introuvable.', [], 404);
        }
        if (strtotime($anneeDestination['date_debut']) <= strtotime($classeSource['source_date_debut'])) {
            jsonResponse(false, 'L’année de destination doit être postérieure à l’année source.', [], 422);
        }

        $resultatsAnnuels = $this->academicResults->getAnnualResultsForClass(
            $classeSourceId,
            $anneeSourceId
        );
        $resultatsParEleve = [];
        foreach ($resultatsAnnuels['resultats'] as $resultat) {
            $resultatsParEleve[(int) $resultat['eleve_id']] = $resultat;
        }

        $plans = [];
        $elevesDejaSelectionnes = [];
        foreach ($elevesData as $data) {
            if (!is_array($data)) {
                jsonResponse(false, 'La liste des élèves contient des données invalides.', [], 400);
            }

            $eleveId = (int) ($data['eleve_id'] ?? 0);
            $inscriptionSourceId = (int) ($data['ancienne_inscription_id'] ?? 0);
            $classeDestinationId = (int) ($data['nouvelle_classe_id'] ?? 0);
            if ($eleveId <= 0 || $inscriptionSourceId <= 0 || isset($elevesDejaSelectionnes[$eleveId])) {
                jsonResponse(false, 'Un élève est invalide ou présent plusieurs fois dans la sélection.', [], 422);
            }
            $elevesDejaSelectionnes[$eleveId] = true;

            $inscriptionSource = $this->db->fetchOne(
                "SELECT i.id, i.eleve_id
                 FROM inscriptions i
                 INNER JOIN eleves e ON e.id = i.eleve_id AND e.est_actif = 1
                 WHERE i.id = :iid AND i.eleve_id = :eid AND i.classe_id = :cid
                   AND i.annee_scolaire_id = :aid AND i.statut IN ('Actif', 'Redoublant')",
                [
                    'iid' => $inscriptionSourceId,
                    'eid' => $eleveId,
                    'cid' => $classeSourceId,
                    'aid' => $anneeSourceId,
                ]
            );
            if (!$inscriptionSource) {
                jsonResponse(false, "L’inscription source de l’élève {$eleveId} n’est plus valide.", [], 422);
            }

            $resultat = $resultatsParEleve[$eleveId] ?? null;
            if (!$resultat || empty($resultat['bulletin_complet'])) {
                jsonResponse(
                    false,
                    "Réinscription bloquée : le bulletin de l’élève {$eleveId} n’est pas complet à 100 %.",
                    [],
                    422
                );
            }

            $estReussite = !empty($resultat['is_admis']);
            $estDiplome = $estReussite && (int) $classeSource['niveau'] >= 6;
            $niveauDestinationAttendu = $estReussite
                ? (int) $classeSource['niveau'] + 1
                : (int) $classeSource['niveau'];

            if (!$estDiplome) {
                $classeDestination = $this->db->fetchOne(
                    "SELECT id, niveau
                     FROM classes
                     WHERE id = :cid AND annee_scolaire_id = :aid AND est_actif = 1",
                    ['cid' => $classeDestinationId, 'aid' => $anneeDestinationId]
                );
                if (!$classeDestination || (int) $classeDestination['niveau'] !== $niveauDestinationAttendu) {
                    $decision = $estReussite ? 'passe en classe supérieure' : 'redouble dans la même classe';
                    jsonResponse(
                        false,
                        "Destination incorrecte pour l’élève {$eleveId} : il doit {$decision}.",
                        [],
                        422
                    );
                }
            }

            $dejaInscrit = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM inscriptions
                 WHERE eleve_id = :eid AND annee_scolaire_id = :aid",
                ['eid' => $eleveId, 'aid' => $anneeDestinationId]
            );
            if ($dejaInscrit > 0) {
                jsonResponse(false, "L’élève {$eleveId} est déjà inscrit pour l’année de destination.", [], 422);
            }

            $plans[] = [
                'eleve_id' => $eleveId,
                'inscription_source_id' => $inscriptionSourceId,
                'classe_destination_id' => $estDiplome ? null : $classeDestinationId,
                'est_reussite' => $estReussite,
                'est_diplome' => $estDiplome,
            ];
        }

        $this->db->beginTransaction();
        try {
            $capacitesUtilisees = [];
            $classesDestination = [];
            foreach ($plans as $plan) {
                if ($plan['classe_destination_id'] === null) {
                    continue;
                }
                $classesDestination[$plan['classe_destination_id']] = true;
            }

            foreach (array_keys($classesDestination) as $classeDestinationId) {
                $classeDestination = $this->db->fetchOne(
                    "SELECT id, capacite_max
                     FROM classes
                     WHERE id = :cid AND annee_scolaire_id = :aid AND est_actif = 1
                     FOR UPDATE",
                    ['cid' => $classeDestinationId, 'aid' => $anneeDestinationId]
                );
                if (!$classeDestination) {
                    $this->db->rollback();
                    jsonResponse(false, 'Une classe de destination n’est plus disponible.', [], 409);
                }

                $inscritsActuels = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM inscriptions
                     WHERE classe_id = :cid AND annee_scolaire_id = :aid
                       AND statut IN ('Actif', 'Redoublant')",
                    ['cid' => $classeDestinationId, 'aid' => $anneeDestinationId]
                );
                $capacitesUtilisees[$classeDestinationId] = [
                    'capacite' => (int) $classeDestination['capacite_max'],
                    'inscrits' => $inscritsActuels,
                ];
            }

            foreach ($plans as $plan) {
                $destinationId = $plan['classe_destination_id'];
                if ($destinationId !== null) {
                    $capacitesUtilisees[$destinationId]['inscrits']++;
                    if ($capacitesUtilisees[$destinationId]['inscrits'] > $capacitesUtilisees[$destinationId]['capacite']) {
                        $this->db->rollback();
                        jsonResponse(false, "La classe de destination {$destinationId} n’a plus assez de places.", [], 409);
                    }
                }
            }

            $inscrits = 0;
            $redoublants = 0;
            $diplomes = 0;
            foreach ($plans as $plan) {
                if ($plan['est_diplome']) {
                    $updated = $this->db->update(
                        "UPDATE inscriptions
                         SET statut = 'Diplômé', motif_statut = 'Réussite en fin de 6e primaire',
                             date_changement_statut = CURDATE()
                         WHERE id = :iid AND eleve_id = :eid AND statut IN ('Actif', 'Redoublant')",
                        [
                            'iid' => $plan['inscription_source_id'],
                            'eid' => $plan['eleve_id'],
                        ]
                    );
                    if ($updated !== 1) {
                        $this->db->rollback();
                        jsonResponse(false, 'Une inscription de fin de cycle a changé pendant le traitement.', [], 409);
                    }
                    $diplomes++;
                    continue;
                }

                $statut = $plan['est_reussite'] ? 'Actif' : 'Redoublant';
                $this->db->insert(
                    "INSERT INTO inscriptions (eleve_id, classe_id, annee_scolaire_id, date_inscription, statut)
                     VALUES (:eid, :cid, :aid, CURDATE(), :statut)",
                    [
                        'eid' => $plan['eleve_id'],
                        'cid' => $plan['classe_destination_id'],
                        'aid' => $anneeDestinationId,
                        'statut' => $statut,
                    ]
                );
                $inscrits++;
                if (!$plan['est_reussite']) {
                    $redoublants++;
                }
            }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollback();
            error_log('Erreur réinscription massive: ' . $e->getMessage());
            jsonResponse(false, 'La réinscription a échoué et aucune modification n’a été conservée.', [], 500);
        }

        logActivity(
            'Réinscription',
            'inscriptions',
            null,
            "Transition scolaire : {$inscrits} inscrits, {$redoublants} redoublants, {$diplomes} diplômés"
        );

        jsonResponse(true, 'Transition scolaire enregistrée avec succès.', [
            'inscrits' => $inscrits,
            'redoublants' => $redoublants,
            'diplomes' => $diplomes,
        ]);
    }

    // ============================================================
    // HISTORIQUE D'UN ÉLÈVE
    // ============================================================

    private function getHistorique(): void
    {
$eleveId = (int) post('eleve_id', 0);
        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);
        $result = $this->inscriptionService->getStudentHistory($eleveId);
        jsonResponse(true, 'Historique récupéré.', [
            'eleve' => $result['eleve'],
            'historique' => $result['historique']
        ]);
    

    /**
     * Récupère les classes disponibles (avec capacité)
     */
    private function getClassesDisponibles(): void
    {
$anneeId = (int) post('annee_id', 0);
        $classes = $this->inscriptionService->getAvailableClasses($anneeId);
        jsonResponse(true, 'Classes récupérées.', ['classes' => $classes]);
    

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère toutes les années scolaires (pour filtres)
     */
    public function getAnneesScolaires(): array
    {
return $this->inscriptionService->getSchoolYears();
    

    /**
     * Récupère les classes de l'année active
     */
    public function getClassesActives(): array
    {
return $this->inscriptionService->getActiveClasses();
    

    /**
     * Récupère les élèves non inscrits (disponibles pour inscription)
     */
    public function getElevesNonInscrits(): array
    {
return $this->inscriptionService->getUnenrolledStudents();
    

    /**
     * Récupère une inscription par ID
     */
    public function getInscriptionById(int $id): ?array
    {
return $this->inscriptionService->findEnrollment($id);
    

    /**
     * Récupère l'historique complet d'un élève
     */
    public function getHistoriqueEleve(int $eleveId): array
    {
return $this->inscriptionService->getEnrollmentHistory($eleveId);
    
}