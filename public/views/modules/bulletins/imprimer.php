<?php
/**
 * Fichier : public/views/modules/bulletins/imprimer.php
 * Rôle    : Impression des bulletins — Individuelle ou massive avec navigation
 * Système : Bulletin officiel congolais (EPST/RDC) — Annuel complet
 * Version : 4.0 — Navigation entre bulletins + impression sélective
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('bulletins.imprimer');

global $db;

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'ResultatController.php';
$resultatController = new ResultatController();

$classeId = (int) ($_GET['classe_id'] ?? 0);
$anneeId = (int) ($_GET['annee_id'] ?? 0);
$eleveId = (int) ($_GET['eleve_id'] ?? 0);
$mode = $_GET['mode'] ?? '';

if ($anneeId <= 0) $anneeId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");

$annees = $resultatController->getAnneesScolaires();
$classes = $db->fetchAll(
    "SELECT c.* FROM classes c WHERE c.annee_scolaire_id = :aid ORDER BY c.niveau",
    ['aid' => $anneeId]
);
if ($classeId > 0) {
    $classeAnneeId = (int) $db->fetchColumn(
        "SELECT annee_scolaire_id FROM classes WHERE id = :cid",
        ['cid' => $classeId]
    );
    if ($classeAnneeId !== $anneeId) $classeId = 0;
}

$ecole = $db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
$annee = $db->fetchOne("SELECT * FROM annees_scolaires WHERE id = :id", ['id' => $anneeId]);

// Récupérer les élèves d'une classe
$eleves = [];
if ($classeId > 0) {
    $eleves = $db->fetchAll(
        "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe
         FROM inscriptions i
         INNER JOIN eleves e ON i.eleve_id = e.id
         INNER JOIN classes c ON c.id = i.classe_id AND c.annee_scolaire_id = i.annee_scolaire_id
         WHERE i.classe_id = :cid AND i.annee_scolaire_id = :aid
           AND i.statut = 'Actif' AND e.est_actif = 1
         ORDER BY e.nom, e.prenom",
        ['cid' => $classeId, 'aid' => $anneeId]
    );
}

// Déterminer l'élève à afficher
$currentIndex = 0;
$bulletin = null;

if ($mode === 'massif' && !isset($_GET['print_all']) && !empty($eleves)) {
    // Si un élève spécifique est demandé, le trouver
    if ($eleveId > 0) {
        foreach ($eleves as $i => $el) {
            if ((int) $el['id'] === $eleveId) {
                $currentIndex = $i;
                break;
            }
        }
    }
    $eleveActuel = $eleves[$currentIndex];
    $bulletin = $resultatController->getResultatsCompletsEleve($eleveActuel['id'], $anneeId);
} elseif ($eleveId > 0) {
    $bulletin = $resultatController->getResultatsCompletsEleve($eleveId, $anneeId);
}

// Élèves précédent et suivant
$prevEleve = $currentIndex > 0 ? $eleves[$currentIndex - 1] : null;
$nextEleve = $currentIndex < count($eleves) - 1 ? $eleves[$currentIndex + 1] : null;

$degre = getBulletinDegree($bulletin);

$pageTitle = 'Imprimer les bulletins';
$pageIcon  = 'fa-print';
$pageDescription = 'Impression des bulletins annuels';
$extraCSS = ['modules/bulletins.css', 'modules/bulletin-view.css', 'modules/bulletin-print.css'];
$extraJS  = ['modules/bulletins.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="/gestion_scolaire/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="/gestion_scolaire/bulletins">Bulletins</a>
    <span class="separator">/</span>
    <span class="current">Imprimer</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-print"></i> Imprimer les bulletins</h4>
        <p>Imprimez un bulletin individuel ou tous les bulletins d'une classe.</p>
    </div>
    <div class="resultats-header-right">
        <a href="/gestion_scolaire/bulletins" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    </div>
</div>

<div id="flashMessage"></div>

<!-- OPTIONS D'IMPRESSION -->
<div class="imprimer-options no-print">
    <!-- INDIVIDUEL -->
    <div class="imprimer-option-card">
        <div class="imprimer-option-icon blue"><i class="fa-solid fa-user"></i></div>
        <div class="imprimer-option-content">
            <h5>Impression individuelle</h5>
            <p>Imprimer le bulletin annuel complet d'un seul élève</p>
            <form method="GET" action="" class="imprimer-option-form">
                <input type="hidden" name="mode" value="individuel">
                <input type="hidden" name="annee_id" value="<?= $anneeId ?>">
                <div class="form-row">
                    <div class="form-group">
                        <select name="classe_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Classe —</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $classeId === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <select name="eleve_id" class="form-control">
                            <option value="">— Élève —</option>
                            <?php foreach ($eleves as $el): ?>
                                <option value="<?= $el['id'] ?>" <?= $eleveId === (int) $el['id'] ? 'selected' : '' ?>><?= e($el['prenom'] . ' ' . $el['nom']) ?> — <?= e($el['matricule']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-print"></i> Afficher</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MASSIF -->
    <div class="imprimer-option-card">
        <div class="imprimer-option-icon green"><i class="fa-solid fa-users"></i></div>
        <div class="imprimer-option-content">
            <h5>Impression massive</h5>
            <p>Parcourir et imprimer les bulletins annuels de tous les élèves d'une classe</p>
            <form method="GET" action="" class="imprimer-option-form">
                <input type="hidden" name="mode" value="massif">
                <input type="hidden" name="annee_id" value="<?= $anneeId ?>">
                <div class="form-row">
                    <div class="form-group">
                        <select name="classe_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Classe —</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $classeId === (int) $cl['id'] && $mode === 'massif' ? 'selected' : '' ?>><?= e($cl['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-users"></i> Afficher la classe</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- BULLETIN INDIVIDUEL -->
<?php if ($bulletin && $mode === 'individuel'): ?>
<div class="no-print" style="text-align:center;margin-bottom:20px;">
    <button type="button" class="btn btn-primary btn-lg" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer ce bulletin</button>
    <button type="button" class="btn btn-outline-primary btn-lg ms-2" onclick="window.history.back()"><i class="fa-solid fa-arrow-left"></i> Retour</button>
</div>

<?= renderBulletinComplet($bulletin, $ecole, $annee, $degre) ?>

<!-- IMPRESSION MASSIVE AVEC NAVIGATION -->
<?php elseif ($mode === 'massif' && !empty($eleves) && $bulletin): ?>
<div class="no-print" style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
    <?php if ($prevEleve): ?>
        <a href="?mode=massif&classe_id=<?= $classeId ?>&annee_id=<?= $anneeId ?>&eleve_id=<?= $prevEleve['id'] ?>" class="btn btn-outline-primary btn-lg">
            <i class="fa-solid fa-chevron-left"></i> <?= e($prevEleve['prenom']) ?>
        </a>
    <?php else: ?>
        <button class="btn btn-outline-primary btn-lg" disabled><i class="fa-solid fa-chevron-left"></i> Précédent</button>
    <?php endif; ?>

    <div style="text-align:center;">
        <span style="font-size:0.9rem;font-weight:600;color:var(--color-dark);">
            Bulletin <?= $currentIndex + 1 ?> / <?= count($eleves) ?>
        </span>
        <br>
        <span style="font-size:0.78rem;color:var(--color-gray-500);">
            <?= e($bulletin['eleve']['prenom'] . ' ' . $bulletin['eleve']['nom']) ?> — 
            <?= e($bulletin['eleve']['matricule']) ?>
        </span>
    </div>

    <?php if ($nextEleve): ?>
        <a href="?mode=massif&classe_id=<?= $classeId ?>&annee_id=<?= $anneeId ?>&eleve_id=<?= $nextEleve['id'] ?>" class="btn btn-outline-primary btn-lg">
            <?= e($nextEleve['prenom']) ?> <i class="fa-solid fa-chevron-right"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-outline-primary btn-lg" disabled>Suivant <i class="fa-solid fa-chevron-right"></i></button>
    <?php endif; ?>
</div>

<div class="no-print" style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer ce bulletin</button>
    <button type="button" class="btn btn-success" onclick="window.location.href='?mode=massif&classe_id=<?= $classeId ?>&annee_id=<?= $anneeId ?>&print_all=1'">
        <i class="fa-solid fa-print"></i> Imprimer tous les bulletins
    </button>
    <select class="form-control" style="width:auto;min-width:200px;" onchange="if(this.value) window.location.href='?mode=massif&classe_id=<?= $classeId ?>&annee_id=<?= $anneeId ?>&eleve_id='+this.value">
        <option value="">— Aller à un élève —</option>
        <?php foreach ($eleves as $i => $el): ?>
            <option value="<?= $el['id'] ?>" <?= (int) $el['id'] === (int) $bulletin['eleve']['id'] ? 'selected' : '' ?>>
                <?= $i + 1 ?>. <?= e($el['prenom'] . ' ' . $el['nom']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?= renderBulletinComplet($bulletin, $ecole, $annee, $degre) ?>

<!-- IMPRESSION MASSIVE — TOUS -->
<?php elseif ($mode === 'massif' && isset($_GET['print_all']) && !empty($eleves)): ?>
<div class="no-print" style="text-align:center;margin-bottom:20px;">
    <button type="button" class="btn btn-primary btn-lg" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer tous les bulletins (<?= count($eleves) ?>)</button>
    <a href="?mode=massif&classe_id=<?= $classeId ?>&annee_id=<?= $anneeId ?>" class="btn btn-outline-primary btn-lg ms-2"><i class="fa-solid fa-arrow-left"></i> Retour</a>
</div>

<div class="resultats-info-bar">
    <div class="resultats-info-item"><i class="fa-solid fa-chalkboard"></i><span>Classe : <strong><?= e($db->fetchColumn("SELECT nom FROM classes WHERE id = :id", ['id' => $classeId])) ?></strong></span></div>
    <div class="resultats-info-item"><i class="fa-solid fa-users"></i><span><strong><?= count($eleves) ?></strong> élève(s)</span></div>
    <div class="resultats-info-item"><i class="fa-solid fa-calendar-alt"></i><span>Année : <strong><?= e($annee['libelle'] ?? '') ?></strong></span></div>
</div>

<div class="bulletins-print-all">
<?php foreach ($eleves as $el): 
    $b = $resultatController->getResultatsCompletsEleve($el['id'], $anneeId);
    if (empty($b)) continue;
?>
    <?= renderBulletinComplet($b, $ecole, $annee, getBulletinDegree($b)) ?>
<?php endforeach; ?>
</div>

<?php elseif ($classeId <= 0): ?>
<div class="empty-state" style="padding:60px 20px;background:#fff;border-radius:16px;border:1px solid var(--border-color);">
    <i class="fa-solid fa-print"></i>
    <p>Choisissez un mode d'impression ci-dessus.</p>
</div>
<?php endif; ?>

<?php
function getBulletinDegree(?array $bulletin): string
{
    if (!$bulletin || !isset($bulletin['eleve']['classe_niveau'])) {
        return '';
    }

    $niveau = (int) $bulletin['eleve']['classe_niveau'];
    if ($niveau >= 1 && $niveau <= 2) {
        return 'DEGRÉ ÉLÉMENTAIRE (' . $niveau . 'e ANNÉE)';
    }
    if ($niveau >= 3 && $niveau <= 4) {
        return 'DEGRÉ MOYEN (' . $niveau . 'e ANNÉE)';
    }
    if ($niveau >= 5 && $niveau <= 6) {
        return 'DEGRÉ TERMINAL (' . $niveau . 'e ANNÉE)';
    }

    return '';
}

// Fonction pour afficher le bulletin complet
function renderBulletinComplet($bulletin, $ecole, $annee, $degre) {
    ob_start();
?>
<div class="bulletin-document bulletin-print-sheet">
<div class="bulletin-rdc-header">
    <div class="rdc-header-top">
        <div class="rdc-flag-col">
            <img src="<?= IMAGES_URL ?>drapeau_rdc.jpg" alt="Drapeau de la République démocratique du Congo" class="rdc-flag">
        </div>
        <div class="rdc-title-col">
            <div class="rdc-pays"><?= e(mb_strtoupper($ecole['pays'] ?? 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO')) ?></div>
            <div class="rdc-ministere"><?= e(mb_strtoupper($ecole['ministere'] ?? "MINISTÈRE DE L'ENSEIGNEMENT PRIMAIRE, SECONDAIRE ET TECHNIQUE")) ?></div>
        </div>
        <div class="rdc-coat-col">
            <img src="<?= IMAGES_URL ?>armoiries_rdc.jpg" alt="Armoiries de la République démocratique du Congo" class="rdc-coat">
        </div>
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
            <div class="rdc-info-line">
                <span class="lbl">ÉLÈVE :</span>
                <span class="val dotted"><?= e(mb_strtoupper($bulletin['eleve']['nom'] . ' ' . $bulletin['eleve']['prenom'])) ?></span>
                <span class="lbl-inline">SEXE :</span>
                <span class="val-sm"><?= $bulletin['eleve']['sexe'] === 'M' ? 'M' : 'F' ?></span>
            </div>
            <div class="rdc-info-line">
                <span class="lbl">NÉ(E) À :</span>
                <span class="val dotted"><?= e(mb_strtoupper($bulletin['eleve']['lieu_naissance'] ?? '')) ?></span>
                <span class="lbl-inline">LE :</span>
                <span class="val-sm"><?= e($bulletin['eleve']['date_naissance_format'] ?? '') ?></span>
            </div>
            <div class="rdc-info-line"><span class="lbl">CLASSE :</span><span class="val dotted"><?= e($bulletin['eleve']['classe_niveau']) ?><sup>e</sup> ANNÉE</span></div>
            <div class="rdc-info-line"><span class="lbl">N° PERM. :</span><span class="val-perm"><?= e($bulletin['eleve']['numero_permanent'] ?? $bulletin['eleve']['matricule']) ?></span></div>
        </div>
    </div>
    <div class="rdc-banner-title">
        <div class="bulletin-title">BULLETIN DE L'ÉLÈVE : <?= e($degre) ?></div>
        <div class="bulletin-annee">ANNÉE SCOLAIRE <?= e($annee['libelle'] ?? '') ?></div>
    </div>
</div>

<div class="bulletin-table-wrapper">
    <table class="bulletin-table">
        <thead>
            <tr>
                <th rowspan="3" class="col-branches">BRANCHES</th>
                <th colspan="8" class="col-trimestre trimestre-1">PREMIER TRIMESTRE</th>
                <th colspan="8" class="col-trimestre trimestre-2">DEUXIÈME TRIMESTRE</th>
                <th colspan="8" class="col-trimestre trimestre-3">TROISIÈME TRIMESTRE</th>
                <th colspan="2" rowspan="2" class="col-annuel">TOTAL ANNUEL</th>
            </tr>
            <tr class="header-sub-row">
                <th colspan="2">1<sup>e</sup> P</th><th colspan="2">2<sup>e</sup> P</th><th colspan="2">EXAM</th><th colspan="2">TRIM. 1</th>
                <th colspan="2">3<sup>e</sup> P</th><th colspan="2">4<sup>e</sup> P</th><th colspan="2">EXAM</th><th colspan="2">TRIM. 2</th>
                <th colspan="2">5<sup>e</sup> P</th><th colspan="2">6<sup>e</sup> P</th><th colspan="2">EXAM</th><th colspan="2">TRIM. 3</th>
            </tr>
            <tr class="header-mo-row">
                <?php for ($i = 0; $i < 12; $i++): ?>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bulletin['groupes'] as $groupe): ?>
                <tr class="groupe-row"><td class="col-branches groupe-nom" colspan="27"><strong><?= e(mb_strtoupper($groupe['libelle'])) ?></strong></td></tr>
                <?php foreach ($groupe['sous_matieres'] as $sm): ?>
                    <tr class="sous-matiere-row">
                        <td class="col-branches"><?= e($sm['libelle']) ?></td>
                        <td class="col-m"><?= $sm['T1']['p1']['max'] ?></td><td class="col-o"><?= $sm['T1']['p1']['obt'] > 0 ? $sm['T1']['p1']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T1']['p2']['max'] ?></td><td class="col-o"><?= $sm['T1']['p2']['obt'] > 0 ? $sm['T1']['p2']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T1']['ex']['max'] ?></td><td class="col-o"><?= $sm['T1']['ex']['obt'] > 0 ? $sm['T1']['ex']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T1']['total_max'] ?></td><td class="col-o col-total"><strong><?= $sm['T1']['total_obt'] > 0 ? $sm['T1']['total_obt'] : '—' ?></strong></td>
                        <td class="col-m"><?= $sm['T2']['p1']['max'] ?></td><td class="col-o"><?= $sm['T2']['p1']['obt'] > 0 ? $sm['T2']['p1']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T2']['p2']['max'] ?></td><td class="col-o"><?= $sm['T2']['p2']['obt'] > 0 ? $sm['T2']['p2']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T2']['ex']['max'] ?></td><td class="col-o"><?= $sm['T2']['ex']['obt'] > 0 ? $sm['T2']['ex']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T2']['total_max'] ?></td><td class="col-o col-total"><strong><?= $sm['T2']['total_obt'] > 0 ? $sm['T2']['total_obt'] : '—' ?></strong></td>
                        <td class="col-m"><?= $sm['T3']['p1']['max'] ?></td><td class="col-o"><?= $sm['T3']['p1']['obt'] > 0 ? $sm['T3']['p1']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T3']['p2']['max'] ?></td><td class="col-o"><?= $sm['T3']['p2']['obt'] > 0 ? $sm['T3']['p2']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T3']['ex']['max'] ?></td><td class="col-o"><?= $sm['T3']['ex']['obt'] > 0 ? $sm['T3']['ex']['obt'] : '—' ?></td>
                        <td class="col-m"><?= $sm['T3']['total_max'] ?></td><td class="col-o col-total"><strong><?= $sm['T3']['total_obt'] > 0 ? $sm['T3']['total_obt'] : '—' ?></strong></td>
                        <td class="col-m"><?= $sm['max_annuel'] ?></td><td class="col-o col-total"><strong><?= $sm['annuel']['obt'] > 0 ? $sm['annuel']['obt'] : '—' ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="sous-total-row">
                    <td class="col-branches"><strong>SOUS-TOTAL</strong></td>
                    <?php $st = $groupe['sous_total'];
                    foreach (['T1','T2','T3'] as $tk):
                        foreach (['p1','p2','ex','total'] as $pk): $val = $st[$tk][$pk]; ?>
                            <td class="col-m"><?= $val['max'] ?></td><td class="col-o"><strong><?= $val['obt'] > 0 ? $val['obt'] : '—' ?></strong></td>
                    <?php endforeach; endforeach; ?>
                    <td class="col-m"><?= $st['annuel']['max'] ?></td><td class="col-o"><strong><?= $st['annuel']['obt'] > 0 ? $st['annuel']['obt'] : '—' ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-general-row">
                <td class="col-branches"><strong>TOTAL GÉNÉRAL</strong></td>
                <?php $tg = $bulletin['totaux'];
                foreach (['T1','T2','T3'] as $tk):
                    foreach (['p1','p2','ex','total'] as $pk): $val = $tg[$tk][$pk]; ?>
                        <td class="col-m"><?= $val['max'] ?></td><td class="col-o"><strong><?= $val['obt'] ?></strong></td>
                <?php endforeach; endforeach; ?>
                <td class="col-m"><?= $tg['annuel']['max'] ?></td><td class="col-o"><strong><?= $tg['annuel']['obt'] ?></strong></td>
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
</div>
<?php
    return ob_get_clean();
}
?>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>