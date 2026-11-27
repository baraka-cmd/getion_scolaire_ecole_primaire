<?php
/**
 * Fichier : src/controllers/EvaluationController.php
 * Rôle    : Gestion des évaluations et saisie des notes
 * Système : Bulletin congolais — Groupes → Sous-matières → Notes
 * Version : 2.0 — Refonte (groupe_id au lieu de matiere_id)
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/EleveController.php';
require_once __DIR__ . '/../services/EvaluationNoteService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class NoteController
{
    private Database $db;
    private EvaluationNoteService $evaluationNoteService;
    private StudentAccessPolicy $studentAccessPolicy;

    public function __construct(?EvaluationNoteService $evaluationNoteService = null)
    {
        $this->db = Database::getInstance();
        $this->evaluationNoteService = $evaluationNoteService ?? new EvaluationNoteService();
        $this->studentAccessPolicy = new StudentAccessPolicy($this->db);
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('evaluations.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            // ============================================================
            // ÉVALUATIONS
            // ============================================================
            case 'liste':
                $this->getListe();
                break;
            case 'getEvaluation':
                $this->getEvaluation();
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
            case 'genererEvaluations':
                $this->genererEvaluations();
                break;

            // ============================================================
            // NOTES
            // ============================================================
            case 'getGrilleSaisie':
                $this->getGrilleSaisie();
                break;
            case 'sauvegarderNotes':
                $this->sauvegarderNotes();
                break;
            case 'getNotesEleve':
                $this->getNotesEleve();
                break;

            // ============================================================
            // PROGRESSION
            // ============================================================
            case 'getProgressionTrimestre':
                $this->getProgressionTrimestre();
                break;

            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // LISTE DES ÉVALUATIONS
    // ============================================================

    private function getListe(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);
        $anneeId     = (int) post('annee_id', 0);
        $groupeId    = (int) post('groupe_id', 0);
        $type        = post('type_evaluation', '');

        $where = [];
        $params = [];

        if ($anneeId > 0) {
            $where[] = "t.annee_scolaire_id = :aid";
            $params['aid'] = $anneeId;
        }

        if ($trimestreId > 0) {
            $where[] = "ev.trimestre_id = :tid";
            $params['tid'] = $trimestreId;
        }

        if ($classeId > 0) {
            $where[] = "ev.classe_id = :cid";
            $params['cid'] = $classeId;
        }

        if ($groupeId > 0) {
            $where[] = "ev.groupe_id = :gid";
            $params['gid'] = $groupeId;
        }

        if (in_array($type, ['Période 1', 'Période 2', 'Examen'], true)) {
            $where[] = "ev.type_evaluation = :type";
            $params['type'] = $type;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $evaluations = $this->db->fetchAll(
            "SELECT ev.*, 
                    t.libelle AS trimestre_libelle, 
                    t.numero AS trimestre_numero,
                    t.est_actif AS trimestre_actif,
                    c.nom AS classe_nom, 
                    c.niveau AS classe_niveau,
                    m.libelle AS groupe_libelle,
                    m.code AS groupe_code,
                    (SELECT COUNT(*) FROM notes n WHERE n.evaluation_id = ev.id) AS nb_notes_saisies,
                    (SELECT COUNT(*) FROM inscriptions i
                     WHERE i.classe_id = ev.classe_id AND i.annee_scolaire_id = c.annee_scolaire_id
                       AND i.statut = 'Actif')
                    * (SELECT COUNT(*)
                       FROM classe_matieres cm
                       INNER JOIN matieres sm ON sm.id = cm.matiere_id
                       WHERE cm.classe_id = ev.classe_id AND cm.est_active = 1
                         AND sm.parent_id = ev.groupe_id AND sm.est_groupe = 0 AND sm.est_actif = 1) AS nb_notes_attendues
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             {$whereClause}
             ORDER BY t.numero, c.niveau, FIELD(ev.type_evaluation, 'Période 1', 'Période 2', 'Examen')",
            $params
        );

        jsonResponse(true, 'Liste récupérée.', ['evaluations' => $evaluations]);
    }

    // ============================================================
    // RÉCUPÉRATION D'UNE ÉVALUATION
    // ============================================================

    private function getEvaluation(): void
    {
        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        $evaluation = $this->db->fetchOne(
            "SELECT ev.*, 
                    t.libelle AS trimestre_libelle, 
                    c.nom AS classe_nom,
                    m.libelle AS groupe_libelle
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             WHERE ev.id = :id",
            ['id' => $id]
        );

        if (!$evaluation) jsonResponse(false, 'Évaluation introuvable.', [], 404);
        if (!$this->canCurrentUserManageEvaluation($id)) {
            jsonResponse(false, 'Évaluation introuvable.', [], 404);
        }

        jsonResponse(true, 'Évaluation récupérée.', ['evaluation' => $evaluation]);
    }

    // ============================================================
    // CRÉATION D'UNE ÉVALUATION
    // ============================================================

    private function creer(): void
    {
        requirePermission('evaluations.creer');

        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);
        $groupeId    = (int) post('groupe_id', 0);
        $type        = post('type_evaluation', '');
        $dateEval    = post('date_evaluation', '');

        $errors = [];

        if ($classeId <= 0) $errors['classe_id'] = 'Classe obligatoire.';
        if ($trimestreId <= 0) $errors['trimestre_id'] = 'Trimestre obligatoire.';
        if ($groupeId <= 0) $errors['groupe_id'] = 'Groupe de matières obligatoire.';
        if (!in_array($type, ['Période 1', 'Période 2', 'Examen'])) {
            $errors['type_evaluation'] = 'Type invalide.';
        }
        if (isBlank($dateEval)) $errors['date_evaluation'] = 'Date obligatoire.';

        if ($classeId > 0 && $trimestreId > 0) {
            $trimestreValide = $this->db->fetchColumn(
                "SELECT COUNT(*)
                 FROM classes c
                 INNER JOIN trimestres t ON t.annee_scolaire_id = c.annee_scolaire_id
                 WHERE c.id = :cid AND t.id = :tid",
                ['cid' => $classeId, 'tid' => $trimestreId]
            );
            if ((int) $trimestreValide !== 1) {
                $errors['trimestre_id'] = 'Le trimestre doit appartenir à l’année scolaire de la classe.';
            }
        }

        // Vérifier l'unicité : une seule évaluation par groupe/classe/trimestre/type
        if ($groupeId > 0 && $classeId > 0 && $trimestreId > 0 && !empty($type)) {
            $existe = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM evaluations 
                 WHERE trimestre_id = :tid AND classe_id = :cid AND groupe_id = :gid AND type_evaluation = :type",
                ['tid' => $trimestreId, 'cid' => $classeId, 'gid' => $groupeId, 'type' => $type]
            );
            if ($existe > 0) {
                $errors['type_evaluation'] = 'Cette évaluation existe déjà pour ce groupe, cette classe et ce trimestre.';
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Veuillez corriger les erreurs.', ['errors' => $errors], 422);
        }

        try {
            $id = $this->db->insert(
                "INSERT INTO evaluations (trimestre_id, groupe_id, classe_id, type_evaluation, date_evaluation)
                 VALUES (:tid, :gid, :cid, :type, :date_eval)",
                [
                    'tid'       => $trimestreId,
                    'gid'       => $groupeId,
                    'cid'       => $classeId,
                    'type'      => $type,
                    'date_eval' => $dateEval
                ]
            );

            logActivity('Création', 'evaluations', $id, "Évaluation créée : groupe {$groupeId}, classe {$classeId}, {$type}");

            jsonResponse(true, 'Évaluation créée avec succès.', ['id' => $id]);

        } catch (Exception $e) {
            error_log('Erreur création évaluation: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la création.');
        }
    }

    // ============================================================
    // MODIFICATION D'UNE ÉVALUATION
    // ============================================================

    private function modifier(): void
    {
        requirePermission('evaluations.modifier');

        $id       = (int) post('id', 0);
        $dateEval = post('date_evaluation', '');

        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);
        if (isBlank($dateEval)) jsonResponse(false, 'Date obligatoire.', [], 422);

        $existe = $this->db->fetchOne("SELECT id FROM evaluations WHERE id = :id", ['id' => $id]);
        if (!$existe) jsonResponse(false, 'Évaluation introuvable.', [], 404);

        try {
            $this->db->update(
                "UPDATE evaluations SET date_evaluation = :date_eval WHERE id = :id",
                ['date_eval' => $dateEval, 'id' => $id]
            );

            logActivity('Modification', 'evaluations', $id, 'Date modifiée');
            jsonResponse(true, 'Date modifiée avec succès.');

        } catch (Exception $e) {
            error_log('Erreur modification évaluation: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la modification.');
        }
    }

    // ============================================================
    // SUPPRESSION D'UNE ÉVALUATION
    // ============================================================

    private function supprimer(): void
    {
        requirePermission('evaluations.supprimer');

        $id = (int) post('id', 0);
        if ($id <= 0) jsonResponse(false, 'ID invalide.', [], 400);

        // Vérifier si des notes existent
        $nbNotes = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notes WHERE evaluation_id = :eid",
            ['eid' => $id]
        );

        if ($nbNotes > 0) {
            jsonResponse(false, "Impossible de supprimer : {$nbNotes} note(s) saisie(s). Supprimez d'abord les notes.", [], 409);
        }

        try {
            $this->db->delete("DELETE FROM evaluations WHERE id = :id", ['id' => $id]);
            logActivity('Suppression', 'evaluations', $id, 'Évaluation supprimée');
            jsonResponse(true, 'Évaluation supprimée avec succès.');
        } catch (Exception $e) {
            error_log('Erreur suppression évaluation: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la suppression.');
        }
    }

    // ============================================================
    // GÉNÉRATION AUTOMATIQUE DES ÉVALUATIONS
    // ============================================================

    private function genererEvaluations(): void
    {
        requirePermission('evaluations.creer');

        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $trimestre = $this->db->fetchOne(
            "SELECT t.*
             FROM trimestres t
             INNER JOIN classes c ON c.annee_scolaire_id = t.annee_scolaire_id
             WHERE t.id = :tid AND c.id = :cid",
            ['tid' => $trimestreId, 'cid' => $classeId]
        );
        if (!$trimestre) {
            jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
        }

        // Récupérer tous les groupes actifs
        $groupes = $this->db->fetchAll(
            "SELECT m.id, m.libelle 
             FROM matieres m 
             WHERE m.est_groupe = 1 AND m.est_actif = 1
             ORDER BY m.id"
        );

        $types = ['Période 1', 'Période 2', 'Examen'];
        $crees = 0;
        $ignores = 0;

        foreach ($groupes as $groupe) {
            foreach ($types as $type) {
                // Vérifier si l'évaluation existe déjà
                $existe = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM evaluations 
                     WHERE trimestre_id = :tid AND classe_id = :cid AND groupe_id = :gid AND type_evaluation = :type",
                    ['tid' => $trimestreId, 'cid' => $classeId, 'gid' => $groupe['id'], 'type' => $type]
                );

                if ($existe > 0) {
                    $ignores++;
                    continue;
                }

                // Calculer la date suggérée
                $dateSuggeree = $this->calculerDateSuggestion($trimestre, $type);

                $this->db->insert(
                    "INSERT INTO evaluations (trimestre_id, groupe_id, classe_id, type_evaluation, date_evaluation)
                     VALUES (:tid, :gid, :cid, :type, :date_eval)",
                    [
                        'tid'       => $trimestreId,
                        'gid'       => $groupe['id'],
                        'cid'       => $classeId,
                        'type'      => $type,
                        'date_eval' => $dateSuggeree
                    ]
                );

                $crees++;
            }
        }

        logActivity('Génération', 'evaluations', null, "Génération {$crees} évaluations pour classe {$classeId}");

        jsonResponse(true, "{$crees} évaluation(s) créée(s), {$ignores} ignorée(s).");
    }

    // ============================================================
    // GRILLE DE SAISIE DES NOTES
    // ============================================================

    private function getGrilleSaisie(): void
    {
        $evaluationId = (int) post('evaluation_id', 0);
        if ($evaluationId <= 0) jsonResponse(false, 'ID évaluation invalide.', [], 400);

        // Récupérer l'évaluation
        $evaluation = $this->db->fetchOne(
            "SELECT ev.*, 
                    t.libelle AS trimestre_libelle, 
                    t.numero AS trimestre_numero,
                    c.nom AS classe_nom, 
                    c.niveau AS classe_niveau,
                    c.annee_scolaire_id,
                    m.libelle AS groupe_libelle,
                    m.id AS groupe_id
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id AND c.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             WHERE ev.id = :id",
            ['id' => $evaluationId]
        );

        if (!$evaluation) jsonResponse(false, 'Évaluation introuvable.', [], 404);
        if (!$this->canCurrentUserManageEvaluation($evaluationId)) {
            jsonResponse(false, 'Évaluation introuvable.', [], 404);
        }

        // Récupérer les sous-matières du groupe avec leurs maxima
        $sousMatieres = $this->db->fetchAll(
            "SELECT m.id, m.libelle, m.code, 
                    COALESCE(cm.maxima_override, m.maxima) AS maxima
             FROM matieres m
             INNER JOIN classe_matieres cm ON m.id = cm.matiere_id AND cm.classe_id = :cid AND cm.est_active = 1
             WHERE m.parent_id = :gid 
             AND m.est_groupe = 0 
             AND m.est_actif = 1
             ORDER BY m.id",
            ['gid' => $evaluation['groupe_id'], 'cid' => $evaluation['classe_id']]
        );

        // Récupérer les élèves de la classe
        $eleves = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
               AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['cid' => $evaluation['classe_id'], 'aid' => $evaluation['annee_scolaire_id']]
        );

        // Récupérer les notes existantes
        $notes = $this->db->fetchAll(
            "SELECT n.id, n.eleve_id, n.sous_matiere_id, n.valeur, n.absence
             FROM notes n
             WHERE n.evaluation_id = :eid",
            ['eid' => $evaluationId]
        );

        // Organiser les notes en grille [eleve_id][sous_matiere_id]
        $notesGrid = [];
        foreach ($notes as $note) {
            $notesGrid[$note['eleve_id']][$note['sous_matiere_id']] = [
                'id'      => $note['id'],
                'valeur'  => $note['valeur'] !== null ? (float) $note['valeur'] : null,
                'absence' => (bool) $note['absence']
            ];
        }

        // Statistiques
        $nbNotesSaisies = count($notes);
        $nbTotalNotes = count($eleves) * count($sousMatieres);

        jsonResponse(true, 'Grille récupérée.', [
            'evaluation'     => $evaluation,
            'sousMatieres'   => $sousMatieres,
            'eleves'         => $eleves,
            'notes'          => $notesGrid,
            'nbNotesSaisies' => $nbNotesSaisies,
            'nbTotalNotes'   => $nbTotalNotes
        ]);
    }

    // ============================================================
    // SAUVEGARDE DES NOTES
    // ============================================================

    private function sauvegarderNotes(): void
    {
        requirePermission('notes.saisir');
        Security::getInstance()->validateCsrf();

        $evaluationId = (int) post('evaluation_id', 0);
        $notesData    = post('notes', '');

        if ($evaluationId <= 0) {
            jsonResponse(false, 'ID évaluation invalide.', [], 400);
        }

        $notesArray = json_decode($notesData, true);

        if (empty($notesArray) || !is_array($notesArray)) {
            jsonResponse(false, 'Aucune note à enregistrer (JSON invalide).', [], 400);
        }

        $evaluation = $this->db->fetchOne(
            "SELECT ev.id, ev.classe_id, ev.groupe_id, ev.type_evaluation,
                    c.annee_scolaire_id
             FROM evaluations ev
             INNER JOIN trimestres t ON t.id = ev.trimestre_id
             INNER JOIN classes c ON c.id = ev.classe_id
                 AND c.annee_scolaire_id = t.annee_scolaire_id
             WHERE ev.id = :id",
            ['id' => $evaluationId]
        );
        if (!$evaluation || !$this->canCurrentUserManageEvaluation($evaluationId)) {
            jsonResponse(false, 'Évaluation introuvable.', [], 404);
        }

        $studentIds = array_map('intval', array_column(
            $this->db->fetchAll(
                "SELECT i.eleve_id
                 FROM inscriptions i
                 INNER JOIN eleves e ON e.id = i.eleve_id AND e.est_actif = 1
                 WHERE i.classe_id = :class_id
                   AND i.annee_scolaire_id = :year_id
                   AND i.statut = 'Actif'",
                ['class_id' => $evaluation['classe_id'], 'year_id' => $evaluation['annee_scolaire_id']]
            ),
            'eleve_id'
        ));
        $subjectRows = $this->db->fetchAll(
            "SELECT sm.id, COALESCE(cm.maxima_override, sm.maxima) AS maxima
             FROM matieres sm
             INNER JOIN classe_matieres cm ON cm.matiere_id = sm.id
                 AND cm.classe_id = :class_id AND cm.est_active = 1
             WHERE sm.parent_id = :group_id AND sm.est_groupe = 0 AND sm.est_actif = 1",
            ['class_id' => $evaluation['classe_id'], 'group_id' => $evaluation['groupe_id']]
        );
        $subjectMaxima = [];
        foreach ($subjectRows as $subject) {
            $subjectMaxima[(int) $subject['id']] = (float) $subject['maxima']
                * ($evaluation['type_evaluation'] === 'Examen' ? 2 : 1);
        }
        $allowedStudents = array_fill_keys($studentIds, true);
        $normalizedNotes = [];
        $seenCells = [];
        foreach ($notesArray as $note) {
            if (!is_array($note)) {
                jsonResponse(false, 'Données de note invalides.', [], 422);
            }

            $eleveId = (int) ($note['eleve_id'] ?? 0);
            $sousMatiereId = (int) ($note['sous_matiere_id'] ?? 0);
            $absenceRaw = $note['absence'] ?? 0;
            if (
                $eleveId <= 0
                || !isset($allowedStudents[$eleveId])
                || !array_key_exists($sousMatiereId, $subjectMaxima)
                || !in_array($absenceRaw, [0, 1, '0', '1', false, true], true)
            ) {
                jsonResponse(false, 'Élève ou sous-matière non autorisé pour cette évaluation.', [], 422);
            }
            $cellKey = $eleveId . ':' . $sousMatiereId;
            if (isset($seenCells[$cellKey])) {
                jsonResponse(false, 'Une note est répétée dans la requête.', [], 422);
            }
            $seenCells[$cellKey] = true;

            $rawValue = $note['valeur'] ?? null;
            if ($rawValue === '') {
                $rawValue = null;
            }
            if ($rawValue !== null && (!is_numeric($rawValue) || !is_finite((float) $rawValue))) {
                jsonResponse(false, 'Valeur de note invalide.', [], 422);
            }

            $value = $rawValue !== null ? (float) $rawValue : null;
            if ($value !== null && ($value < 0 || $value > $subjectMaxima[$sousMatiereId])) {
                jsonResponse(false, 'La note dépasse le maximum autorisé.', [], 422);
            }
            if ((bool) $absenceRaw && $value !== null) {
                jsonResponse(false, 'Une absence ne peut pas avoir de note numérique.', [], 422);
            }

            $normalizedNotes[] = [
                'eleve_id' => $eleveId,
                'sous_matiere_id' => $sousMatiereId,
                'valeur' => $value,
                'absence' => (int) (bool) $absenceRaw,
            ];
        }

        $sauvegardees = 0;
        $userId = $_SESSION['utilisateur_id'];

        try {
            $this->db->beginTransaction();
            foreach ($normalizedNotes as $note) {
                if ($this->evaluationNoteService->saveCell(
                    $evaluationId,
                    $note['eleve_id'],
                    $note['sous_matiere_id'],
                    $note['valeur'],
                    $note['absence'],
                    (int) $userId
                )) {
                    $sauvegardees++;
                }
            }

            $this->db->commit();
            logActivity('Saisie notes', 'notes', null, "{$sauvegardees} note(s) pour évaluation {$evaluationId}");

            jsonResponse(true, "{$sauvegardees} note(s) enregistrée(s).");

        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Erreur sauvegarde notes: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de l\'enregistrement.');
        }
    }

    // ============================================================
    // NOTES D'UN ÉLÈVE
    // ============================================================

    private function getNotesEleve(): void
    {
        $eleveId     = (int) post('eleve_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);
        if (!$this->studentAccessPolicy->canCurrentUserAccessStudent($eleveId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        $where = [];
        $params = ['eid' => $eleveId];

        if ($trimestreId > 0) {
            $where[] = "ev.trimestre_id = :tid";
            $params['tid'] = $trimestreId;
        }

        $whereClause = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';

        $notes = $this->db->fetchAll(
            "SELECT n.valeur, n.absence, 
                    ev.type_evaluation, 
                    DATE_FORMAT(ev.date_evaluation, '%d/%m/%Y') AS date_eval,
                    sm.libelle AS sous_matiere,
                    sm.maxima,
                    m.libelle AS groupe,
                    t.libelle AS trimestre
             FROM notes n
             INNER JOIN evaluations ev ON n.evaluation_id = ev.id
             INNER JOIN matieres sm ON n.sous_matiere_id = sm.id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             WHERE n.eleve_id = :eid {$whereClause}
             ORDER BY t.numero, m.libelle, sm.libelle, FIELD(ev.type_evaluation, 'Période 1', 'Période 2', 'Examen')",
            $params
        );

        jsonResponse(true, 'Notes récupérées.', ['notes' => $notes]);
    }

    public function canCurrentUserManageEvaluation(int $evaluationId): bool
    {
        if ($evaluationId <= 0) {
            return false;
        }

        $evaluation = $this->db->fetchOne(
            "SELECT ev.classe_id, t.annee_scolaire_id
             FROM evaluations ev
             INNER JOIN trimestres t ON t.id = ev.trimestre_id
             INNER JOIN classes c ON c.id = ev.classe_id
                 AND c.annee_scolaire_id = t.annee_scolaire_id
             WHERE ev.id = :id",
            ['id' => $evaluationId]
        );
        if (!$evaluation) {
            return false;
        }

        if (hasRole('Enseignant')) {
            return (bool) $this->db->fetchColumn(
                "SELECT EXISTS (
                    SELECT 1
                    FROM enseignants ens
                    INNER JOIN classes c ON c.id = ens.classe_id
                    WHERE ens.utilisateur_id = :user_id
                      AND ens.est_actif = 1
                      AND c.id = :class_id
                      AND c.annee_scolaire_id = :year_id
                      AND c.annee_scolaire_id = (
                          SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
                      )
                )",
                [
                    'user_id' => (int) ($_SESSION['utilisateur_id'] ?? 0),
                    'class_id' => $evaluation['classe_id'],
                    'year_id' => $evaluation['annee_scolaire_id'],
                ]
            );
        }

        return can('notes.saisir');
    }

    // ============================================================
    // PROGRESSION DU TRIMESTRE
    // ============================================================

    private function getProgressionTrimestre(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $anneeCoherente = (int) $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM classes c
             INNER JOIN trimestres t ON t.annee_scolaire_id = c.annee_scolaire_id
             WHERE c.id = :cid AND t.id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );
        if ($anneeCoherente !== 1) {
            jsonResponse(false, 'La classe et le trimestre doivent appartenir à la même année scolaire.', [], 400);
        }

        $progression = [];

        $types = ['Période 1', 'Période 2', 'Examen'];
        foreach ($types as $type) {
            $evaluations = $this->db->fetchAll(
                "SELECT id FROM evaluations 
                 WHERE trimestre_id = :tid AND classe_id = :cid AND type_evaluation = :type",
                ['tid' => $trimestreId, 'cid' => $classeId, 'type' => $type]
            );

            $nbNotes = 0;
            $nbAttendues = 0;

            if (!empty($evaluations)) {
                $evalIds = array_column($evaluations, 'id');
                $placeholders = implode(',', array_fill(0, count($evalIds), '?'));

                $nbNotes = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM notes WHERE evaluation_id IN ({$placeholders})",
                    $evalIds
                );

                $nbEleves = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*)
                     FROM inscriptions i
                     INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id
                     WHERE i.classe_id = :cid AND i.statut = 'Actif'",
                    ['cid' => $classeId]
                );

                $totalSousMatieres = 0;
                foreach ($evalIds as $eid) {
                    $eval = $this->db->fetchOne("SELECT groupe_id FROM evaluations WHERE id = :id", ['id' => $eid]);
                    if ($eval) {
                        $nbSM = (int) $this->db->fetchColumn(
                            "SELECT COUNT(*)
                             FROM classe_matieres cm
                             INNER JOIN matieres sm ON sm.id = cm.matiere_id
                             WHERE cm.classe_id = :cid AND cm.est_active = 1
                               AND sm.parent_id = :gid AND sm.est_groupe = 0 AND sm.est_actif = 1",
                            ['cid' => $classeId, 'gid' => $eval['groupe_id']]
                        );
                        $totalSousMatieres += $nbSM;
                    }
                }

                $nbAttendues = $nbEleves * $totalSousMatieres;
            }

            $progression[$type] = [
                'nb_evaluations' => count($evaluations),
                'nb_notes'       => $nbNotes,
                'nb_attendues'   => $nbAttendues,
                'pourcentage'    => $nbAttendues > 0 ? round(($nbNotes / $nbAttendues) * 100) : 0
            ];
        }

        jsonResponse(true, 'Progression récupérée.', ['progression' => $progression]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Calcule une date suggérée pour une évaluation
     */
    private function calculerDateSuggestion(array $trimestre, string $type): string
    {
        $dateDebut = $trimestre['date_debut'];
        $dateFin   = $trimestre['date_fin'];

        switch ($type) {
            case 'Période 1':
                return date('Y-m-d', strtotime($dateDebut . ' + 30 days'));
            case 'Période 2':
                return date('Y-m-d', strtotime($dateDebut . ' + 60 days'));
            case 'Examen':
                return date('Y-m-d', strtotime($dateFin . ' - 7 days'));
            default:
                return $dateDebut;
        }
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les classes de l'année active
     */
    public function getClassesActives(): array
    {
        return $this->db->fetchAll(
            "SELECT c.* FROM classes c
             WHERE c.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY c.niveau"
        );
    }

    /**
     * Récupère les trimestres de l'année active
     */
    public function getTrimestresActifs(): array
    {
        return $this->db->fetchAll(
            "SELECT t.* FROM trimestres t
             WHERE t.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY t.numero"
        );
    }

    /**
     * Récupère le trimestre actif
     */
    public function getTrimestreActif(): ?array
    {
        return $this->db->fetchOne(
            "SELECT t.* FROM trimestres t
             WHERE t.est_actif = 1 
             AND t.annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             LIMIT 1"
        );
    }

    /**
     * Récupère tous les groupes de matières actifs
     */
    public function getGroupesActifs(): array
    {
        return $this->db->fetchAll(
            "SELECT m.*, 
                    (SELECT COUNT(*) FROM matieres sm WHERE sm.parent_id = m.id AND sm.est_groupe = 0 AND sm.est_actif = 1) AS nb_sous_matieres
             FROM matieres m
             WHERE m.est_groupe = 1 AND m.est_actif = 1
             ORDER BY m.libelle"
        );
    }

    /**
     * Récupère les sous-matières d'un groupe avec leurs maxima pour une classe
     */
    public function getSousMatieresByGroupe(int $groupeId, int $classeId): array
    {
        return $this->db->fetchAll(
            "SELECT m.id, m.libelle, m.code, 
                    COALESCE(cm.maxima_override, m.maxima) AS maxima
             FROM matieres m
             INNER JOIN classe_matieres cm ON m.id = cm.matiere_id AND cm.classe_id = :cid AND cm.est_active = 1
             WHERE m.parent_id = :gid 
             AND m.est_groupe = 0 
             AND m.est_actif = 1
             ORDER BY m.id",
            ['gid' => $groupeId, 'cid' => $classeId]
        );
    }

    /**
     * Récupère une évaluation par ID
     */
    public function getEvaluationById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT ev.*, 
                    t.libelle AS trimestre_libelle,
                    c.nom AS classe_nom,
                    m.libelle AS groupe_libelle
             FROM evaluations ev
             INNER JOIN trimestres t ON ev.trimestre_id = t.id
             INNER JOIN classes c ON ev.classe_id = c.id
             INNER JOIN matieres m ON ev.groupe_id = m.id
             WHERE ev.id = :id",
            ['id' => $id]
        );
    }
}