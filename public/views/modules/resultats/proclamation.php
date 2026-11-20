<?php
/**
 * Fichier : public/views/modules/resultats/proclamation.php
 * Rôle    : Liste de proclamation — Classement par ordre de mérite avec statistiques
 * Système : Bulletin congolais — Proclamation officielle RDC
 * Version : 2.0 — Proclamation par période, examen ou trimestre avec filtre intelligent
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('resultats.consulter');

global $db;

// Charger le contrôleur
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'ResultatController.php';
$resultatController = new ResultatController();

// Traitement AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $resultatController->handleAjax();
}

// Paramètres
$classeId = (int) ($_GET['classe_id'] ?? 0);
$trimestreId = (int) ($_GET['trimestre_id'] ?? 0);
$anneeId = (int) ($_GET['annee_id'] ?? 0);
$typeProclamationParam = $_GET['type_proclamation'] ?? '';
$typeProclamation = is_string($typeProclamationParam) ? trim($typeProclamationParam) : '';

if ($anneeId <= 0) {
    $anneeId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
}

$annees = $resultatController->getAnneesScolaires();
$classes = $db->fetchAll(
    "SELECT c.* FROM classes c WHERE c.annee_scolaire_id = :aid ORDER BY c.niveau",
    ['aid' => $anneeId]
);
$trimestres = $db->fetchAll(
    "SELECT t.* FROM trimestres t WHERE t.annee_scolaire_id = :aid ORDER BY t.numero",
    ['aid' => $anneeId]
);

$classeAnneeId = $classeId > 0
    ? (int) $db->fetchColumn("SELECT annee_scolaire_id FROM classes WHERE id = :cid", ['cid' => $classeId])
    : 0;
if ($classeId > 0 && $classeAnneeId !== $anneeId) $classeId = 0;

$trimestreAnneeId = $trimestreId > 0
    ? (int) $db->fetchColumn("SELECT annee_scolaire_id FROM trimestres WHERE id = :tid", ['tid' => $trimestreId])
    : 0;
if ($trimestreId > 0 && $trimestreAnneeId !== $anneeId) $trimestreId = 0;

// Déterminer le numéro du trimestre sélectionné
$trimestreNum = 0;
if ($trimestreId > 0) {
    $trimestreNum = (int) $db->fetchColumn("SELECT numero FROM trimestres WHERE id = :id", ['id' => $trimestreId]);
}

// Types de proclamation disponibles selon le trimestre
$typesProclamation = [];
switch ($trimestreNum) {
    case 1:
        $typesProclamation = [
            'Période 1' => '1ère Période',
            'Période 2' => '2ème Période',
            'Examen'    => 'Examen 1er Trimestre',
            'Trimestre' => '1er Trimestre complet'
        ];
        break;
    case 2:
        $typesProclamation = [
            'Période 3' => '3ème Période',
            'Période 4' => '4ème Période',
            'Examen'    => 'Examen 2ème Trimestre',
            'Trimestre' => '2ème Trimestre complet'
        ];
        break;
    case 3:
        $typesProclamation = [
            'Période 5' => '5ème Période',
            'Période 6' => '6ème Période',
            'Examen'    => 'Examen 3ème Trimestre',
            'Trimestre' => '3ème Trimestre complet'
        ];
        break;
}

if ($trimestreNum > 0) {
    $typesProclamation['Annuel'] = 'Année complète';
} else {
    $typesProclamation = ['Annuel' => 'Année complète'];
}

// Si pas de type sélectionné, prendre le premier disponible
if (empty($typeProclamation) && !empty($typesProclamation)) {
    $typeProclamation = array_key_first($typesProclamation);
}
if (!array_key_exists($typeProclamation, $typesProclamation)) {
    $typeProclamation = array_key_first($typesProclamation) ?? '';
}
$isAnnualProclamation = $typeProclamation === 'Annuel';

// Récupérer les infos
$classe = null;
$trimestre = null;
$annee = null;
$ecole = $db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
$resultats = [];
$resultatsParticipants = [];
$resultatsIncomplets = [];
$resultatsAbsents = [];
$resultatsNonEvalues = [];
$stats = [];
$titulaire = null;

if (
    $classeId > 0
    && ($isAnnualProclamation || $trimestreId > 0)
    && !empty($typeProclamation)
) {
    $classe = $db->fetchOne("SELECT * FROM classes WHERE id = :id", ['id' => $classeId]);
    $annee = $db->fetchOne("SELECT * FROM annees_scolaires WHERE id = :id", ['id' => $anneeId]);
    $trimestre = $trimestreId > 0
        ? $db->fetchOne("SELECT * FROM trimestres WHERE id = :id", ['id' => $trimestreId])
        : null;

    // Récupérer le titulaire
    $titulaire = $db->fetchOne(
        "SELECT CONCAT(e.prenom, ' ', e.nom) AS nom_complet
         FROM enseignants e
         WHERE e.classe_id = :cid AND e.est_actif = 1
         LIMIT 1",
        ['cid' => $classeId]
    );

    // L'annuel additionne les notes des trois trimestres selon la formule du bulletin.
    $dataProclamation = $isAnnualProclamation
        ? $resultatController->getResultatsAnnuelsClasse($classeId, $anneeId)
        : $resultatController->getResultatsPeriode($classeId, $trimestreId, $typeProclamation);
    $resultats = $dataProclamation['resultats'] ?? [];

    foreach ($resultats as $resultat) {
        if ($isAnnualProclamation && empty($resultat['bulletin_complet'])) {
            $resultatsIncomplets[] = $resultat;
        } elseif ($resultat['participation'] === 'present') {
            $resultatsParticipants[] = $resultat;
        } elseif ($resultat['participation'] === 'absent') {
            $resultatsAbsents[] = $resultat;
        } else {
            $resultatsNonEvalues[] = $resultat;
        }
    }

    $admis = array_values(array_filter(
        $resultatsParticipants,
        static fn($resultat) => $resultat['is_admis']
    ));
    $ajournes = array_values(array_filter(
        $resultatsParticipants,
        static fn($resultat) => !$resultat['is_admis']
    ));
    $pourcentagesParticipants = array_column($resultatsParticipants, 'pourcentage');

    $stats = [
        'total'           => $dataProclamation['effectif'] ?? count($resultats),
        'presences'       => count($resultatsParticipants),
        'absents'         => count($resultatsAbsents),
        'non_evalues'     => count($resultatsNonEvalues),
        'garcons'         => $dataProclamation['total_garcons'] ?? 0,
        'filles'          => $dataProclamation['total_filles'] ?? 0,
        'admis'           => count($admis),
        'garcons_admis'   => count(array_filter($admis, static fn($resultat) => $resultat['sexe'] === 'M')),
        'filles_admises'  => count(array_filter($admis, static fn($resultat) => $resultat['sexe'] === 'F')),
        'ajournes'        => count($ajournes),
        'garcons_echecs'  => count(array_filter($ajournes, static fn($resultat) => $resultat['sexe'] === 'M')),
        'filles_echecs'   => count(array_filter($ajournes, static fn($resultat) => $resultat['sexe'] === 'F')),
        'taux_reussite'   => count($resultatsParticipants) > 0
            ? round((count($admis) / count($resultatsParticipants)) * 100, 1)
            : 0,
        'moyenne_classe'  => count($pourcentagesParticipants) > 0
            ? round(array_sum($pourcentagesParticipants) / count($pourcentagesParticipants), 1)
            : 0,
        'moyenne_haute'   => !empty($pourcentagesParticipants) ? max($pourcentagesParticipants) : 0,
        'moyenne_basse'   => !empty($pourcentagesParticipants) ? min($pourcentagesParticipants) : 0,
        'mention_dominante' => '—'
    ];

    foreach ($resultatsParticipants as $index => &$resultat) {
        $resultat['rang_proclamation'] = $index + 1;
    }
    unset($resultat);

    // Mentions
    if (!empty($resultatsParticipants)) {
        $mentions = [];
        foreach ($resultatsParticipants as $r) {
            $m = $r['mention'];
            if (!isset($mentions[$m])) $mentions[$m] = 0;
            $mentions[$m]++;
        }
        arsort($mentions);
        $stats['mention_dominante'] = !empty($mentions) ? array_key_first($mentions) : '—';
    }
}

// Libellé du type de proclamation
$typeLabel = $typesProclamation[$typeProclamation] ?? $typeProclamation;
$province = trim((string) ($ecole['province'] ?? ''));
$provinceLabel = '';
if ($province !== '') {
    $provinceUpper = mb_strtoupper($province);
    if (preg_match('/^(?:PROVINCE|VILLE-PROVINCE)\b/u', $provinceUpper)) {
        $provinceLabel = $provinceUpper;
    } elseif (in_array($provinceUpper, ['NORD-KIVU', 'SUD-KIVU'], true)) {
        $provinceLabel = 'PROVINCE DU ' . $provinceUpper;
    } elseif ($provinceUpper === 'KINSHASA') {
        $provinceLabel = 'VILLE-PROVINCE DE KINSHASA';
    } else {
        $provinceLabel = 'PROVINCE DE ' . $provinceUpper;
    }
}
$villeEcole = trim((string) ($ecole['ville'] ?? ''));
$logoProclamation = !empty($ecole['logo'])
    ? ASSETS_URL . ltrim((string) $ecole['logo'], '/\\')
    : '';
$selectionQuery = http_build_query([
    'classe_id' => $classeId,
    'trimestre_id' => $trimestreId,
    'annee_id' => $anneeId,
    'type_proclamation' => $typeProclamation,
]);
$consultationUrl = APP_BASE_PATH . '/resultats?' . $selectionQuery;
$procesVerbalUrl = APP_BASE_PATH . '/resultats/proces-verbaux?' . $selectionQuery;
$deliberationUrl = APP_BASE_PATH . '/resultats/deliberations?' . $selectionQuery;

$pageTitle = 'Liste de proclamation';
$pageIcon  = 'fa-list-ol';
$pageDescription = 'Liste de proclamation — ' . $typeLabel;
$extraCSS = ['modules/resultats.css'];
$extraJS  = ['modules/resultats.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Fil d'Ariane -->
<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/resultats">Résultats</a>
    <span class="separator">/</span>
    <span class="current">Proclamation</span>
</nav>

<!-- ============================================================
     EN-TÊTE
     ============================================================ -->
<div class="resultats-header">
    <div class="resultats-header-left">
        <h4>
            <i class="fa-solid fa-list-ol"></i> Liste de proclamation
        </h4>
        <p>Liste de proclamation officielle — Classement par ordre de mérite.</p>
    </div>
    <div class="resultats-header-right">
        <a href="<?= e(APP_BASE_PATH) ?>/resultats" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Résultats
        </a>
        <?php if (!empty($resultats)): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimer la proclamation
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     MESSAGE FLASH
     ============================================================ -->
<div id="flashMessage"></div>

<!-- ============================================================
     SÉLECTEURS
     ============================================================ -->
<div class="resultats-selectors no-print">
    <div class="resultats-selector-card">
        <div class="resultats-selector-header">
            <i class="fa-solid fa-filter"></i> Sélectionner la classe, le trimestre et le type
        </div>
        <div class="resultats-selector-body">
            <form method="GET" action="" class="resultats-selector-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="classe_id" class="form-label">
                            <i class="fa-solid fa-chalkboard"></i> Classe
                        </label>
                        <select id="classe_id" name="classe_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Sélectionnez une classe —</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $classeId === (int) $cl['id'] ? 'selected' : '' ?>>
                                    <?= e($cl['nom']) ?> (Niv. <?= $cl['niveau'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="trimestre_id" class="form-label">
                            <i class="fa-solid fa-calendar-alt"></i> Trimestre
                        </label>
                        <select id="trimestre_id" name="trimestre_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Sélectionnez un trimestre —</option>
                            <?php foreach ($trimestres as $trim): ?>
                                <option value="<?= $trim['id'] ?>" <?= $trimestreId === (int) $trim['id'] ? 'selected' : '' ?>>
                                    <?= e($trim['libelle']) ?> <?= $trim['est_actif'] ? '(Actif)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="type_proclamation" class="form-label">
                            <i class="fa-solid fa-tag"></i> Type
                        </label>
                        <select id="type_proclamation" name="type_proclamation" class="form-control" onchange="this.form.submit()" <?= empty($typesProclamation) ? 'disabled' : '' ?>>
                            <?php if (empty($typesProclamation)): ?>
                                <option value="">— Sélectionnez une année —</option>
                            <?php else: ?>
                                <?php foreach ($typesProclamation as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $typeProclamation === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="annee_id" class="form-label">
                            <i class="fa-solid fa-calendar"></i> Année
                        </label>
                        <select id="annee_id" name="annee_id" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($annees as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $anneeId === (int) $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['libelle']) ?> <?= $a['est_active'] ? '(Active)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     LISTE DE PROCLAMATION
     ============================================================ -->
<?php if ($classeId <= 0 || (!$isAnnualProclamation && $trimestreId <= 0)): ?>
    <div class="empty-state" style="padding:60px 20px;background:#fff;border-radius:16px;border:1px solid var(--border-color);">
        <i class="fa-solid fa-list-ol"></i>
        <p>Sélectionnez une classe et une année; le trimestre est requis uniquement pour une proclamation trimestrielle.</p>
    </div>

<?php elseif (empty($resultats)): ?>
    <div class="resultats-non-calcules">
        <div class="resultats-non-calcules-icon">
            <i class="fa-solid fa-info-circle"></i>
        </div>
        <div>
            <h5>Aucun résultat trouvé</h5>
            <p><?= $isAnnualProclamation
                ? 'Aucun élève actif n’est inscrit dans cette classe pour l’année sélectionnée.'
                : 'Les notes pour cette période n’ont pas encore été saisies ou les résultats n’ont pas été calculés.' ?></p>
            <?php if (!$isAnnualProclamation): ?>
                <a href="<?= e(APP_BASE_PATH) ?>/resultats?<?= e($selectionQuery) ?>" class="btn btn-primary btn-sm mt-3">
                    <i class="fa-solid fa-calculator"></i> Calculer les résultats
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Document de proclamation -->
    <div class="proclamation-document" id="proclamationDocument">
        <header class="proclamation-header">
            <div class="proclamation-identite">
                <div class="proclamation-logo">
                    <?php if ($logoProclamation !== ''): ?>
                        <img src="<?= e($logoProclamation) ?>" alt="Logo de l’établissement">
                    <?php endif; ?>
                </div>
                <div class="proclamation-autorites">
                    <div class="proclamation-pays"><?= e($ecole['pays'] ?? 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO') ?></div>
                    <div class="proclamation-ministere">
                        <?= e($ecole['ministere'] ?? "MINISTÈRE DE L'ENSEIGNEMENT PRIMAIRE, SECONDAIRE ET PROFESSIONNEL") ?>
                    </div>
                    <div class="proclamation-province">
                        <?= $provinceLabel !== '' ? e($provinceLabel) : 'PROVINCE NON RENSEIGNÉE' ?>
                    </div>
                    <div class="proclamation-localite">
                        <?= e($ecole['ville'] ?? '') ?>
                        <?php if (!empty($ecole['commune_territoire'])): ?>
                            · <?= e($ecole['commune_territoire']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <svg class="proclamation-drapeau" viewBox="0 0 180 120" role="img" aria-label="Drapeau de la République démocratique du Congo">
                    <title>Drapeau de la République démocratique du Congo</title>
                    <rect width="180" height="120" fill="#007FFF"/>
                    <path d="M-12 126 L190 -10" fill="none" stroke="#F7D618" stroke-width="31"/>
                    <path d="M-12 126 L190 -10" fill="none" stroke="#CE1021" stroke-width="19"/>
                    <path d="M34 10 L39 23 L53 23 L42 31 L46 45 L34 37 L22 45 L26 31 L15 23 L29 23 Z" fill="#F7D618"/>
                </svg>
            </div>

            <div class="proclamation-ecole">
                <div><strong>ÉCOLE :</strong> <?= e($ecole['nom_ecole'] ?? '___________________________') ?></div>
                <?php if (!empty($ecole['code_etablissement'])): ?>
                    <div><strong>CODE :</strong> <?= e($ecole['code_etablissement']) ?></div>
                <?php endif; ?>
                <?php if (!empty($ecole['commune_territoire'])): ?>
                    <div><strong>COMMUNE / TERRITOIRE :</strong> <?= e($ecole['commune_territoire']) ?></div>
                <?php endif; ?>
            </div>

            <h1 class="proclamation-titre">LISTE OFFICIELLE DE PROCLAMATION</h1>
            <div class="proclamation-session"><?= e(mb_strtoupper($typeLabel)) ?></div>
            <div class="proclamation-annee">
                <?= e($isAnnualProclamation ? 'ANNÉE COMPLÈTE' : ($trimestre['libelle'] ?? '—')) ?>
                · ANNÉE SCOLAIRE <?= e($annee['libelle'] ?? '—') ?>
            </div>

            <div class="proclamation-infos">
                <div class="proclamation-info-ligne">
                    <span><strong>Classe :</strong> <?= e($classe['nom']) ?> (Niveau <?= (int) $classe['niveau'] ?>)</span>
                    <span><strong>Salle :</strong> <?= e($classe['salle'] ?? '—') ?></span>
                    <span><strong>Titulaire :</strong> <?= e($titulaire['nom_complet'] ?? 'Non assigné') ?></span>
                </div>
            </div>

            <div class="proclamation-synthese">
                <div><span>INSCRITS</span><strong><?= $stats['total'] ?></strong><small>G <?= $stats['garcons'] ?> · F <?= $stats['filles'] ?></small></div>
                <div><span>PRÉSENTS</span><strong><?= $stats['presences'] ?></strong><small>Notes avec présence</small></div>
                <div><span>ABSENTS</span><strong><?= $stats['absents'] ?></strong><small>Absences enregistrées</small></div>
                <div><span>NON ÉVALUÉS</span><strong><?= $stats['non_evalues'] ?></strong><small>Aucune note saisie</small></div>
                <div><span>ADMIS</span><strong><?= $stats['admis'] ?></strong><small>Taux <?= number_format($stats['taux_reussite'], 1) ?>%</small></div>
                <div><span>MOYENNE DE CLASSE</span><strong><?= number_format($stats['moyenne_classe'], 1) ?>%</strong><small>Sur les présents</small></div>
            </div>
        </header>

        <!-- ========== TABLEAU DE PROCLAMATION ========== -->
        <div class="proclamation-table-wrapper">
            <table class="proclamation-table">
                <thead>
                    <tr>
                        <th class="col-numero">CLASSEMENT</th>
                        <th class="col-nom">NOM ET POST-NOM</th>
                        <th class="col-sexe">SEXE</th>
                        <th class="col-pourcentage">POURCENTAGE</th>
                        <th class="col-observation">OBSERVATION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $ligneAjoureeAffichee = false;
                    $observationPrecedente = null;
                    $observations = [
                        '80+' => 'RÉUSSITE — 80 % ET PLUS',
                        '70-80' => 'RÉUSSITE — DE 70 % À MOINS DE 80 %',
                        '60-70' => 'RÉUSSITE — DE 60 % À MOINS DE 70 %',
                        '50-60' => 'RÉUSSITE — DE 50 % À MOINS DE 60 %',
                        '0-50' => 'ÉCHEC — MOINS DE 50 %',
                    ];
                    foreach ($resultatsParticipants as $resultat):
                        $isAdmis = (bool) $resultat['is_admis'];
                        $pourcentage = $resultat['pourcentage'];
                        $niveauObservation = $pourcentage >= 80 ? '80+'
                            : ($pourcentage >= 70 ? '70-80'
                                : ($pourcentage >= 60 ? '60-70'
                                    : ($pourcentage >= 50 ? '50-60' : '0-50')));

                        if (!$isAdmis && !$ligneAjoureeAffichee) {
                            $ligneAjoureeAffichee = true;
                            echo '<tr class="ligne-separatrice"><td colspan="5"><span>DÉBUT DES ÉCHECS — MOINS DE 50 %</span></td></tr>';
                        }
                        $nouveauNiveau = $observationPrecedente !== $niveauObservation;
                    ?>
                        <tr class="proclamation-row <?= $isAdmis ? 'admis' : 'ajourne' ?>">
                            <td class="col-numero"><?= (int) $resultat['rang_proclamation'] ?></td>
                            <td class="col-nom">
                                <strong><?= e(mb_strtoupper($resultat['nom'] . ' ' . $resultat['prenom'])) ?></strong>
                            </td>
                            <td class="col-sexe"><?= $resultat['sexe'] === 'M' ? 'M' : 'F' ?></td>
                            <td class="col-pourcentage">
                                <span class="pourcentage-valeur <?= $isAdmis ? 'admis' : 'faible' ?>"><?= number_format($pourcentage, 1) ?>%</span>
                            </td>
                            <td class="col-observation <?= $nouveauNiveau ? 'observation-seuil' : '' ?>">
                                <?php if ($nouveauNiveau): ?>
                                    <?= e($observations[$niveauObservation]) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                        $observationPrecedente = $niveauObservation;
                    endforeach;
                    ?>

                    <?php if (!empty($resultatsIncomplets) || !empty($resultatsAbsents) || !empty($resultatsNonEvalues)): ?>
                        <tr class="hors-classement-separateur"><td colspan="5">BULLETINS INCOMPLETS, ABSENTS OU NON ÉVALUÉS — HORS CLASSEMENT</td></tr>
                        <?php foreach ($resultatsIncomplets as $resultat): ?>
                            <tr class="proclamation-row hors-classement">
                                <td>—</td>
                                <td class="col-nom"><strong><?= e(mb_strtoupper($resultat['nom'] . ' ' . $resultat['prenom'])) ?></strong></td>
                                <td><?= $resultat['sexe'] === 'M' ? 'M' : 'F' ?></td>
                                <td><?= number_format((float) $resultat['pourcentage'], 1) ?>%</td>
                                <td class="col-observation">Bulletin incomplet : <?= (int) $resultat['notes_completees'] ?>/<?= (int) $resultat['notes_attendues'] ?> notes renseignées</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($resultatsAbsents as $resultat): ?>
                            <tr class="proclamation-row hors-classement">
                                <td>—</td>
                                <td class="col-nom"><strong><?= e(mb_strtoupper($resultat['nom'] . ' ' . $resultat['prenom'])) ?></strong></td>
                                <td><?= $resultat['sexe'] === 'M' ? 'M' : 'F' ?></td>
                                <td>ABS</td>
                                <td class="col-observation">Absent(e) à toutes les évaluations de la session</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($resultatsNonEvalues as $resultat): ?>
                            <tr class="proclamation-row hors-classement">
                                <td>—</td>
                                <td class="col-nom"><strong><?= e(mb_strtoupper($resultat['nom'] . ' ' . $resultat['prenom'])) ?></strong></td>
                                <td><?= $resultat['sexe'] === 'M' ? 'M' : 'F' ?></td>
                                <td>—</td>
                                <td class="col-observation">Notes non encore enregistrées</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="proclamation-bilan">
            <strong>RÉCAPITULATIF</strong>
            <span>Admis : <?= $stats['admis'] ?></span>
            <span>Ajournés : <?= $stats['ajournes'] ?></span>
            <?php if ($isAnnualProclamation): ?>
                <span>Bulletins incomplets : <?= count($resultatsIncomplets) ?></span>
            <?php endif; ?>
            <span>Absents : <?= $stats['absents'] ?></span>
            <span>Non évalués : <?= $stats['non_evalues'] ?></span>
            <span>Taux de réussite : <?= number_format($stats['taux_reussite'], 1) ?> %</span>
            <span>Moyenne : <?= number_format($stats['moyenne_classe'], 1) ?> %</span>
        </div>

        <div class="proclamation-signatures">
            <div class="proclamation-date">Fait à <?= e($villeEcole !== '' ? $villeEcole : '________________') ?>, le <?= date('d/m/Y') ?></div>
            <div class="proclamation-signature-blocks">
                <div class="proclamation-signature-block">
                    <span>L'enseignant titulaire</span>
                    <div class="proclamation-signature-line"></div>
                    <strong class="proclamation-signature-nom"><?= e($titulaire['nom_complet'] ?? '____________________________') ?></strong>
                </div>
                <div class="proclamation-signature-block">
                    <span>Le chef d'établissement</span>
                    <div class="proclamation-signature-line"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== BOUTONS D'ACTION ========== -->
    <div class="proclamation-actions no-print">
        <button type="button" class="btn btn-primary btn-lg" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Imprimer la proclamation
        </button>
        <?php if (!$isAnnualProclamation): ?>
            <a href="<?= e($procesVerbalUrl) ?>" class="btn btn-outline-primary">
                <i class="fa-solid fa-file-contract"></i> Voir le procès-verbal
            </a>
            <a href="<?= e($deliberationUrl) ?>" class="btn btn-outline-primary">
                <i class="fa-solid fa-gavel"></i> Délibérations
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';