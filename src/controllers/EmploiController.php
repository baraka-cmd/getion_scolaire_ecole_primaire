<?php
/**
 * Fichier : src/controllers/EmploiController.php
 * Rôle    : Gestion de l'emploi du temps (CRUD + consultation)
 * Système : École primaire congolaise — Horaire statique par classe
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

class EmploiController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    /**
 * Point d'entrée pour les requêtes AJAX
 */
public function handleAjax(): void
{
    if (!can('emploi.consulter')) {
        jsonResponse(false, 'Accès refusé.', [], 403);
    }

    if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Requête invalide.', [], 400);
    }

    $action = post('action', '');

    switch ($action) {
        case 'getEmploi':
            $this->getEmploi();
            break;
        case 'sauvegarderCreneau':
            $this->sauvegarderCreneau();
            break;
        case 'supprimerCreneau':
            $this->supprimerCreneau();
            break;
        case 'supprimerHoraire':
            $this->supprimerHoraire();
            break;
        case 'dupliquer':
            $this->dupliquer();
            break;
        case 'supprimerTout':
            $this->supprimerTout();
            break;
        default:
            jsonResponse(false, 'Action inconnue.', [], 400);
    }
}

/**
 * Supprime tous les créneaux d'un horaire spécifique
 */
public function supprimerHoraire(): void
{
    requirePermission('emploi.modifier');

    $classeId   = (int) post('classe_id', 0);
    $anneeId    = (int) post('annee_id', 0);
    $heureDebut = post('heure_debut', '');
    $heureFin   = post('heure_fin', '');

    if ($classeId <= 0 || empty($heureDebut) || empty($heureFin)) {
        jsonResponse(false, 'Paramètres obligatoires manquants.', [], 400);
    }

    if ($anneeId <= 0) {
        $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
    }
    $this->requireClassScheduleAccess($classeId, $anneeId);

    $this->db->delete(
        "DELETE FROM emploi_du_temps 
         WHERE classe_id = :cid AND annee_scolaire_id = :aid 
         AND heure_debut = :debut AND heure_fin = :fin",
        ['cid' => $classeId, 'aid' => $anneeId, 'debut' => $heureDebut, 'fin' => $heureFin]
    );

    jsonResponse(true, 'Horaire supprimé avec succès.');
}

    /**
     * Récupère l'emploi du temps d'une classe pour une année
     */
    public function getEmploi(): void
    {
        $classeId = (int) post('classe_id', 0);
        $anneeId  = (int) post('annee_id', 0);

        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        }
        $this->requireClassScheduleAccess($classeId, $anneeId, false);

        // Récupérer les créneaux
        $creneaux = $this->db->fetchAll(
            "SELECT edt.*, 
                    m.libelle AS matiere_libelle, m.code AS matiere_code,
                    CONCAT(COALESCE(e.prenom, ''), ' ', COALESCE(e.nom, '')) AS enseignant_nom,
                    e.id AS enseignant_id
             FROM emploi_du_temps edt
             LEFT JOIN matieres m ON edt.matiere_id = m.id
             LEFT JOIN enseignants e ON edt.enseignant_id = e.id
             WHERE edt.classe_id = :cid AND edt.annee_scolaire_id = :aid
             ORDER BY FIELD(edt.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'), edt.heure_debut",
            ['cid' => $classeId, 'aid' => $anneeId]
        );

        // Récupérer les sous-matières de la classe
        $matieres = $this->db->fetchAll(
            "SELECT m.id, m.libelle, m.code, p.libelle AS groupe_libelle
             FROM matieres m
             INNER JOIN matieres p ON m.parent_id = p.id
             INNER JOIN classe_matieres cm ON m.id = cm.matiere_id AND cm.classe_id = :cid AND cm.est_active = 1
             WHERE m.est_groupe = 0 AND m.est_actif = 1
             ORDER BY p.id, m.libelle",
            ['cid' => $classeId]
        );

        // Récupérer les enseignants
        $enseignants = $this->db->fetchAll(
            "SELECT id, matricule, nom, prenom, specialite 
             FROM enseignants WHERE est_actif = 1 ORDER BY nom"
        );

        // Organiser par jour et horaire
        $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $grille = [];

        foreach ($jours as $jour) {
            $grille[$jour] = [];
        }

        foreach ($creneaux as $creneau) {
            $grille[$creneau['jour']][] = $creneau;
        }

        // Extraire tous les horaires uniques
        $horaires = [];
        foreach ($creneaux as $c) {
            $key = $c['heure_debut'] . '-' . $c['heure_fin'];
            if (!isset($horaires[$key])) {
                $horaires[$key] = [
                    'heure_debut' => $c['heure_debut'],
                    'heure_fin'   => $c['heure_fin'],
                    'type'        => $c['type_creneau'] ?? 'cours'
                ];
            }
        }

        jsonResponse(true, 'Emploi du temps récupéré.', [
            'grille'    => $grille,
            'horaires'  => array_values($horaires),
            'jours'     => $jours,
            'matieres'  => $matieres,
            'enseignants' => $enseignants
        ]);
    }

    /**
     * Sauvegarde ou met à jour un créneau
     */
    public function sauvegarderCreneau(): void
    {
        requirePermission('emploi.modifier');

        $classeId     = (int) post('classe_id', 0);
        $anneeId      = (int) post('annee_id', 0);
        $jour         = (string) post('jour', '');
        $heureDebut   = (string) post('heure_debut', '');
        $heureFin     = (string) post('heure_fin', '');
        $matiereId    = (int) post('matiere_id', 0);
        $enseignantId = (int) post('enseignant_id', 0);
        $creneauId    = (int) post('creneau_id', 0);
        $typeCreneau  = (string) post('type_creneau', 'cours');
        $libelle      = trim((string) post('libelle', ''));

        if ($classeId <= 0 || $anneeId <= 0 || $jour === '' || $heureDebut === '' || $heureFin === '') {
            jsonResponse(false, 'Classe, année, jour et horaires sont obligatoires.', [], 400);
        }

        $this->requireClassScheduleAccess($classeId, $anneeId);

        $joursValides = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $typesValides = ['cours', 'recréation', 'cernafor'];
        if (!in_array($jour, $joursValides, true) || !in_array($typeCreneau, $typesValides, true)) {
            jsonResponse(false, 'Le jour ou le type de créneau est invalide.', [], 422);
        }
        if (!$this->isValidTime($heureDebut) || !$this->isValidTime($heureFin) || $heureDebut >= $heureFin) {
            jsonResponse(false, 'L’heure de début doit précéder l’heure de fin.', [], 422);
        }

        if ($creneauId > 0) {
            $existingSlot = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM emploi_du_temps
                 WHERE id = :id AND classe_id = :classe AND annee_scolaire_id = :annee",
                ['id' => $creneauId, 'classe' => $classeId, 'annee' => $anneeId]
            );
            if ((int) $existingSlot !== 1) {
                jsonResponse(false, 'Le créneau à modifier est introuvable dans cette classe et cette année.', [], 404);
            }
        }

        if ($typeCreneau === 'cours') {
            if ($matiereId <= 0) {
                jsonResponse(false, 'Une matière doit être choisie pour un cours.', [], 422);
            }
            $matiereValide = $this->db->fetchColumn(
                "SELECT COUNT(*)
                 FROM classe_matieres cm
                 INNER JOIN matieres m ON m.id = cm.matiere_id
                 WHERE cm.classe_id = :classe AND cm.matiere_id = :matiere
                   AND cm.est_active = 1 AND m.est_actif = 1 AND m.est_groupe = 0",
                ['classe' => $classeId, 'matiere' => $matiereId]
            );
            if ((int) $matiereValide !== 1) {
                jsonResponse(false, 'Cette matière n’est pas active pour la classe sélectionnée.', [], 422);
            }
        } else {
            $matiereId = 0;
            $enseignantId = 0;
            if ($libelle === '') {
                $libelle = $typeCreneau === 'recréation' ? 'Récréation' : 'CERNAFOR';
            }
        }

        if (mb_strlen($libelle) > 100) {
            jsonResponse(false, 'Le libellé ne peut pas dépasser 100 caractères.', [], 422);
        }

        if ($enseignantId > 0) {
            $enseignantActif = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM enseignants WHERE id = :id AND est_actif = 1",
                ['id' => $enseignantId]
            );
            if ((int) $enseignantActif !== 1) {
                jsonResponse(false, 'L’enseignant sélectionné est introuvable ou inactif.', [], 422);
            }
        }

        $excludeSlotSql = $creneauId > 0 ? ' AND id <> :exclude_id' : '';
        $overlapParams = [
            'classe' => $classeId,
            'annee' => $anneeId,
            'jour' => $jour,
            'debut' => $heureDebut,
            'fin' => $heureFin
        ];
        if ($creneauId > 0) {
            $overlapParams['exclude_id'] = $creneauId;
        }
        $classeOverlap = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM emploi_du_temps
             WHERE classe_id = :classe AND annee_scolaire_id = :annee AND jour = :jour
               AND heure_debut < :fin AND heure_fin > :debut{$excludeSlotSql}",
            $overlapParams
        );
        if ((int) $classeOverlap > 0) {
            jsonResponse(false, 'Ce créneau chevauche déjà un autre horaire de la classe.', [], 409);
        }

        if ($enseignantId > 0) {
            $teacherOverlapParams = [
                'enseignant' => $enseignantId,
                'annee' => $anneeId,
                'jour' => $jour,
                'debut' => $heureDebut,
                'fin' => $heureFin
            ];
            if ($creneauId > 0) {
                $teacherOverlapParams['exclude_id'] = $creneauId;
            }
            $enseignantOverlap = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM emploi_du_temps
                 WHERE enseignant_id = :enseignant AND annee_scolaire_id = :annee AND jour = :jour
                   AND heure_debut < :fin AND heure_fin > :debut{$excludeSlotSql}",
                $teacherOverlapParams
            );
            if ((int) $enseignantOverlap > 0) {
                jsonResponse(false, 'Cet enseignant a déjà un cours qui chevauche cet horaire.', [], 409);
            }
        }

        try {
            $params = [
                'jour' => $jour,
                'debut' => $heureDebut,
                'fin' => $heureFin,
                'matiere' => $matiereId > 0 ? $matiereId : null,
                'enseignant' => $enseignantId > 0 ? $enseignantId : null,
                'type' => $typeCreneau,
                'libelle' => $libelle !== '' ? $libelle : null
            ];
            if ($creneauId > 0) {
                $params['id'] = $creneauId;
                $params['classe'] = $classeId;
                $params['annee'] = $anneeId;
                $this->db->update(
                    "UPDATE emploi_du_temps
                     SET jour = :jour, heure_debut = :debut, heure_fin = :fin,
                         matiere_id = :matiere, enseignant_id = :enseignant,
                         type_creneau = :type, libelle = :libelle
                     WHERE id = :id AND classe_id = :classe AND annee_scolaire_id = :annee",
                    $params
                );
                jsonResponse(true, 'Créneau modifié avec succès.');
            }

            $params['classe'] = $classeId;
            $params['annee'] = $anneeId;
            $this->db->insert(
                "INSERT INTO emploi_du_temps
                    (classe_id, annee_scolaire_id, jour, heure_debut, heure_fin, matiere_id, enseignant_id, type_creneau, libelle)
                 VALUES (:classe, :annee, :jour, :debut, :fin, :matiere, :enseignant, :type, :libelle)",
                $params
            );
            jsonResponse(true, 'Créneau ajouté avec succès.');
        } catch (Exception $e) {
            error_log('Erreur sauvegarde créneau: ' . $e->getMessage());
            jsonResponse(false, 'Impossible d’enregistrer ce créneau. Vérifiez les horaires et les données de la classe.', [], 409);
        }
    }

    /**
     * Supprime un créneau
     */
    public function supprimerCreneau(): void
    {
        requirePermission('emploi.modifier');

        $creneauId = (int) post('creneau_id', 0);
        $classeId = (int) post('classe_id', 0);
        $anneeId = (int) post('annee_id', 0);
        if ($creneauId <= 0 || $classeId <= 0 || $anneeId <= 0) {
            jsonResponse(false, 'Créneau, classe et année sont obligatoires.', [], 400);
        }
        $this->requireClassScheduleAccess($classeId, $anneeId);

        $this->db->delete(
            "DELETE FROM emploi_du_temps
             WHERE id = :id AND classe_id = :classe AND annee_scolaire_id = :annee",
            ['id' => $creneauId, 'classe' => $classeId, 'annee' => $anneeId]
        );
        jsonResponse(true, 'Créneau supprimé avec succès.');
    }

    /**
     * Duplique l'emploi du temps d'une classe vers une autre
     */
    public function dupliquer(): void
    {
        requirePermission('emploi.creer');

        $classeSource = (int) post('classe_source_id', 0);
        $classeDest   = (int) post('classe_dest_id', 0);
        $anneeId      = (int) post('annee_id', 0);

        if ($classeSource <= 0 || $classeDest <= 0) {
            jsonResponse(false, 'Classes source et destination obligatoires.', [], 400);
        }

        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        }
        if ($classeSource === $classeDest) {
            jsonResponse(false, 'Choisissez deux classes différentes.', [], 422);
        }
        $this->requireClassScheduleAccess($classeSource, $anneeId);
        $this->requireClassScheduleAccess($classeDest, $anneeId);

        try {
            $this->db->beginTransaction();
            $this->db->delete(
                "DELETE FROM emploi_du_temps WHERE classe_id = :cid AND annee_scolaire_id = :aid",
                ['cid' => $classeDest, 'aid' => $anneeId]
            );

            // Copier les créneaux
            $this->db->query(
                "INSERT INTO emploi_du_temps (classe_id, annee_scolaire_id, jour, heure_debut, heure_fin, 
                 matiere_id, enseignant_id, type_creneau, libelle)
                 SELECT :dest, annee_scolaire_id, jour, heure_debut, heure_fin, 
                        matiere_id, enseignant_id, type_creneau, libelle
                 FROM emploi_du_temps
                 WHERE classe_id = :source AND annee_scolaire_id = :aid",
                ['dest' => $classeDest, 'source' => $classeSource, 'aid' => $anneeId]
            );

            $this->db->commit();
            jsonResponse(true, 'Emploi du temps dupliqué avec succès.');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur duplication: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la duplication.');
        }
    }

    /**
     * Supprime tout l'emploi du temps d'une classe
     */
    public function supprimerTout(): void
    {
        requirePermission('emploi.modifier');

        $classeId = (int) post('classe_id', 0);
        $anneeId  = (int) post('annee_id', 0);

        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);
        if ($anneeId <= 0) {
            $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        }
        $this->requireClassScheduleAccess($classeId, $anneeId);

        $this->db->delete(
            "DELETE FROM emploi_du_temps WHERE classe_id = :cid AND annee_scolaire_id = :aid",
            ['cid' => $classeId, 'aid' => $anneeId]
        );

        jsonResponse(true, 'Emploi du temps supprimé avec succès.');
    }

    // ============================================================
    // MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les classes de l'année sélectionnée ou de l'année active
     */
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

    /**
     * Récupère les années scolaires
     */
    public function getAnneesScolaires(): array
    {
        return $this->db->fetchAll("SELECT * FROM annees_scolaires ORDER BY date_debut DESC");
    }

    /**
     * Récupère l'emploi du temps d'une classe (pour affichage direct)
     */
    public function getEmploiByClasse(int $classeId, ?int $anneeId = null): array
    {
        if ($anneeId === null) {
            $anneeId = (int) $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
        }

        return $this->db->fetchAll(
            "SELECT edt.*, 
                    m.libelle AS matiere_libelle, m.code AS matiere_code,
                    CONCAT(COALESCE(e.prenom, ''), ' ', COALESCE(e.nom, '')) AS enseignant_nom
             FROM emploi_du_temps edt
             LEFT JOIN matieres m ON edt.matiere_id = m.id
             LEFT JOIN enseignants e ON edt.enseignant_id = e.id
             WHERE edt.classe_id = :cid AND edt.annee_scolaire_id = :aid
             ORDER BY FIELD(edt.jour, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'), edt.heure_debut",
            ['cid' => $classeId, 'aid' => $anneeId]
        );
    }

    /**
     * Vérifie si l'enseignant connecté est titulaire de la classe
     */
    public function estTitulaire(int $classeId): bool
    {
        $enseignantId = $_SESSION['utilisateur_id'] ?? 0;
        $enseignant = $this->db->fetchOne(
            "SELECT id FROM enseignants WHERE utilisateur_id = :uid AND est_actif = 1 LIMIT 1",
            ['uid' => $enseignantId]
        );
        if (!$enseignant) return false;

        $titulaire = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM enseignants WHERE id = :eid AND classe_id = :cid",
            ['eid' => $enseignant['id'], 'cid' => $classeId]
        );

        return $titulaire > 0;
    }

    private function requireClassScheduleAccess(int $classeId, int $anneeId, bool $requireEditPermission = true): void
    {
        $classeExiste = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM classes WHERE id = :classe AND annee_scolaire_id = :annee",
            ['classe' => $classeId, 'annee' => $anneeId]
        );
        if ((int) $classeExiste !== 1) {
            jsonResponse(false, 'La classe ne correspond pas à l’année scolaire sélectionnée.', [], 422);
        }

        if (
            $requireEditPermission
            && !hasRole(['Admin', 'Directeur'])
            && !(hasRole('Enseignant') && $this->estTitulaire($classeId))
        ) {
            jsonResponse(false, 'Vous ne pouvez modifier que l’emploi du temps de votre classe.', [], 403);
        }
    }

    private function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

}