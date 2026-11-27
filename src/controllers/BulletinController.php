<?php
/**
 * Fichier : src/controllers/BulletinController.php
 * Rôle    : Génération des bulletins pour une classe entière
 * Système : Bulletin congolais — 6 périodes + 3 examens + 3 trimestres + total annuel
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/AcademicResultsService.php';
require_once __DIR__ . '/../services/ResultsBulletinService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class BulletinController
{
    private Database $db;
    private AcademicResultsService $academicResults;
    private ResultsBulletinService $resultsBulletinService;
    private StudentAccessPolicy $studentAccessPolicy;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->academicResults = new AcademicResultsService();
        $this->resultsBulletinService = new ResultsBulletinService();
        $this->studentAccessPolicy = new StudentAccessPolicy($this->db);
    }

    /**
     * Point d'entrée pour les requêtes AJAX
     */
    public function handleAjax(): void
    {
        if (!can('bulletins.consulter')) {
            jsonResponse(false, 'Accès refusé.', [], 403);
        }

        if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Requête invalide.', [], 400);
        }

        $action = post('action', '');

        switch ($action) {
            case 'genererBulletinsClasse':
                $this->genererBulletinsClasse();
                break;
            case 'getBulletinsClasse':
                $this->getBulletinsClasse();
                break;
            case 'genererBulletinEleve':
                $this->genererBulletinEleve();
                break;
            case 'getStatistiquesClasse':
                $this->getStatistiquesClasse();
                break;
            default:
                jsonResponse(false, 'Action inconnue.', [], 400);
        }
    }

    // ============================================================
    // 1. GÉNÉRATION DES BULLETINS POUR UNE CLASSE
    // ============================================================

    /**
     * Génère les bulletins de tous les élèves d'une classe
     */
    public function genererBulletinsClasse(): void
    {
        requirePermission('bulletins.generer');

        $classeId = (int) post('classe_id', 0);
        $anneeId  = (int) post('annee_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);

        // Si pas d'année, prendre l'année active
        if ($anneeId <= 0) {
            $anneeId = $this->resultsBulletinService->getActiveSchoolYearId();
        }

        $classe = $this->db->fetchOne(
            "SELECT id, annee_scolaire_id FROM classes WHERE id = :cid",
            ['cid' => $classeId]
        );
        if (!$classe || (int) $classe['annee_scolaire_id'] !== $anneeId) {
            jsonResponse(false, 'La classe ne correspond pas à l’année scolaire sélectionnée.', [], 422);
        }

        if ($trimestreId > 0) {
            $trimestreValide = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM trimestres
                 WHERE id = :tid AND annee_scolaire_id = :aid",
                ['tid' => $trimestreId, 'aid' => $anneeId]
            );
            if ((int) $trimestreValide !== 1) {
                jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire sélectionnée.', [], 422);
            }
        }

        // Récupérer les élèves de la classe
        $eleves = $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom
             FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid AND i.statut = 'Actif' AND e.est_actif = 1
             ORDER BY e.nom, e.prenom",
            ['cid' => $classeId, 'aid' => $anneeId]
        );

        if (empty($eleves)) {
            jsonResponse(false, 'Aucun élève trouvé dans cette classe.', [], 404);
        }

        $generes = 0;
        $ignores = 0;

        try {
            $this->db->beginTransaction();

            foreach ($eleves as $eleve) {
                // Vérifier si le bulletin existe déjà pour ce trimestre
                if ($trimestreId > 0) {
                    $existe = $this->db->fetchColumn(
                        "SELECT COUNT(*) FROM bulletins 
                         WHERE eleve_id = :eid AND trimestre_id = :tid",
                        ['eid' => $eleve['id'], 'tid' => $trimestreId]
                    );

                    if ($existe > 0) {
                        $ignores++;
                        continue;
                    }
                }

                // Récupérer les résultats calculés
                $resultats = [];
                if ($trimestreId > 0) {
                    $resultats = $this->db->fetchAll(
                        "SELECT * FROM resultats_trimestriels 
                         WHERE eleve_id = :eid AND classe_id = :cid AND trimestre_id = :tid",
                        ['eid' => $eleve['id'], 'cid' => $classeId, 'tid' => $trimestreId]
                    );
                } else {
                    // Tous les trimestres
                    $resultats = $this->db->fetchAll(
                        "SELECT * FROM resultats_trimestriels 
                         WHERE eleve_id = :eid AND classe_id = :cid
                         AND trimestre_id IN (SELECT id FROM trimestres WHERE annee_scolaire_id = :aid)
                         ORDER BY trimestre_id",
                        ['eid' => $eleve['id'], 'cid' => $classeId, 'aid' => $anneeId]
                    );
                }

                if (empty($resultats)) {
                    $ignores++;
                    continue;
                }

                // Générer le bulletin pour chaque trimestre
                foreach ($resultats as $resultat) {
                    $numeroBulletin = 'BUL-' . $eleve['matricule'] . '-T' . $resultat['trimestre_id'] . '-' . date('Y');

                    $bulletinId = $this->db->insert(
                        "INSERT INTO bulletins (eleve_id, trimestre_id, numero_bulletin, date_edition, 
                         edite_par, commentaire_enseignant, est_imprime)
                         VALUES (:eid, :tid, :numero, CURDATE(), :uid, :commentaire, 0)",
                        [
                            'eid'         => $eleve['id'],
                            'tid'         => $resultat['trimestre_id'],
                            'numero'      => $numeroBulletin,
                            'uid'         => $_SESSION['utilisateur_id'],
                            'commentaire' => 'Bulletin généré automatiquement.'
                        ]
                    );

                    $generes++;
                }
            }

            $this->db->commit();

            logActivity('Génération', 'bulletins', null, "{$generes} bulletin(s) généré(s) pour la classe {$classeId}");

            jsonResponse(true, "{$generes} bulletin(s) généré(s), {$ignores} ignoré(s).");

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur génération bulletins: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors de la génération des bulletins.');
        }
    }

    // ============================================================
    // 2. RÉCUPÉRATION DES BULLETINS D'UNE CLASSE
    // ============================================================

    /**
     * Récupère la liste des bulletins générés pour une classe
     */
    public function getBulletinsClasse(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0) jsonResponse(false, 'ID classe invalide.', [], 400);

        $classe = $this->db->fetchOne(
            "SELECT id, annee_scolaire_id FROM classes WHERE id = :cid",
            ['cid' => $classeId]
        );
        if (!$classe) jsonResponse(false, 'Classe introuvable.', [], 404);

        if ($trimestreId > 0) {
            $trimestreValide = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM trimestres
                 WHERE id = :tid AND annee_scolaire_id = :aid",
                ['tid' => $trimestreId, 'aid' => $classe['annee_scolaire_id']]
            );
            if ((int) $trimestreValide !== 1) {
                jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
            }
        }

        $where = [];
        $params = ['cid' => $classeId];

        if ($trimestreId > 0) {
            $where[] = "b.trimestre_id = :tid";
            $params['tid'] = $trimestreId;
        }

        $whereClause = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';

        $bulletins = $this->db->fetchAll(
            "SELECT b.*, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom, t.libelle AS trimestre_libelle,
                    DATE_FORMAT(b.date_edition, '%d/%m/%Y %H:%i') AS date_edition_format,
                    rt.moyenne_generale, rt.mention, rt.decision, rt.rang, rt.effectif_classe
             FROM bulletins b
             INNER JOIN eleves e ON b.eleve_id = e.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             INNER JOIN trimestres t ON b.trimestre_id = t.id AND t.annee_scolaire_id = c.annee_scolaire_id
             LEFT JOIN resultats_trimestriels rt ON b.eleve_id = rt.eleve_id
                AND b.trimestre_id = rt.trimestre_id AND i.classe_id = rt.classe_id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = c.annee_scolaire_id
               AND c.annee_scolaire_id = :aid {$whereClause}
             ORDER BY e.nom, e.prenom, t.numero",
            array_merge($params, ['aid' => $classe['annee_scolaire_id']])
        );

        jsonResponse(true, 'Bulletins récupérés.', [
            'bulletins' => $bulletins,
            'total'     => count($bulletins)
        ]);
    }

    // ============================================================
    // 3. GÉNÉRATION DU BULLETIN D'UN ÉLÈVE
    // ============================================================

    /**
     * Génère le bulletin complet d'un élève pour une année scolaire
     */
    public function genererBulletinEleve(): void
    {
        $eleveId = (int) post('eleve_id', 0);
        $anneeId = (int) post('annee_id', 0);

        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);

        if (!$this->studentAccessPolicy->canCurrentUserAccessStudent($eleveId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        if ($anneeId <= 0) {
            $anneeId = $this->resultsBulletinService->getActiveSchoolYearId();
        }

        // Récupérer les infos de l'élève
        $eleve = $this->resultsBulletinService->getStudentForBulletin($eleveId, $anneeId);

        if (!$eleve) jsonResponse(false, 'Élève introuvable.', [], 404);

        $groupes = $this->academicResults->getSubjectGroupsForClass((int) $eleve['classe_id']);
        $trimestresParNumero = $this->academicResults->getTrimestresByYear($anneeId);

        // Construire le bulletin
        $bulletin = [
            'eleve'     => $eleve,
            'annee'     => $this->resultsBulletinService->getSchoolYear($anneeId),
            'ecole'     => $this->resultsBulletinService->getSchoolParameters(),
            'groupes'   => [],
            'totaux'    => [
                'T1' => ['max' => 0, 'obt' => 0, 'p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                'T2' => ['max' => 0, 'obt' => 0, 'p3' => ['max' => 0, 'obt' => 0], 'p4' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                'T3' => ['max' => 0, 'obt' => 0, 'p5' => ['max' => 0, 'obt' => 0], 'p6' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                'annuel' => ['max' => 0, 'obt' => 0]
            ]
        ];

        foreach ($groupes as $groupe) {
            $groupeData = [
                'libelle'       => $groupe['libelle'],
                'code'          => $groupe['code'],
                'sous_matieres' => [],
                'sous_total'    => [
                    'T1' => ['max' => 0, 'obt' => 0],
                    'T2' => ['max' => 0, 'obt' => 0],
                    'T3' => ['max' => 0, 'obt' => 0],
                    'annuel' => ['max' => 0, 'obt' => 0]
                ]
            ];

            foreach ($groupe['sous_matieres'] as $sm) {
                $maxPeriode = (float) $sm['maxima_periode'];
                $maxExamen  = $maxPeriode * 2;
                $trimestre1Id = (int) ($trimestresParNumero[1]['id'] ?? 0);
                $trimestre2Id = (int) ($trimestresParNumero[2]['id'] ?? 0);
                $trimestre3Id = (int) ($trimestresParNumero[3]['id'] ?? 0);

                $notes = [
                    'T1' => $trimestre1Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre1Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                    'T2' => $trimestre2Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre2Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                    'T3' => $trimestre3Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre3Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                ];

                $maxTrimestre = $notes['T1']['total_max'];
                $smData = [
                    'libelle'       => $sm['libelle'],
                    'code'          => $sm['code'],
                    'max_periode'   => $maxPeriode,
                    'max_examen'    => $maxExamen,
                    'max_trimestre' => $maxTrimestre,
                    'max_annuel'    => $maxTrimestre * 3,
                    'notes'         => $notes,
                    'annuel'        => [
                        'max' => $maxTrimestre * 3,
                        'obt' => $notes['T1']['total_obt'] + $notes['T2']['total_obt'] + $notes['T3']['total_obt']
                    ]
                ];

                // Ajouter aux totaux
                foreach (['T1', 'T2', 'T3'] as $trimKey) {
                    $bulletin['totaux'][$trimKey]['max'] += $notes[$trimKey]['total_max'];
                    $bulletin['totaux'][$trimKey]['obt'] += $notes[$trimKey]['total_obt'];
                    $groupeData['sous_total'][$trimKey]['max'] += $notes[$trimKey]['total_max'];
                    $groupeData['sous_total'][$trimKey]['obt'] += $notes[$trimKey]['total_obt'];
                }
                $bulletin['totaux']['annuel']['max'] += $smData['max_annuel'];
                $bulletin['totaux']['annuel']['obt'] += $smData['annuel']['obt'];
                $groupeData['sous_total']['annuel']['max'] += $smData['max_annuel'];
                $groupeData['sous_total']['annuel']['obt'] += $smData['annuel']['obt'];

                $groupeData['sous_matieres'][] = $smData;
            }

            $bulletin['groupes'][] = $groupeData;
        }

        // Calculer les pourcentages et mentions
        $stats = [];
        foreach (['T1', 'T2', 'T3', 'annuel'] as $key) {
            $pct = AcademicResultsService::calculatePercentage(
                (float) $bulletin['totaux'][$key]['obt'],
                (float) $bulletin['totaux'][$key]['max']
            );
            $stats[$key] = [
                'pourcentage' => $pct,
                'mention'     => $this->determinerMention($pct),
                'decision'    => $this->determinerDecision($pct)
            ];
        }

        $bulletin['stats'] = $stats;

        jsonResponse(true, 'Bulletin généré.', ['bulletin' => $bulletin]);
    }

    // ============================================================
    // 4. STATISTIQUES D'UNE CLASSE
    // ============================================================

    /**
     * Récupère les statistiques d'une classe pour un trimestre
     */
    public function getStatistiquesClasse(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $trimestreValide = $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM classes c
             INNER JOIN trimestres t ON t.annee_scolaire_id = c.annee_scolaire_id
             WHERE c.id = :cid AND t.id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );
        if ((int) $trimestreValide !== 1) {
            jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
        }

        $stats = [
            'effectif'        => 0,
            'admis'           => 0,
            'ajournes'        => 0,
            'taux_reussite'   => 0,
            'moyenne_classe'  => 0,
            'moyenne_haute'   => 0,
            'moyenne_basse'   => 0,
            'mentions'        => []
        ];

        $resultats = $this->db->fetchAll(
            "SELECT moyenne_generale, mention, decision
             FROM resultats_trimestriels
             WHERE classe_id = :cid AND trimestre_id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );

        if (!empty($resultats)) {
            $stats['effectif'] = count($resultats);
            $stats['admis'] = count(array_filter($resultats, fn($r) => $r['decision'] === 'Passe'));
            $stats['ajournes'] = $stats['effectif'] - $stats['admis'];
            $stats['taux_reussite'] = $stats['effectif'] > 0 
                ? round(($stats['admis'] / $stats['effectif']) * 100, 1) : 0;

            $moyennes = array_column($resultats, 'moyenne_generale');
            $stats['moyenne_classe'] = round(array_sum($moyennes) / count($moyennes), 1);
            $stats['moyenne_haute'] = max($moyennes);
            $stats['moyenne_basse'] = min($moyennes);

            // Compter les mentions
            foreach ($resultats as $r) {
                $mention = $r['mention'];
                if (!isset($stats['mentions'][$mention])) {
                    $stats['mentions'][$mention] = 0;
                }
                $stats['mentions'][$mention]++;
            }
        }

        jsonResponse(true, 'Statistiques récupérées.', ['stats' => $stats]);
    }

    // ============================================================
    // 5. MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Calcule les notes d'une sous-matière pour un trimestre
     */
    private function calculerNotesTrimestre(
        int $eleveId,
        int $classeId,
        int $sousMatiereId,
        int $trimestreId,
        float $maxPeriode
    ): array {
        return $this->academicResults->calculateSubjectQuarter(
            $eleveId,
            $classeId,
            $sousMatiereId,
            $trimestreId,
            $maxPeriode
        );
    }

    /**
     * Détermine la mention selon le pourcentage
     */
    public function determinerMention(float $pourcentage): string
    {
        if ($pourcentage >= 90) return 'Excellent';
        if ($pourcentage >= 80) return 'Très Bien';
        if ($pourcentage >= 70) return 'Bien';
        if ($pourcentage >= 60) return 'Assez Bien';
        if ($pourcentage >= 50) return 'Passable';
        return 'Insuffisant';
    }

    /**
     * Détermine la décision selon le pourcentage
     */
    public function determinerDecision(float $pourcentage): string
    {
        return $pourcentage >= 50 ? 'Passe' : 'Ajourné';
    }

    // ============================================================
    // 6. MÉTHODES PUBLIQUES (pour les vues)
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
     * Récupère les années scolaires
     */
    public function getAnneesScolaires(): array
    {
        return $this->db->fetchAll("SELECT * FROM annees_scolaires ORDER BY date_debut DESC");
    }

    /**
     * Récupère un bulletin par ID
     */
    public function getBulletinById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT b.*, e.matricule, e.nom, e.prenom, e.sexe,
                    c.nom AS classe_nom, t.libelle AS trimestre_libelle,
                    DATE_FORMAT(b.date_edition, '%d/%m/%Y %H:%i') AS date_edition_format
             FROM bulletins b
             INNER JOIN eleves e ON b.eleve_id = e.id
             INNER JOIN trimestres t ON b.trimestre_id = t.id
             INNER JOIN inscriptions i ON e.id = i.eleve_id
                AND i.statut = 'Actif' AND i.annee_scolaire_id = t.annee_scolaire_id
             INNER JOIN classes c ON i.classe_id = c.id
                AND c.annee_scolaire_id = i.annee_scolaire_id
             WHERE b.id = :id",
            ['id' => $id]
        );
    }
}