<?php
/**
 * Fichier : src/controllers/ResultatController.php
 * Rôle    : Calcul des résultats, moyennes, rangs, mentions, décisions
 * Système : Bulletin congolais — 6 périodes + 3 examens + 3 trimestres + total annuel
 * Version : 1.0
 */

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/AcademicResultsService.php';
require_once __DIR__ . '/../services/ResultsBulletinService.php';
require_once __DIR__ . '/../policies/StudentAccessPolicy.php';

class ResultatController
{
    private Database $db;
    private AcademicResultsService $academicResults;
    private ResultsBulletinService $resultsBulletinService;
    private StudentAccessPolicy $studentAccessPolicy;
    private array $bulletinPlacesCache = [];

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
    /**
 * Point d'entrée pour les requêtes AJAX
 */
public function handleAjax(): void
{
    if (!can('resultats.consulter')) {
        jsonResponse(false, 'Accès refusé.', [], 403);
    }

    if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Requête invalide.', [], 400);
    }

    $action = post('action', '');

    switch ($action) {
        case 'getResultatsEleve':
            $this->getResultatsEleve();
            break;
        case 'getProcesVerbal':
            $this->getProcesVerbal();
            break;
        case 'calculerResultatsClasse':
            $this->calculerResultatsClasse();
            break;
        case 'getDeliberations':
            $this->getDeliberations();
            break;
        case 'sauvegarderDeliberation':
            $this->sauvegarderDeliberation();
            break;
        case 'getProclamationPeriode':
            $this->getProclamationPeriode();
            break;
        default:
            jsonResponse(false, 'Action inconnue.', [], 400);
    }
}

    // ============================================================
    // 1. RÉSULTATS D'UN ÉLÈVE (BULLETIN COMPLET)
    // ============================================================

    /**
     * Récupère les résultats complets d'un élève pour une année scolaire
     */
    public function getResultatsEleve(): void
    {
        $eleveId  = (int) post('eleve_id', 0);
        $anneeId  = (int) post('annee_id', 0);

        if ($eleveId <= 0) jsonResponse(false, 'ID élève invalide.', [], 400);

        if (!$this->studentAccessPolicy->canCurrentUserAccessStudent($eleveId)) {
            jsonResponse(false, 'Élève introuvable.', [], 404);
        }

        // Si pas d'année spécifiée, prendre l'année active
        if ($anneeId <= 0) {
            $anneeId = $this->resultsBulletinService->getActiveSchoolYearId();
        }

        // Récupérer les infos de l'élève
        $eleve = $this->resultsBulletinService->getStudentForResults($eleveId, $anneeId);

        if (!$eleve) jsonResponse(false, 'Élève introuvable ou non inscrit.', [], 404);

        // Récupérer les trimestres
        $trimestresParNumero = $this->academicResults->getTrimestresByYear($anneeId);
        $trimestres = array_values($trimestresParNumero);
        $groupes = $this->academicResults->getSubjectGroupsForClass((int) $eleve['classe_id']);

        // Construire les résultats par groupe
        $resultats = [];
        $totauxGeneraux = [
            'T1' => ['max' => 0, 'obt' => 0, 'p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
            'T2' => ['max' => 0, 'obt' => 0, 'p3' => ['max' => 0, 'obt' => 0], 'p4' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
            'T3' => ['max' => 0, 'obt' => 0, 'p5' => ['max' => 0, 'obt' => 0], 'p6' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
            'annuel' => ['max' => 0, 'obt' => 0]
        ];

        foreach ($groupes as $groupe) {
            $groupeData = [
                'id'          => $groupe['id'],
                'libelle'     => $groupe['libelle'],
                'code'        => $groupe['code'],
                'sous_matieres' => [],
                'sous_total'  => [
                    'T1' => ['max' => 0, 'obt' => 0, 'p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                    'T2' => ['max' => 0, 'obt' => 0, 'p3' => ['max' => 0, 'obt' => 0], 'p4' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                    'T3' => ['max' => 0, 'obt' => 0, 'p5' => ['max' => 0, 'obt' => 0], 'p6' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0]],
                    'annuel' => ['max' => 0, 'obt' => 0]
                ]
            ];

            foreach ($groupe['sous_matieres'] as $sm) {
                $maxPeriode = (float) $sm['maxima_periode'];
                $maxExamen  = $maxPeriode * 2;
                $maxTrimestre = $maxPeriode * 4; // P1 + P2 + Ex(2×)

                $trimestre1Id = (int) ($trimestresParNumero[1]['id'] ?? 0);
                $trimestre2Id = (int) ($trimestresParNumero[2]['id'] ?? 0);
                $trimestre3Id = (int) ($trimestresParNumero[3]['id'] ?? 0);
                $smData = [
                    'id'          => $sm['id'],
                    'libelle'     => $sm['libelle'],
                    'code'        => $sm['code'],
                    'max_periode' => $maxPeriode,
                    'max_examen'  => $maxExamen,
                    'max_trimestre' => $maxTrimestre,
                    'max_annuel'  => $maxTrimestre * 3,
                    'T1' => $trimestre1Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre1Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                    'T2' => $trimestre2Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre2Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                    'T3' => $trimestre3Id > 0 ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre3Id, $maxPeriode) : AcademicResultsService::emptySubjectQuarter($maxPeriode),
                ];

                // Calculer le total annuel
                $smData['annuel'] = [
                    'max' => $smData['max_annuel'],
                    'obt' => $smData['T1']['total_obt'] + $smData['T2']['total_obt'] + $smData['T3']['total_obt']
                ];

                // Ajouter aux sous-totaux du groupe
                foreach (['T1', 'T2', 'T3'] as $trimKey) {
                    foreach (['p1', 'p2', 'ex'] as $perKey) {
                        if ($trimKey === 'T1') $key = $perKey;
                        elseif ($trimKey === 'T2') $key = ($perKey === 'p1' ? 'p3' : ($perKey === 'p2' ? 'p4' : 'ex'));
                        else $key = ($perKey === 'p1' ? 'p5' : ($perKey === 'p2' ? 'p6' : 'ex'));

                        if (isset($smData[$trimKey][$perKey])) {
                            $groupeData['sous_total'][$trimKey][$key]['max'] += $smData[$trimKey][$perKey]['max'];
                            $groupeData['sous_total'][$trimKey][$key]['obt'] += $smData[$trimKey][$perKey]['obt'];
                            $totauxGeneraux[$trimKey][$key]['max'] += $smData[$trimKey][$perKey]['max'];
                            $totauxGeneraux[$trimKey][$key]['obt'] += $smData[$trimKey][$perKey]['obt'];
                        }
                    }
                    $groupeData['sous_total'][$trimKey]['max'] += $smData[$trimKey]['total_max'];
                    $groupeData['sous_total'][$trimKey]['obt'] += $smData[$trimKey]['total_obt'];
                    $totauxGeneraux[$trimKey]['max'] += $smData[$trimKey]['total_max'];
                    $totauxGeneraux[$trimKey]['obt'] += $smData[$trimKey]['total_obt'];
                }
                $groupeData['sous_total']['annuel']['max'] += $smData['max_annuel'];
                $groupeData['sous_total']['annuel']['obt'] += $smData['annuel']['obt'];
                $totauxGeneraux['annuel']['max'] += $smData['max_annuel'];
                $totauxGeneraux['annuel']['obt'] += $smData['annuel']['obt'];

                $groupeData['sous_matieres'][] = $smData;
            }

            $resultats[] = $groupeData;
        }

        // Calculer les pourcentages et mentions
        $stats = [];
        foreach (['T1', 'T2', 'T3', 'annuel'] as $key) {
            $pct = AcademicResultsService::calculatePercentage(
                (float) $totauxGeneraux[$key]['obt'],
                (float) $totauxGeneraux[$key]['max']
            );
            $stats[$key] = [
                'max'         => $totauxGeneraux[$key]['max'],
                'obt'         => $totauxGeneraux[$key]['obt'],
                'pourcentage' => $pct,
                'mention'     => $this->determinerMention($pct),
                'decision'    => $this->determinerDecision($pct)
            ];
        }

        // Récupérer les rangs depuis la table resultats_trimestriels
        $rangs = $this->getRangsEleve($eleveId, $anneeId);

        jsonResponse(true, 'Résultats récupérés.', [
            'eleve'      => $eleve,
            'trimestres' => $trimestres,
            'resultats'  => $resultats,
            'totaux'     => $totauxGeneraux,
            'stats'      => $stats,
            'rangs'      => $rangs
        ]);
    }

    // ============================================================
    // 2. CALCUL DES NOTES D'UNE SOUS-MATIÈRE POUR UN TRIMESTRE
    // ============================================================

    /**
     * Calcule P1, P2, Examen et Total pour un trimestre
     * Trimestre 1 = Période 1 + Période 2 + Examen 1
     * Trimestre 2 = Période 3 + Période 4 + Examen 2
     * Trimestre 3 = Période 5 + Période 6 + Examen 3
     */
    /**
 * Récupère la note d'un élève pour une sous-matière, un trimestre et un type d'évaluation
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

    // ============================================================
    // 3. PROCÈS-VERBAL (tous les élèves d'une classe)
    // ============================================================

    /**
     * Récupère le procès-verbal d'une classe pour un trimestre
     */
    public function getProcesVerbal(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $trimestre = $this->resultsBulletinService->fetchOne(
            "SELECT t.*
             FROM trimestres t
             INNER JOIN classes c ON c.id = :cid AND c.annee_scolaire_id = t.annee_scolaire_id
             WHERE t.id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );
        if (!$trimestre) {
            jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
        }

        $data = $this->getResultatsPeriode($classeId, $trimestreId, 'Trimestre');
        $resultats = $data['resultats'];
        $effectif = $data['effectif'];

        jsonResponse(true, 'Procès-verbal récupéré.', [
            'classe'    => $this->resultsBulletinService->fetchOne("SELECT * FROM classes WHERE id = :id", ['id' => $classeId]),
            'trimestre' => $trimestre,
            'resultats' => $resultats,
            'effectif'  => $effectif
        ]);
    }

    // ============================================================
    // 4. CALCUL ET STOCKAGE DES RÉSULTATS D'UNE CLASSE
    // ============================================================

    /**
     * Calcule et stocke les résultats de tous les élèves d'une classe pour un trimestre
     */
    public function calculerResultatsClasse(): void
    {
        requirePermission('resultats.modifier');

        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $trimestre = $this->resultsBulletinService->fetchOne(
            "SELECT t.*
             FROM trimestres t
             INNER JOIN classes c ON c.id = :cid AND c.annee_scolaire_id = t.annee_scolaire_id
             WHERE t.id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );
        if (!$trimestre) {
            jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
        }

        $eleves = $this->resultsBulletinService->fetchAll(
            "SELECT e.id FROM inscriptions i
             INNER JOIN eleves e ON i.eleve_id = e.id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
               AND i.statut = 'Actif' AND e.est_actif = 1",
            ['cid' => $classeId, 'aid' => $trimestre['annee_scolaire_id']]
        );

        $groupes = $this->academicResults->getSubjectGroupsForClass($classeId);
        $sauvegardes = 0;

        try {
            $this->db->beginTransaction();

            foreach ($eleves as $eleve) {
                $totalMax = 0;
                $totalObt = 0;

                foreach ($groupes as $groupe) {
                    foreach ($groupe['sous_matieres'] as $sm) {
                        $maxPeriode = (float) $sm['maxima_periode'];
                        $notes = $this->calculerNotesTrimestre(
                            (int) $eleve['id'],
                            $classeId,
                            (int) $sm['id'],
                            $trimestreId,
                            $maxPeriode
                        );
                        $totalMax += $notes['total_max'];
                        $totalObt += $notes['total_obt'];
                    }
                }

                $pct = AcademicResultsService::calculatePercentage($totalObt, $totalMax);
                $mention = $this->determinerMention($pct);
                $decision = $this->determinerDecision($pct);

                // Supprimer l'ancien résultat
                $this->resultsBulletinService->delete(
                    "DELETE FROM resultats_trimestriels
                     WHERE eleve_id = :eid AND classe_id = :cid AND trimestre_id = :tid",
                    ['eid' => $eleve['id'], 'cid' => $classeId, 'tid' => $trimestreId]
                );

                // Remplacez l'insertion SQL dans calculerResultatsClasse() par :

                $this->resultsBulletinService->insert(
                    "INSERT INTO resultats_trimestriels (eleve_id, classe_id, trimestre_id, moyenne_generale, 
                    total_points, total_coefficients, mention, decision)
                    VALUES (:eid, :cid, :tid, :pct, :obt, :max, :mention, :decision)",
                    [
                        'eid'      => $eleve['id'],
                        'cid'      => $classeId,
                        'tid'      => $trimestreId,
                        'pct'      => $pct,
                        'obt'      => $totalObt, // Point obtenus -> total_points
                        'max'      => $totalMax, // Maxima -> total_coefficients
                        'mention'  => $mention,
                        'decision' => $decision
                    ]
                );


                $sauvegardes++;
            }

            // Calculer les rangs
            $this->calculerRangs($classeId, $trimestreId);

            $this->db->commit();

            logActivity('Calcul', 'resultats_trimestriels', null, "Résultats calculés pour classe {$classeId}, trimestre {$trimestreId}");

            jsonResponse(true, "{$sauvegardes} résultat(s) calculé(s) et sauvegardé(s).");

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Erreur calcul résultats: ' . $e->getMessage());
            jsonResponse(false, 'Erreur lors du calcul des résultats.');
        }
    }

    // ============================================================
    // 5. DÉLIBÉRATIONS
    // ============================================================

    /**
     * Récupère les résultats pour les délibérations
     */
    public function getDeliberations(): void
    {
        $classeId    = (int) post('classe_id', 0);
        $trimestreId = (int) post('trimestre_id', 0);

        if ($classeId <= 0 || $trimestreId <= 0) {
            jsonResponse(false, 'Classe et trimestre obligatoires.', [], 400);
        }

        $trimestreValide = $this->resultsBulletinService->fetchColumn(
            "SELECT COUNT(*)
             FROM classes c
             INNER JOIN trimestres t ON t.annee_scolaire_id = c.annee_scolaire_id
             WHERE c.id = :cid AND t.id = :tid",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );
        if ((int) $trimestreValide !== 1) {
            jsonResponse(false, 'Le trimestre ne correspond pas à l’année scolaire de cette classe.', [], 422);
        }

        $resultats = $this->resultsBulletinService->fetchAll(
            "SELECT rt.*, e.matricule, e.nom, e.prenom, e.sexe,
                    DATE_FORMAT(e.date_naissance, '%d/%m/%Y') AS date_naissance_format
             FROM resultats_trimestriels rt
             INNER JOIN eleves e ON rt.eleve_id = e.id
             WHERE rt.classe_id = :cid AND rt.trimestre_id = :tid
             ORDER BY rt.rang ASC",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );

        jsonResponse(true, 'Délibérations récupérées.', [
            'resultats' => $resultats
        ]);
    }

    /**
     * Sauvegarde une décision de délibération
     */
    public function sauvegarderDeliberation(): void
    {
        requirePermission('resultats.modifier');

        $resultatId   = (int) post('resultat_id', 0);
        $decision     = post('decision', '');
        $appreciation = post('appreciation', '');
        $decisionFinale = (int) post('decision_finale', 0);

        if ($resultatId <= 0) jsonResponse(false, 'ID résultat invalide.', [], 400);

        $this->resultsBulletinService->update(
            "UPDATE resultats_trimestriels 
             SET decision = :decision, appreciation_conseil = :appreciation, decision_finale = :finale
             WHERE id = :id",
            [
                'decision'     => $decision,
                'appreciation' => $appreciation,
                'finale'       => $decisionFinale,
                'id'           => $resultatId
            ]
        );

        jsonResponse(true, 'Délibération sauvegardée.');
    }

    // ============================================================
    // 6. MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Calcule les rangs pour une classe et un trimestre
     */
    private function calculerRangs(int $classeId, int $trimestreId): void
    {
        $resultats = $this->resultsBulletinService->fetchAll(
            "SELECT id, moyenne_generale FROM resultats_trimestriels 
             WHERE classe_id = :cid AND trimestre_id = :tid 
             ORDER BY moyenne_generale DESC",
            ['cid' => $classeId, 'tid' => $trimestreId]
        );

        $effectif = count($resultats);

        foreach ($resultats as $index => $r) {
            $this->resultsBulletinService->update(
                "UPDATE resultats_trimestriels SET rang = :rang, effectif_classe = :eff WHERE id = :id",
                ['rang' => $index + 1, 'eff' => $effectif, 'id' => $r['id']]
            );
        }
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

    /**
     * Récupère les rangs d'un élève
     */
    private function getRangsEleve(int $eleveId, int $anneeId): array
    {
        $rangs = [];
        $classeId = (int) $this->resultsBulletinService->fetchColumn(
            "SELECT i.classe_id
             FROM inscriptions i
             INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id
             WHERE i.eleve_id = :eid AND i.annee_scolaire_id = :aid AND i.statut = 'Actif'
             LIMIT 1",
            ['eid' => $eleveId, 'aid' => $anneeId]
        );
        if ($classeId <= 0) {
            return $rangs;
        }

        $trimestres = $this->resultsBulletinService->fetchAll(
            "SELECT id, numero, libelle FROM trimestres WHERE annee_scolaire_id = :aid ORDER BY numero",
            ['aid' => $anneeId]
        );

        foreach ($trimestres as $trimestre) {
            $resultat = $this->resultsBulletinService->fetchOne(
                "SELECT rang, effectif_classe, moyenne_generale, mention, decision
                 FROM resultats_trimestriels 
                 WHERE eleve_id = :eid AND classe_id = :cid AND trimestre_id = :tid",
                ['eid' => $eleveId, 'cid' => $classeId, 'tid' => $trimestre['id']]
            );

            $rangs['T' . $trimestre['numero']] = $resultat ?: null;
        }

        return $rangs;
    }

    // ============================================================
    // 7. MÉTHODES PUBLIQUES (pour les vues)
    // ============================================================

    /**
     * Récupère les classes de l'année active
     */
    public function getClassesActives(): array
    {
        return $this->resultsBulletinService->fetchAll(
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
        return $this->resultsBulletinService->fetchAll(
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
        return $this->resultsBulletinService->fetchAll("SELECT * FROM annees_scolaires ORDER BY date_debut DESC");
    }



    /**
 * Calcule les pourcentages détaillés pour le bulletin
 * Retourne : %P1, %P2, %Ex, %Trimestre, %Périodes, %Examens, %Annuel
 */
public function getResultatsCompletsEleve(int $eleveId, int $anneeId): array
{
    // Récupérer les infos de l'élève
    $eleve = $this->resultsBulletinService->fetchOne(
        "SELECT e.*, c.nom AS classe_nom, c.niveau AS classe_niveau, c.id AS classe_id
         FROM eleves e
         INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
         INNER JOIN classes c ON i.classe_id = c.id AND c.annee_scolaire_id = i.annee_scolaire_id
         WHERE e.id = :eid AND i.annee_scolaire_id = :aid",
        ['eid' => $eleveId, 'aid' => $anneeId]
    );

    if (!$eleve) return [];

    $trimestresParNumero = $this->academicResults->getTrimestresByYear($anneeId);
    $groupes = $this->academicResults->getSubjectGroupsForClass((int) $eleve['classe_id']);

    $resultats = [];
    $totaux = [
        'T1' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
        'T2' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
        'T3' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
        'annuel' => ['max' => 0, 'obt' => 0],
        'periodes' => ['max' => 0, 'obt' => 0],
        'examens' => ['max' => 0, 'obt' => 0]
    ];

    foreach ($groupes as $groupe) {
        $groupeData = [
            'libelle'       => $groupe['libelle'],
            'code'          => $groupe['code'],
            'sous_matieres' => [],
            'sous_total'    => [
                'T1' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
                'T2' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
                'T3' => ['p1' => ['max' => 0, 'obt' => 0], 'p2' => ['max' => 0, 'obt' => 0], 'ex' => ['max' => 0, 'obt' => 0], 'total' => ['max' => 0, 'obt' => 0]],
                'annuel' => ['max' => 0, 'obt' => 0]
            ]
        ];

        foreach ($groupe['sous_matieres'] as $sm) {
            $maxPeriode = (float) $sm['maxima_periode'];
            $maxExamen  = $maxPeriode * 2;
            $maxTrimestre = ($maxPeriode * 2) + $maxExamen;

            $trimestre1Id = (int) ($trimestresParNumero[1]['id'] ?? 0);
            $trimestre2Id = (int) ($trimestresParNumero[2]['id'] ?? 0);
            $trimestre3Id = (int) ($trimestresParNumero[3]['id'] ?? 0);
            $t1 = $trimestre1Id > 0
                ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre1Id, $maxPeriode)
                : AcademicResultsService::emptySubjectQuarter($maxPeriode);
            $t2 = $trimestre2Id > 0
                ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre2Id, $maxPeriode)
                : AcademicResultsService::emptySubjectQuarter($maxPeriode);
            $t3 = $trimestre3Id > 0
                ? $this->calculerNotesTrimestre($eleveId, (int) $eleve['classe_id'], (int) $sm['id'], $trimestre3Id, $maxPeriode)
                : AcademicResultsService::emptySubjectQuarter($maxPeriode);

            $smData = [
                'libelle'       => $sm['libelle'],
                'code'          => $sm['code'],
                'max_periode'   => $maxPeriode,
                'max_examen'    => $maxExamen,
                'max_trimestre' => $maxTrimestre,
                'max_annuel'    => $maxTrimestre * 3,
                'T1' => $t1,
                'T2' => $t2,
                'T3' => $t3,
                'annuel' => ['max' => $maxTrimestre * 3, 'obt' => $t1['total_obt'] + $t2['total_obt'] + $t3['total_obt']]
            ];

            // Ajouter aux sous-totaux du groupe
            foreach (['T1', 'T2', 'T3'] as $trimKey) {
                $groupeData['sous_total'][$trimKey]['p1']['max'] += $smData[$trimKey]['p1']['max'];
                $groupeData['sous_total'][$trimKey]['p1']['obt'] += $smData[$trimKey]['p1']['obt'];
                $groupeData['sous_total'][$trimKey]['p2']['max'] += $smData[$trimKey]['p2']['max'];
                $groupeData['sous_total'][$trimKey]['p2']['obt'] += $smData[$trimKey]['p2']['obt'];
                $groupeData['sous_total'][$trimKey]['ex']['max'] += $smData[$trimKey]['ex']['max'];
                $groupeData['sous_total'][$trimKey]['ex']['obt'] += $smData[$trimKey]['ex']['obt'];
                $groupeData['sous_total'][$trimKey]['total']['max'] += $smData[$trimKey]['total_max'];
                $groupeData['sous_total'][$trimKey]['total']['obt'] += $smData[$trimKey]['total_obt'];

                $totaux[$trimKey]['p1']['max'] += $smData[$trimKey]['p1']['max'];
                $totaux[$trimKey]['p1']['obt'] += $smData[$trimKey]['p1']['obt'];
                $totaux[$trimKey]['p2']['max'] += $smData[$trimKey]['p2']['max'];
                $totaux[$trimKey]['p2']['obt'] += $smData[$trimKey]['p2']['obt'];
                $totaux[$trimKey]['ex']['max'] += $smData[$trimKey]['ex']['max'];
                $totaux[$trimKey]['ex']['obt'] += $smData[$trimKey]['ex']['obt'];
                $totaux[$trimKey]['total']['max'] += $smData[$trimKey]['total_max'];
                $totaux[$trimKey]['total']['obt'] += $smData[$trimKey]['total_obt'];

                // Accumuler périodes et examens
                $totaux['periodes']['max'] += $smData[$trimKey]['p1']['max'] + $smData[$trimKey]['p2']['max'];
                $totaux['periodes']['obt'] += $smData[$trimKey]['p1']['obt'] + $smData[$trimKey]['p2']['obt'];
                $totaux['examens']['max'] += $smData[$trimKey]['ex']['max'];
                $totaux['examens']['obt'] += $smData[$trimKey]['ex']['obt'];
            }

            $groupeData['sous_total']['annuel']['max'] += $smData['max_annuel'];
            $groupeData['sous_total']['annuel']['obt'] += $smData['annuel']['obt'];
            $totaux['annuel']['max'] += $smData['max_annuel'];
            $totaux['annuel']['obt'] += $smData['annuel']['obt'];

            $groupeData['sous_matieres'][] = $smData;
        }

        $resultats[] = $groupeData;
    }

    // Calculer tous les pourcentages
    $pourcentageT1 = AcademicResultsService::calculatePercentage($totaux['T1']['total']['obt'], $totaux['T1']['total']['max']);
    $pourcentageT2 = AcademicResultsService::calculatePercentage($totaux['T2']['total']['obt'], $totaux['T2']['total']['max']);
    $pourcentageT3 = AcademicResultsService::calculatePercentage($totaux['T3']['total']['obt'], $totaux['T3']['total']['max']);
    $pourcentageAnnuel = AcademicResultsService::calculatePercentage($totaux['annuel']['obt'], $totaux['annuel']['max']);
    $pourcentages = [
        'T1' => [
            'p1' => AcademicResultsService::calculatePercentage($totaux['T1']['p1']['obt'], $totaux['T1']['p1']['max']),
            'p2' => AcademicResultsService::calculatePercentage($totaux['T1']['p2']['obt'], $totaux['T1']['p2']['max']),
            'ex' => AcademicResultsService::calculatePercentage($totaux['T1']['ex']['obt'], $totaux['T1']['ex']['max']),
            'p1_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T1']['p1']['obt'], $totaux['T1']['p1']['max'])),
            'p2_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T1']['p2']['obt'], $totaux['T1']['p2']['max'])),
            'ex_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T1']['ex']['obt'], $totaux['T1']['ex']['max'])),
            'trimestre' => $pourcentageT1,
            'mention' => $this->determinerMention($pourcentageT1),
            'application' => $this->determinerMention($pourcentageT1),
            'decision' => $this->determinerDecision($pourcentageT1)
        ],
        'T2' => [
            'p1' => AcademicResultsService::calculatePercentage($totaux['T2']['p1']['obt'], $totaux['T2']['p1']['max']),
            'p2' => AcademicResultsService::calculatePercentage($totaux['T2']['p2']['obt'], $totaux['T2']['p2']['max']),
            'ex' => AcademicResultsService::calculatePercentage($totaux['T2']['ex']['obt'], $totaux['T2']['ex']['max']),
            'p1_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T2']['p1']['obt'], $totaux['T2']['p1']['max'])),
            'p2_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T2']['p2']['obt'], $totaux['T2']['p2']['max'])),
            'ex_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T2']['ex']['obt'], $totaux['T2']['ex']['max'])),
            'trimestre' => $pourcentageT2,
            'mention' => $this->determinerMention($pourcentageT2),
            'application' => $this->determinerMention($pourcentageT2),
            'decision' => $this->determinerDecision($pourcentageT2)
        ],
        'T3' => [
            'p1' => AcademicResultsService::calculatePercentage($totaux['T3']['p1']['obt'], $totaux['T3']['p1']['max']),
            'p2' => AcademicResultsService::calculatePercentage($totaux['T3']['p2']['obt'], $totaux['T3']['p2']['max']),
            'ex' => AcademicResultsService::calculatePercentage($totaux['T3']['ex']['obt'], $totaux['T3']['ex']['max']),
            'p1_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T3']['p1']['obt'], $totaux['T3']['p1']['max'])),
            'p2_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T3']['p2']['obt'], $totaux['T3']['p2']['max'])),
            'ex_application' => $this->determinerMention(AcademicResultsService::calculatePercentage($totaux['T3']['ex']['obt'], $totaux['T3']['ex']['max'])),
            'trimestre' => $pourcentageT3,
            'mention' => $this->determinerMention($pourcentageT3),
            'application' => $this->determinerMention($pourcentageT3),
            'decision' => $this->determinerDecision($pourcentageT3)
        ],
        'periodes' => AcademicResultsService::calculatePercentage($totaux['periodes']['obt'], $totaux['periodes']['max']),
        'examens'  => AcademicResultsService::calculatePercentage($totaux['examens']['obt'], $totaux['examens']['max']),
        'annuel'   => [
            'pct'      => $pourcentageAnnuel,
            'mention'  => $this->determinerMention($pourcentageAnnuel),
            'application' => $this->determinerMention($pourcentageAnnuel),
            'decision' => $this->determinerDecision($pourcentageAnnuel)
        ]
    ];

    // Récupérer les rangs
    $rangs = $this->getRangsEleve($eleveId, $anneeId);
    $places = $this->getPlacesParComposante(
        (int) $eleve['classe_id'],
        $anneeId,
        $eleveId,
        $groupes,
        $trimestresParNumero
    );

    return [
        'eleve'        => $eleve,
        'groupes'      => $resultats,
        'totaux'       => $totaux,
        'pourcentages' => $pourcentages,
        'rangs'        => $rangs,
        'places'       => $places
    ];
}

/**
 * Calcule le classement de la classe pour chaque période, examen, trimestre et l'année.
 */
private function getPlacesParComposante(
    int $classeId,
    int $anneeId,
    int $eleveId,
    array $groupes,
    array $trimestresParNumero
): array {
    $cacheKey = $classeId . ':' . $anneeId;
    if (!array_key_exists($cacheKey, $this->bulletinPlacesCache)) {
        $eleves = $this->resultsBulletinService->fetchAll(
            "SELECT DISTINCT e.id, e.nom, e.prenom
             FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
               AND i.statut = 'Actif' AND e.est_actif = 1",
            ['cid' => $classeId, 'aid' => $anneeId]
        );

        $composantes = [];
        foreach ([1, 2, 3] as $numero) {
            $trimKey = 'T' . $numero;
            foreach (['p1', 'p2', 'ex', 'trimestre'] as $composante) {
                $composantes[$trimKey . '.' . $composante] = true;
            }
        }
        $composantes['annuel'] = true;

        $totauxEleves = [];
        $presenceNotes = [];
        foreach ($eleves as $eleve) {
            $id = (int) $eleve['id'];
            $totauxEleves[$id] = [];
            $presenceNotes[$id] = [];
            foreach (array_keys($composantes) as $composante) {
                $totauxEleves[$id][$composante] = ['max' => 0.0, 'obt' => 0.0];
                $presenceNotes[$id][$composante] = false;
            }
        }

        $numeroParTrimestreId = [];
        foreach ($trimestresParNumero as $numero => $trimestre) {
            $numeroParTrimestreId[(int) $trimestre['id']] = (int) $numero;
        }

        $evaluations = $this->resultsBulletinService->fetchAll(
            "SELECT DISTINCT ev.trimestre_id, ev.type_evaluation, n.eleve_id
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             WHERE ev.classe_id = :cid",
            ['cid' => $classeId]
        );
        foreach ($evaluations as $evaluation) {
            $numero = $numeroParTrimestreId[(int) $evaluation['trimestre_id']] ?? 0;
            $eleveNoteId = (int) $evaluation['eleve_id'];
            if ($numero < 1 || !isset($presenceNotes[$eleveNoteId])) {
                continue;
            }

            $composante = match ($evaluation['type_evaluation']) {
                'Période 1' => 'p1',
                'Période 2' => 'p2',
                'Examen' => 'ex',
                default => null,
            };
            if ($composante === null) {
                continue;
            }

            $trimKey = 'T' . $numero;
            $presenceNotes[$eleveNoteId][$trimKey . '.' . $composante] = true;
            $presenceNotes[$eleveNoteId][$trimKey . '.trimestre'] = true;
            $presenceNotes[$eleveNoteId]['annuel'] = true;
        }

        foreach ($eleves as $eleve) {
            $id = (int) $eleve['id'];
            foreach ([1, 2, 3] as $numero) {
                $trimestreId = (int) ($trimestresParNumero[$numero]['id'] ?? 0);
                if ($trimestreId <= 0) {
                    continue;
                }

                $trimKey = 'T' . $numero;
                foreach ($groupes as $groupe) {
                    foreach ($groupe['sous_matieres'] as $matiere) {
                        $notes = $this->academicResults->calculateSubjectQuarter(
                            $id,
                            $classeId,
                            (int) $matiere['id'],
                            $trimestreId,
                            (float) $matiere['maxima_periode']
                        );
                        foreach (['p1', 'p2', 'ex'] as $composante) {
                            $key = $trimKey . '.' . $composante;
                            $totauxEleves[$id][$key]['max'] += $notes[$composante]['max'];
                            $totauxEleves[$id][$key]['obt'] += $notes[$composante]['obt'];
                            $totauxEleves[$id][$trimKey . '.trimestre']['max'] += $notes[$composante]['max'];
                            $totauxEleves[$id][$trimKey . '.trimestre']['obt'] += $notes[$composante]['obt'];
                        }
                    }
                }
            }
        }

        foreach ($totauxEleves as $id => $totaux) {
            $totalAnnuel = ['max' => 0.0, 'obt' => 0.0];
            foreach ([1, 2, 3] as $numero) {
                $key = 'T' . $numero . '.trimestre';
                $totalAnnuel['max'] += $totaux[$key]['max'];
                $totalAnnuel['obt'] += $totaux[$key]['obt'];
            }
            $totauxEleves[$id]['annuel'] = $totalAnnuel;
        }

        $classements = [];
        foreach (array_keys($composantes) as $composante) {
            $elevesNotes = array_values(array_filter(
                $eleves,
                static fn(array $eleve): bool => $presenceNotes[(int) $eleve['id']][$composante] ?? false
            ));

            usort($elevesNotes, static function (array $a, array $b) use ($totauxEleves, $composante): int {
                $scoreA = AcademicResultsService::calculatePercentage(
                    $totauxEleves[(int) $a['id']][$composante]['obt'],
                    $totauxEleves[(int) $a['id']][$composante]['max']
                );
                $scoreB = AcademicResultsService::calculatePercentage(
                    $totauxEleves[(int) $b['id']][$composante]['obt'],
                    $totauxEleves[(int) $b['id']][$composante]['max']
                );
                $scoreOrder = $scoreB <=> $scoreA;
                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                $nameA = mb_strtolower(trim($a['nom'] . ' ' . $a['prenom']));
                $nameB = mb_strtolower(trim($b['nom'] . ' ' . $b['prenom']));
                return strnatcasecmp($nameA, $nameB) ?: ((int) $a['id'] <=> (int) $b['id']);
            });

            $effectif = count($elevesNotes);
            foreach ($elevesNotes as $index => $eleve) {
                $classements[$composante][(int) $eleve['id']] = [
                    'rang' => $index + 1,
                    'effectif_classe' => $effectif,
                ];
            }
        }

        $this->bulletinPlacesCache[$cacheKey] = $classements;
    }

    $placesEleve = [];
    foreach ([1, 2, 3] as $numero) {
        $trimKey = 'T' . $numero;
        foreach (['p1', 'p2', 'ex', 'trimestre'] as $composante) {
            $key = $trimKey . '.' . $composante;
            $placesEleve[$trimKey][$composante] = $this->bulletinPlacesCache[$cacheKey][$key][$eleveId] ?? null;
        }
    }
    $placesEleve['annuel']['pct'] = $this->bulletinPlacesCache[$cacheKey]['annuel'][$eleveId] ?? null;

    return $placesEleve;
}




/**
 * Calcule les résultats annuels de tous les élèves inscrits dans une classe.
 */
public function getResultatsAnnuelsClasse(int $classeId, int $anneeId): array
{
    $classe = $this->resultsBulletinService->fetchOne(
        "SELECT id, annee_scolaire_id FROM classes WHERE id = :cid",
        ['cid' => $classeId]
    );

    $emptyResult = [
        'resultats' => [],
        'effectif' => 0,
        'total_garcons' => 0,
        'total_filles' => 0,
    ];
    if (!$classe || (int) $classe['annee_scolaire_id'] !== $anneeId) {
        return $emptyResult;
    }

    $eleves = $this->resultsBulletinService->fetchAll(
        "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
         FROM inscriptions i
         INNER JOIN eleves e ON i.eleve_id = e.id
         WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
           AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1
         ORDER BY e.nom, e.prenom",
        ['cid' => $classeId, 'aid' => $anneeId]
    );
    if (empty($eleves)) {
        return $emptyResult;
    }

    $trimestres = $this->academicResults->getTrimestresByYear($anneeId);
    $groupes = $this->academicResults->getSubjectGroupsForClass($classeId);
    $trimestreIds = [];
    foreach ([1, 2, 3] as $numero) {
        if (!empty($trimestres[$numero]['id'])) {
            $trimestreIds[] = (int) $trimestres[$numero]['id'];
        }
    }
    $gradeCoverage = $this->academicResults->getAnnualGradeCoverage(
        $classeId,
        $trimestreIds,
        $groupes
    );

    $participationParEleve = [];
    if (!empty($trimestreIds)) {
        $placeholders = [];
        $params = ['cid' => $classeId];
        foreach ($trimestreIds as $index => $trimestreId) {
            $placeholder = 'tid_' . $index;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $trimestreId;
        }
        $participationRows = $this->resultsBulletinService->fetchAll(
            "SELECT n.eleve_id,
                    SUM(CASE WHEN n.absence = 0 THEN 1 ELSE 0 END) AS notes_present,
                    SUM(CASE WHEN n.absence = 1 THEN 1 ELSE 0 END) AS notes_absence
             FROM evaluations ev
             INNER JOIN notes n ON n.evaluation_id = ev.id
             WHERE ev.classe_id = :cid
               AND ev.trimestre_id IN (" . implode(', ', $placeholders) . ")
             GROUP BY n.eleve_id",
            $params
        );
        foreach ($participationRows as $row) {
            if ((int) $row['notes_present'] > 0) {
                $participationParEleve[(int) $row['eleve_id']] = 'present';
            } elseif ((int) $row['notes_absence'] > 0) {
                $participationParEleve[(int) $row['eleve_id']] = 'absent';
            }
        }
    }

    $resultats = [];
    $totalGarcons = 0;
    $totalFilles = 0;
    foreach ($eleves as $eleve) {
        $notesCompletees = (int) ($gradeCoverage['students'][(int) $eleve['id']] ?? 0);
        $notesAttendues = (int) $gradeCoverage['expected'];
        $bulletinComplet = $notesAttendues > 0 && $notesCompletees >= $notesAttendues;
        $totalMaximum = 0.0;
        $totalObtenu = 0.0;
        foreach ($groupes as $groupe) {
            foreach ($groupe['sous_matieres'] as $sousMatiere) {
                $maxPeriode = (float) $sousMatiere['maxima_periode'];
                foreach ([1, 2, 3] as $numero) {
                    $trimestreId = (int) ($trimestres[$numero]['id'] ?? 0);
                    $notesTrimestre = $trimestreId > 0
                        ? $this->calculerNotesTrimestre(
                            (int) $eleve['id'],
                            $classeId,
                            (int) $sousMatiere['id'],
                            $trimestreId,
                            $maxPeriode
                        )
                        : AcademicResultsService::emptySubjectQuarter($maxPeriode);
                    $totalMaximum += $notesTrimestre['total_max'];
                    $totalObtenu += $notesTrimestre['total_obt'];
                }
            }
        }

        $pourcentage = AcademicResultsService::calculatePercentage($totalObtenu, $totalMaximum);
        $mention = $this->determinerMention($pourcentage);
        $decision = AcademicResultsService::determineAnnualDecision(
            $totalObtenu,
            $totalMaximum,
            $bulletinComplet
        );
        $resultats[] = [
            'eleve_id' => (int) $eleve['id'],
            'matricule' => $eleve['matricule'],
            'nom' => $eleve['nom'],
            'prenom' => $eleve['prenom'],
            'sexe' => $eleve['sexe'],
            'total_max' => $totalMaximum,
            'total_obt' => $totalObtenu,
            'pourcentage' => $pourcentage,
            'mention' => $mention,
            'decision' => $decision,
            'is_admis' => $decision === 'Passe',
            'participation' => $participationParEleve[(int) $eleve['id']] ?? 'non_evalue',
            'bulletin_complet' => $bulletinComplet,
            'notes_completees' => $notesCompletees,
            'notes_attendues' => $notesAttendues,
            'taux_completude' => $notesAttendues > 0
                ? round(($notesCompletees / $notesAttendues) * 100, 1)
                : 0.0,
        ];

        if ($eleve['sexe'] === 'M') {
            $totalGarcons++;
        } else {
            $totalFilles++;
        }
    }

    usort($resultats, function (array $a, array $b): int {
        return $this->comparerResultatsPourClassement($a, $b);
    });
    foreach ($resultats as $index => &$resultat) {
        $resultat['rang'] = $index + 1;
        $resultat['effectif'] = count($resultats);
    }
    unset($resultat);

    return [
        'resultats' => $resultats,
        'effectif' => count($resultats),
        'total_garcons' => $totalGarcons,
        'total_filles' => $totalFilles,
    ];
}

/**
 * Calcule les résultats pour une période spécifique (P1 à P6, Examen, Trimestre)
 * 
 * @param int $classeId    ID de la classe
 * @param int $trimestreId ID du trimestre (1, 2, 3)
 * @param string $type     Type : 'Période 1', 'Période 2', 'Période 3', 'Période 4', 'Période 5', 'Période 6', 'Examen', 'Trimestre'
 * @return array
 */
/**
 * Calcule les résultats pour une période spécifique
 */
public function getResultatsPeriode(int $classeId, int $trimestreId, string $type): array
{
    $classe = $this->resultsBulletinService->fetchOne(
        "SELECT id, annee_scolaire_id FROM classes WHERE id = :cid",
        ['cid' => $classeId]
    );
    $assessment = $this->academicResults->resolveAssessment($trimestreId, $type);

    if (
        !$classe
        || !$assessment
        || (int) $assessment['trimestre']['annee_scolaire_id'] !== (int) $classe['annee_scolaire_id']
    ) {
        return [
            'resultats' => [],
            'effectif' => 0,
            'total_garcons' => 0,
            'total_filles' => 0,
            'total_admis' => 0,
            'garcons_admis' => 0,
            'filles_admises' => 0,
            'total_echecs' => 0,
            'garcons_echecs' => 0,
            'filles_echecs' => 0,
            'taux_reussite' => 0,
            'moyenne_classe' => 0,
        ];
    }

    $groupes = $this->academicResults->getSubjectGroupsForClass($classeId);

    $eleves = $this->resultsBulletinService->fetchAll(
        "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
         FROM inscriptions i
         INNER JOIN eleves e ON i.eleve_id = e.id
         WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
           AND i.statut = 'Actif' AND e.est_actif = 1
         ORDER BY e.nom, e.prenom",
        ['cid' => $classeId, 'aid' => $classe['annee_scolaire_id']]
    );

    if (empty($eleves)) return [];

    $typesEvaluation = $assessment['type'] !== null
        ? [$assessment['type']]
        : ['Période 1', 'Période 2', 'Examen'];
    $evaluationParams = ['tid' => $trimestreId, 'cid' => $classeId];
    $typePlaceholders = [];
    foreach ($typesEvaluation as $index => $evaluationType) {
        $placeholder = 'type_' . $index;
        $typePlaceholders[] = ':' . $placeholder;
        $evaluationParams[$placeholder] = $evaluationType;
    }

    $participationRows = $this->resultsBulletinService->fetchAll(
        "SELECT n.eleve_id, COUNT(*) AS total_notes,
                SUM(CASE WHEN n.absence = 0 THEN 1 ELSE 0 END) AS notes_present,
                SUM(CASE WHEN n.absence = 1 THEN 1 ELSE 0 END) AS notes_absence
         FROM evaluations ev
         INNER JOIN notes n ON n.evaluation_id = ev.id
         WHERE ev.trimestre_id = :tid AND ev.classe_id = :cid
           AND ev.type_evaluation IN (" . implode(', ', $typePlaceholders) . ")
         GROUP BY n.eleve_id",
        $evaluationParams
    );
    $participationParEleve = [];
    foreach ($participationRows as $row) {
        if ((int) $row['notes_present'] > 0) {
            $participationParEleve[(int) $row['eleve_id']] = 'present';
        } elseif ((int) $row['notes_absence'] > 0) {
            $participationParEleve[(int) $row['eleve_id']] = 'absent';
        } else {
            $participationParEleve[(int) $row['eleve_id']] = 'non_evalue';
        }
    }

    $resultats = [];
    $totalGarcons = 0;
    $totalFilles = 0;
    $totalAdmis = 0;
    $totalGarconsAdmis = 0;
    $totalFillesAdmises = 0;

    foreach ($eleves as $eleve) {
        $calcul = $this->academicResults->calculateStudentAssessmentForResolved(
            (int) $eleve['id'],
            $classeId,
            $assessment,
            $groupes
        );

        $totalMax = $calcul['total_max'];
        $totalObt = $calcul['total_obt'];
        $pourcentage = $calcul['pourcentage'];
        $mention = $this->determinerMention($pourcentage);
        $decision = $this->determinerDecision($pourcentage);
        $isAdmis = $decision === 'Passe';

        if ($eleve['sexe'] === 'M') $totalGarcons++;
        else $totalFilles++;

        if ($isAdmis) {
            $totalAdmis++;
            if ($eleve['sexe'] === 'M') $totalGarconsAdmis++;
            else $totalFillesAdmises++;
        }

        $resultats[] = [
            'eleve_id'    => $eleve['id'],
            'matricule'   => $eleve['matricule'],
            'nom'         => $eleve['nom'],
            'prenom'      => $eleve['prenom'],
            'sexe'        => $eleve['sexe'],
            'total_max'   => $totalMax,
            'total_obt'   => $totalObt,
            'pourcentage' => $pourcentage,
            'mention'     => $mention,
            'decision'    => $decision,
            'is_admis'    => $isAdmis,
            'participation' => $participationParEleve[(int) $eleve['id']] ?? 'non_evalue'
        ];
    }

    usort($resultats, function (array $a, array $b): int {
        return $this->comparerResultatsPourClassement($a, $b);
    });

    $effectif = count($resultats);
    foreach ($resultats as $index => &$r) {
        $r['rang'] = $index + 1;
        $r['effectif'] = $effectif;
    }
    unset($r);

    $totalEchecs = $effectif - $totalAdmis;
    $tauxReussite = $effectif > 0 ? round(($totalAdmis / $effectif) * 100, 1) : 0;
    $moyennes = array_column($resultats, 'pourcentage');
    $moyenneClasse = count($moyennes) > 0 ? round(array_sum($moyennes) / count($moyennes), 1) : 0;

    return [
        'resultats'        => $resultats,
        'effectif'         => $effectif,
        'total_garcons'    => $totalGarcons,
        'total_filles'     => $totalFilles,
        'total_admis'      => $totalAdmis,
        'garcons_admis'    => $totalGarconsAdmis,
        'filles_admises'   => $totalFillesAdmises,
        'total_echecs'     => $totalEchecs,
        'garcons_echecs'   => $totalGarcons - $totalGarconsAdmis,
        'filles_echecs'    => $totalFilles - $totalFillesAdmises,
        'taux_reussite'    => $tauxReussite,
        'moyenne_classe'   => $moyenneClasse
    ];
}

    private function comparerResultatsPourClassement(array $a, array $b): int
    {
        $pourcentageCompare = round((float) $b['pourcentage'], 1) <=> round((float) $a['pourcentage'], 1);
        if ($pourcentageCompare !== 0) {
            return $pourcentageCompare;
        }

        foreach (['nom', 'prenom', 'matricule'] as $champ) {
            $nomCompare = strnatcasecmp((string) ($a[$champ] ?? ''), (string) ($b[$champ] ?? ''));
            if ($nomCompare !== 0) {
                return $nomCompare;
            }
        }

        return (int) ($a['eleve_id'] ?? 0) <=> (int) ($b['eleve_id'] ?? 0);
    }
}