<?php
/**
 * Fichier : public/views/parent/modules/bulletins.php
 * Rôle    : Consultation du bulletin officiel congolais — Vue Parent
 * Système : Bulletin avec 6 périodes, 3 examens, 3 trimestres, total annuel
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('bulletins.consulter');

global $db;

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'ResultatController.php';
$resultatController = new ResultatController();

// Récupérer le parent connecté
$userId = $_SESSION['utilisateur_id'] ?? 0;
$parentId = (int) $db->fetchColumn(
    "SELECT pt.id FROM parents_tuteurs pt WHERE pt.utilisateur_id = :uid LIMIT 1",
    ['uid' => $userId]
);

if ($parentId <= 0) {
    setFlash('warning', 'Aucun profil parent trouvé. Contactez l\'administration.');
    redirect('/gestion_scolaire/dashboard');
}

// Récupérer les enfants du parent
$enfants = $db->fetchAll(
    "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
            c.nom AS classe_nom, c.niveau AS classe_niveau, c.id AS classe_id
     FROM eleve_parent ep
     INNER JOIN eleves e ON ep.eleve_id = e.id AND e.est_actif = 1
     LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
     LEFT JOIN classes c ON i.classe_id = c.id
     WHERE ep.parent_id = :pid
     ORDER BY ep.est_tuteur_principal DESC, e.nom",
    ['pid' => $parentId]
);

if (empty($enfants)) {
    setFlash('info', 'Aucun enfant n\'est actuellement lié à votre compte.');
    redirect('/gestion_scolaire/parent/enfants');
}

// Sélectionner l'enfant
$eleveId = (int) ($_GET['enfant_id'] ?? $enfants[0]['id']);
$anneeId = (int) ($_GET['annee_id'] ?? 0);
if ($anneeId <= 0) $anneeId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");

// Vérifier que l'enfant appartient bien au parent
$enfantAutorise = false;
$selectedEnfant = null;
foreach ($enfants as $enf) {
    if ($enf['id'] === $eleveId) {
        $enfantAutorise = true;
        $selectedEnfant = $enf;
        break;
    }
}
if (!$enfantAutorise) $selectedEnfant = $enfants[0];

$ecole = $db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
$annees = $resultatController->getAnneesScolaires();

// Récupérer le bulletin
$bulletin = $resultatController->getResultatsCompletsEleve($selectedEnfant['id'], $anneeId);
$anneeScolaire = $db->fetchOne("SELECT * FROM annees_scolaires WHERE id = :id", ['id' => $anneeId]);

$degre = '';
if ($bulletin && $bulletin['eleve']['classe_niveau'] == 6) $degre = 'DEGRE TERMINAL (6 ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 1 && $bulletin['eleve']['classe_niveau'] <= 2) $degre = 'DEGRÉ ÉLÉMENTAIRE (' . $bulletin['eleve']['classe_niveau'] . ' ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 3 && $bulletin['eleve']['classe_niveau'] <= 4) $degre = 'DEGRÉ MOYEN (' . $bulletin['eleve']['classe_niveau'] . ' ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 5) $degre = 'DEGRÉ TERMINAL (' . $bulletin['eleve']['classe_niveau'] . ' ANNÉE)';

$pageTitle = 'Bulletin — ' . e($selectedEnfant['prenom'] . ' ' . $selectedEnfant['nom']);
$pageIcon  = 'fa-file-invoice';
$pageDescription = 'Bulletin officiel — ' . e($anneeScolaire['libelle'] ?? '');
$extraCSS = ['modules/bulletins.css', 'modules/bulletin-view.css', 'modules/bulletin-print.css'];
$extraJS  = [];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="/gestion_scolaire/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="/gestion_scolaire/parent/enfants">Mes enfants</a>
    <span class="separator">/</span>
    <span class="current">Bulletin — <?= e($selectedEnfant['prenom'] . ' ' . $selectedEnfant['nom']) ?></span>
</nav>

<!-- SÉLECTEURS -->
<div class="bulletin-selectors no-print">
    <div class="bulletin-selector-card">
        <div class="bulletin-selector-header"><i class="fa-solid fa-filter"></i> Sélectionner l'enfant et l'année</div>
        <div class="bulletin-selector-body">
            <form method="GET" action="" class="bulletin-selector-form">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-child"></i> Enfant</label>
                        <select name="enfant_id" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($enfants as $enf): ?>
                                <option value="<?= $enf['id'] ?>" <?= $selectedEnfant['id'] === (int) $enf['id'] ? 'selected' : '' ?>>
                                    <?= e($enf['prenom'] . ' ' . $enf['nom']) ?> — <?= e($enf['classe_nom'] ?? 'Non inscrit') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-calendar-alt"></i> Année</label>
                        <select name="annee_id" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($annees as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $anneeId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($bulletin): ?>
<!-- ============================================================
     BULLETIN OFFICIEL
     ============================================================ -->
<div class="bulletin-document" id="bulletinDocument">

    <!-- EN-TÊTE OFFICIEL RDC -->
    <div class="bulletin-rdc-header">
        <div class="rdc-header-top">
            <div class="rdc-flag-col"><img src="<?= IMAGES_URL ?>drapeau_rdc.jpg" alt="Drapeau RDC" class="rdc-flag"></div>
            <div class="rdc-title-col">
                <div class="rdc-pays"><?= e(mb_strtoupper($ecole['pays'] ?? 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO')) ?></div>
                <div class="rdc-ministere"><?= e(mb_strtoupper($ecole['ministere'] ?? "MINISTÈRE DE L'ENSEIGNEMENT PRIMAIRE, SECONDAIRE ET TECHNIQUE")) ?></div>
            </div>
            <div class="rdc-coat-col"><img src="<?= IMAGES_URL ?>armoiries_rdc.jpg" alt="Armoiries RDC" class="rdc-coat"></div>
        </div>
        <div class="rdc-id-row">
            <span class="id-label">N° ID.</span>
            <div class="id-grid">
                <?php
                $idClean = preg_replace('/[^A-Z0-9]/i', '', $bulletin['eleve']['numero_permanent'] ?? $bulletin['eleve']['matricule'] ?? '');
                for ($i = 0; $i < 22; $i++):
                ?>
                    <span class="id-box"><?= e($idClean[$i] ?? '') ?></span>
                <?php endfor; ?>
            </div>
        </div>
        <div class="rdc-info-grid">
            <div class="rdc-info-col">
                <div class="rdc-info-line"><span class="lbl">ÉCOLE :</span><span class="val dotted"><?= e(mb_strtoupper($ecole['nom_ecole'] ?? '')) ?></span></div>
                <div class="rdc-info-line"><span class="lbl">VILLE :</span><span class="val dotted"><?= e(mb_strtoupper($ecole['ville'] ?? '')) ?></span></div>
                <div class="rdc-info-line"><span class="lbl">COMMUNE/TER :</span><span class="val dotted"><?= e(mb_strtoupper($ecole['commune_territoire'] ?? '')) ?></span></div>
                <div class="rdc-info-line"><span class="lbl">CODE :</span><span class="val-code"><?= e($ecole['code_etablissement'] ?? '') ?></span></div>
            </div>
            <div class="rdc-info-col">
                <div class="rdc-info-line"><span class="lbl">ÉLÈVE :</span><span class="val dotted"><?= e(mb_strtoupper($bulletin['eleve']['nom'] . ' ' . $bulletin['eleve']['prenom'])) ?></span><span class="lbl-inline">SEXE :</span><span class="val-sm"><?= $bulletin['eleve']['sexe'] === 'M' ? 'M' : 'F' ?></span></div>
                <div class="rdc-info-line"><span class="lbl">NÉ(E) À :</span><span class="val dotted"><?= e(mb_strtoupper($bulletin['eleve']['lieu_naissance'] ?? '')) ?></span><span class="lbl-inline">LE :</span><span class="val-sm"><?= e($bulletin['eleve']['date_naissance_format'] ?? '') ?></span></div>
                <div class="rdc-info-line"><span class="lbl">CLASSE :</span><span class="val dotted"><?= e($bulletin['eleve']['classe_niveau']) ?><sup>e</sup> ANNÉE</span></div>
                <div class="rdc-info-line"><span class="lbl">N° PERM. :</span><span class="val-perm"><?= e($bulletin['eleve']['numero_permanent'] ?? $bulletin['eleve']['matricule']) ?></span></div>
            </div>
        </div>
        <div class="rdc-banner-title">
            <div class="bulletin-title">BULLETIN DE L'ÉLÈVE : <?= e($degre) ?></div>
            <div class="bulletin-annee">ANNÉE SCOLAIRE <?= e($anneeScolaire['libelle'] ?? '') ?></div>
        </div>
    </div>

    <!-- TABLEAU DES NOTES -->
    <div class="bulletin-table-wrapper">
        <table class="bulletin-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-branches">BRANCHES</th>
                    <th colspan="8" class="col-trimestre trimestre-1">1er TRIMESTRE</th>
                    <th colspan="8" class="col-trimestre trimestre-2">2ème TRIMESTRE</th>
                    <th colspan="8" class="col-trimestre trimestre-3">3ème TRIMESTRE</th>
                    <th colspan="2" rowspan="2" class="col-annuel">TOTAL ANNUEL</th>
                </tr>
                <tr>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                    <th class="col-m">M</th><th class="col-o">O</th>
                </tr>
                <tr class="sub-header">
                    <th></th>
                    <th colspan="2">P1</th><th colspan="2">P2</th><th colspan="2">EX1</th><th colspan="2">T1</th>
                    <th colspan="2">P3</th><th colspan="2">P4</th><th colspan="2">EX2</th><th colspan="2">T2</th>
                    <th colspan="2">P5</th><th colspan="2">P6</th><th colspan="2">EX3</th><th colspan="2">T3</th>
                    <th>MAX</th><th>OBT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bulletin['groupes'] as $groupe): ?>
                    <tr class="groupe-row">
                        <td class="col-branches groupe-nom" colspan="27"><strong><?= e(mb_strtoupper($groupe['libelle'])) ?></strong></td>
                    </tr>
                    <?php foreach ($groupe['sous_matieres'] as $sm): ?>
                        <tr class="sous-matiere-row">
                            <td class="col-branches"><?= e($sm['libelle']) ?></td>
                            <td class="col-m"><?= $sm['T1']['p1']['max'] > 0 ? $sm['T1']['p1']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T1']['p1']['obt'] > 0 ? $sm['T1']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T1']['p2']['max'] > 0 ? $sm['T1']['p2']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T1']['p2']['obt'] > 0 ? $sm['T1']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T1']['ex']['max'] > 0 ? $sm['T1']['ex']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T1']['ex']['obt'] > 0 ? $sm['T1']['ex']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T1']['total_max'] > 0 ? $sm['T1']['total_max'] : '' ?></td>
                            <td class="col-o col-total"><strong><?= $sm['T1']['total_obt'] > 0 ? $sm['T1']['total_obt'] : '—' ?></strong></td>
                            <td class="col-m"><?= $sm['T2']['p1']['max'] > 0 ? $sm['T2']['p1']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T2']['p1']['obt'] > 0 ? $sm['T2']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T2']['p2']['max'] > 0 ? $sm['T2']['p2']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T2']['p2']['obt'] > 0 ? $sm['T2']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T2']['ex']['max'] > 0 ? $sm['T2']['ex']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T2']['ex']['obt'] > 0 ? $sm['T2']['ex']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T2']['total_max'] > 0 ? $sm['T2']['total_max'] : '' ?></td>
                            <td class="col-o col-total"><strong><?= $sm['T2']['total_obt'] > 0 ? $sm['T2']['total_obt'] : '—' ?></strong></td>
                            <td class="col-m"><?= $sm['T3']['p1']['max'] > 0 ? $sm['T3']['p1']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T3']['p1']['obt'] > 0 ? $sm['T3']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T3']['p2']['max'] > 0 ? $sm['T3']['p2']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T3']['p2']['obt'] > 0 ? $sm['T3']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T3']['ex']['max'] > 0 ? $sm['T3']['ex']['max'] : '' ?></td>
                            <td class="col-o"><?= $sm['T3']['ex']['obt'] > 0 ? $sm['T3']['ex']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T3']['total_max'] > 0 ? $sm['T3']['total_max'] : '' ?></td>
                            <td class="col-o col-total"><strong><?= $sm['T3']['total_obt'] > 0 ? $sm['T3']['total_obt'] : '—' ?></strong></td>
                            <td class="col-m"><?= $sm['max_annuel'] > 0 ? $sm['max_annuel'] : '' ?></td>
                            <td class="col-o col-total"><strong><?= $sm['annuel']['obt'] > 0 ? $sm['annuel']['obt'] : '—' ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="sous-total-row">
                        <td class="col-branches"><strong>SOUS-TOTAL</strong></td>
                        <?php $st = $groupe['sous_total'];
                        foreach (['T1','T2','T3'] as $tk):
                            foreach (['p1','p2','ex','total'] as $pk): $val = $st[$tk][$pk]; ?>
                                <td class="col-m"><?= $val['max'] > 0 ? $val['max'] : '' ?></td>
                                <td class="col-o"><strong><?= $val['obt'] > 0 ? $val['obt'] : '—' ?></strong></td>
                        <?php endforeach; endforeach; ?>
                        <td class="col-m"><?= $st['annuel']['max'] > 0 ? $st['annuel']['max'] : '' ?></td>
                        <td class="col-o"><strong><?= $st['annuel']['obt'] > 0 ? $st['annuel']['obt'] : '—' ?></strong></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-general-row">
                    <td class="col-branches"><strong>TOTAL GÉNÉRAL</strong></td>
                    <?php $tg = $bulletin['totaux'];
                    foreach (['T1','T2','T3'] as $tk):
                        foreach (['p1','p2','ex','total'] as $pk): $val = $tg[$tk][$pk]; ?>
                            <td class="col-m"><?= $val['max'] > 0 ? $val['max'] : '' ?></td>
                            <td class="col-o"><strong><?= $val['obt'] > 0 ? $val['obt'] : '' ?></strong></td>
                    <?php endforeach; endforeach; ?>
                    <td class="col-m"><?= $tg['annuel']['max'] > 0 ? $tg['annuel']['max'] : '' ?></td>
                    <td class="col-o"><strong><?= $tg['annuel']['obt'] > 0 ? $tg['annuel']['obt'] : '' ?></strong></td>
                </tr>

                <tr class="pourcentage-row">
                    <td class="col-branches"><strong>POURCENTAGE</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T1']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T1']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T1']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T1']['trimestre'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T2']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T2']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T2']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T2']['trimestre'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T3']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T3']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T3']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['T3']['trimestre'] ?>%</strong></td>
                    <td colspan="2" class="text-center"><strong><?= $bulletin['pourcentages']['annuel']['pct'] ?>%</strong></td>
                </tr>

                <?php require VIEWS_PATH . 'modules' . DIRECTORY_SEPARATOR . 'bulletins' . DIRECTORY_SEPARATOR . '_synthese.php'; ?>
            </tbody>
        </table>
    </div>

    <!-- ACTIONS -->
    <div class="bulletin-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer</button>
        <a href="/gestion_scolaire/parent/enfants" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    </div>
</div>

<?php else: ?>
    <div class="empty-state" style="padding:60px 20px;background:#fff;border-radius:16px;border:1px solid var(--border-color);">
        <i class="fa-solid fa-file-invoice"></i>
        <p>Aucune donnée de bulletin disponible pour le moment.</p>
        <a href="/gestion_scolaire/parent/enfants" class="btn btn-primary btn-sm mt-3">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
    </div>
<?php endif; ?>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>