<?php
/**
 * Fichier : public/views/modules/bulletins/consulter.php
 * Rôle    : Consultation du bulletin officiel congolais (EPST/RDC)
 * Système : Bulletin avec 6 périodes, 3 examens, 3 trimestres, total annuel
 * Version : 3.5 — Conforme à la grille officielle du Ministère (EPST/RDC)
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('bulletins.consulter');

global $db;

require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'ResultatController.php';
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'BulletinController.php';
$resultatController = new ResultatController();
$bulletinController = new BulletinController();

$eleveId = (int) ($_GET['eleve_id'] ?? 0);
$anneeId = (int) ($_GET['annee_id'] ?? 0);
if ($anneeId <= 0) $anneeId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");

$ecole = $db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
$classes = $db->fetchAll(
    "SELECT c.* FROM classes c WHERE c.annee_scolaire_id = :aid ORDER BY c.niveau",
    ['aid' => $anneeId]
);
$annees = $resultatController->getAnneesScolaires();

$eleves = [];
$classeId = (int) ($_GET['classe_id'] ?? 0);
if ($classeId > 0) {
    $classeAnneeId = (int) $db->fetchColumn(
        "SELECT annee_scolaire_id FROM classes WHERE id = :cid",
        ['cid' => $classeId]
    );
    if ($classeAnneeId !== $anneeId) $classeId = 0;
}

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

$bulletin = null;
if ($eleveId > 0) {
    $bulletin = $resultatController->getResultatsCompletsEleve($eleveId, $anneeId);
}

$anneeScolaire = $db->fetchOne("SELECT * FROM annees_scolaires WHERE id = :id", ['id' => $anneeId]);

$degre = '';
if ($bulletin && $bulletin['eleve']['classe_niveau'] == 6) $degre = 'DEGRÉ TERMINAL (6e ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 1 && $bulletin['eleve']['classe_niveau'] <= 2) $degre = 'DEGRÉ ÉLÉMENTAIRE (' . $bulletin['eleve']['classe_niveau'] . 'e ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 3 && $bulletin['eleve']['classe_niveau'] <= 4) $degre = 'DEGRÉ MOYEN (' . $bulletin['eleve']['classe_niveau'] . 'e ANNÉE)';
elseif ($bulletin && $bulletin['eleve']['classe_niveau'] >= 5) $degre = 'DEGRÉ TERMINAL (' . $bulletin['eleve']['classe_niveau'] . 'e ANNÉE)';

$pageTitle = 'Bulletin scolaire';
$pageIcon  = 'fa-file-invoice';
$pageDescription = 'Bulletin officiel — ' . ($bulletin ? e($bulletin['eleve']['prenom'] . ' ' . $bulletin['eleve']['nom']) : 'Consultation');
$extraCSS = ['modules/bulletins.css', 'modules/bulletin-view.css', 'modules/bulletin-print.css'];
$extraJS  = ['modules/bulletins.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb no-print">
    <a href="/gestion_scolaire/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="/gestion_scolaire/bulletins">Bulletins</a>
    <span class="separator">/</span>
    <span class="current"><?= $bulletin ? 'Bulletin de ' . e($bulletin['eleve']['prenom'] . ' ' . $bulletin['eleve']['nom']) : 'Consulter' ?></span>
</nav>

<!-- SÉLECTEURS (MASQUÉS À L'IMPRESSION) -->
<div class="bulletin-selectors no-print">
    <div class="bulletin-selector-card">
        <div class="bulletin-selector-header"><i class="fa-solid fa-filter"></i> Sélectionner un élève</div>
        <div class="bulletin-selector-body">
            <form method="GET" action="" class="bulletin-selector-form">
                <input type="hidden" name="annee_id" value="<?= $anneeId ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-chalkboard"></i> Classe</label>
                        <select name="classe_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Classe —</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $classeId === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nom']) ?> (Niv. <?= $cl['niveau'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-graduation-cap"></i> Élève</label>
                        <select name="eleve_id" class="form-control" onchange="this.form.submit()">
                            <option value="">— Élève —</option>
                            <?php foreach ($eleves as $el): ?>
                                <option value="<?= $el['id'] ?>" <?= $eleveId === (int) $el['id'] ? 'selected' : '' ?>><?= e($el['prenom'] . ' ' . $el['nom']) ?> — <?= e($el['matricule']) ?></option>
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
     BULLETIN OFFICIEL RDC (FORMAT IMPRESSION / DOCUMENT)
     ============================================================ -->
<div class="bulletin-document" id="bulletinDocument">

    <!-- EN-TÊTE OFFICIEL 3 COLONNES & BLOC ID -->
    <div class="bulletin-rdc-header">
        <div class="rdc-header-top">
            <div class="rdc-flag-col">
                <img src="<?= IMAGES_URL ?>drapeau_rdc.jpg" alt="Drapeau RDC" class="rdc-flag" onerror="this.style.display='none'">
            </div>
            <div class="rdc-title-col">
                <div class="rdc-pays"><?= e(mb_strtoupper($ecole['pays'] ?? 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO')) ?></div>
                <div class="rdc-ministere"><?= e(mb_strtoupper($ecole['ministere'] ?? "MINISTÈRE DE L'ENSEIGNEMENT PRIMAIRE, SECONDAIRE ET TECHNIQUE")) ?></div>
            </div>
            <div class="rdc-coat-col">
                <img src="<?= IMAGES_URL ?>armoiries_rdc.jpg" alt="Armoiries RDC" class="rdc-coat" onerror="this.style.display='none'">
            </div>
        </div>

        <!-- GRILLE NUMÉRO D'IDENTIFICATION OFFICIEL -->
        <div class="rdc-id-row">
            <span class="id-label">N° ID.</span>
            <div class="id-grid">
                <?php 
                $idClean = preg_replace('/[^A-Z0-9]/i', '', $bulletin['eleve']['numero_permanent'] ?? $bulletin['eleve']['matricule'] ?? '');
                for ($i = 0; $i < 22; $i++): 
                    $char = $idClean[$i] ?? '';
                ?>
                    <span class="id-box"><?= e($char) ?></span>
                <?php endfor; ?>
            </div>
        </div>

        <!-- INFORMATIONS ÉTABLISSEMENT ET ÉLÈVE (STYLE GRILLE ET LIGNES POINTILLÉES) -->
        <div class="rdc-info-grid">
            <div class="rdc-info-col">
                <div class="rdc-info-line">
                    <span class="lbl">ÉCOLE :</span>
                    <span class="val dotted"><?= e(mb_strtoupper($ecole['nom_ecole'] ?? '')) ?></span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">VILLE :</span>
                    <span class="val dotted"><?= e(mb_strtoupper($ecole['ville'] ?? '')) ?></span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">COMMUNE/TER (1) :</span>
                    <span class="val dotted"><?= e(mb_strtoupper($ecole['commune_territoire'] ?? '')) ?></span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">CODE :</span>
                    <span class="val-code"><?= e($ecole['code_etablissement'] ?? '') ?></span>
                </div>
            </div>
            <div class="rdc-info-col">
                <div class="rdc-info-line">
                    <span class="lbl">ÉLÈVE :</span>
                    <span class="val dotted handwriting"><?= e(mb_strtoupper($bulletin['eleve']['nom'] . ' ' . $bulletin['eleve']['prenom'])) ?></span>
                    <span class="lbl-inline">SEXE :</span>
                    <span class="val-sm handwriting"><?= $bulletin['eleve']['sexe'] === 'M' ? 'M' : 'F' ?></span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">NÉ(E) À :</span>
                    <span class="val dotted handwriting"><?= e(mb_strtoupper($bulletin['eleve']['lieu_naissance'] ?? '')) ?></span>
                    <span class="lbl-inline">LE :</span>
                    <span class="val-sm handwriting"><?= e($bulletin['eleve']['date_naissance_format'] ?? '') ?></span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">CLASSE :</span>
                    <span class="val dotted handwriting"><?= $bulletin['eleve']['classe_niveau'] ?><sup>e</sup> ANNÉE</span>
                </div>
                <div class="rdc-info-line">
                    <span class="lbl">N° PERM. :</span>
                    <span class="val-perm"><?= e($bulletin['eleve']['numero_permanent'] ?? $bulletin['eleve']['matricule']) ?></span>
                </div>
            </div>
        </div>

        <!-- BANDEROLE TITRE & ANNÉE SCOLAIRE -->
        <div class="rdc-banner-title">
            <div class="bulletin-title">BULLETIN DE L'ÉLÈVE : <?= e($degre) ?></div>
            <div class="bulletin-annee">ANNÉE SCOLAIRE <?= e($anneeScolaire['libelle'] ?? '') ?></div>
        </div>
    </div>

    <!-- GRILLE OFFICIELLE DU TABLEAU DE NOTES (27 COLONNES) -->
    <div class="bulletin-table-wrapper">
        <table class="bulletin-table">
            <thead>
                <tr class="header-main-row">
                    <th rowspan="3" class="col-branches">BRANCHES</th>
                    <th colspan="8" class="col-trimestre trimestre-1">PREMIER TRIMESTRE</th>
                    <th colspan="8" class="col-trimestre trimestre-2">DEUXIÈME TRIMESTRE</th>
                    <th colspan="8" class="col-trimestre trimestre-3">TROISIÈME TRIMESTRE</th>
                    <th colspan="2" rowspan="2" class="col-annuel">TOTAL ANNUEL</th>
                </tr>
                <tr class="header-sub-row">
                    <th colspan="2" class="sub-head">1<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">2<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">EXAM</th>
                    <th colspan="2" class="sub-head">TRIM.1</th>

                    <th colspan="2" class="sub-head">3<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">4<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">EXAM</th>
                    <th colspan="2" class="sub-head">TRIM.2</th>

                    <th colspan="2" class="sub-head">5<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">6<sup>e</sup> P</th>
                    <th colspan="2" class="sub-head">EXAM</th>
                    <th colspan="2" class="sub-head">TRIM.3</th>
                </tr>
                <tr class="header-mo-row">
                    <!-- T1 -->
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>

                    <!-- T2 -->
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>

                    <!-- T3 -->
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>

                    <!-- Annuel -->
                    <th class="col-m">MAX</th><th class="col-o">OBT</th>
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
                            
                            <!-- 1er TRIMESTRE -->
                            <td class="col-m"><?= $sm['T1']['p1']['max'] > 0 ? $sm['T1']['p1']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T1']['p1']['obt'] > 0 ? $sm['T1']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T1']['p2']['max'] > 0 ? $sm['T1']['p2']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T1']['p2']['obt'] > 0 ? $sm['T1']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T1']['ex']['max'] > 0 ? $sm['T1']['ex']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T1']['ex']['obt'] > 0 ? $sm['T1']['ex']['obt'] : '—' ?></td>
                            <td class="col-m border-left-strong"><?= $sm['T1']['total_max'] > 0 ? $sm['T1']['total_max'] : '' ?></td>
                            <td class="col-o col-total handwriting"><strong><?= $sm['T1']['total_obt'] > 0 ? $sm['T1']['total_obt'] : '—' ?></strong></td>
                            
                            <!-- 2ème TRIMESTRE -->
                            <td class="col-m"><?= $sm['T2']['p1']['max'] > 0 ? $sm['T2']['p1']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T2']['p1']['obt'] > 0 ? $sm['T2']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T2']['p2']['max'] > 0 ? $sm['T2']['p2']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T2']['p2']['obt'] > 0 ? $sm['T2']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T2']['ex']['max'] > 0 ? $sm['T2']['ex']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T2']['ex']['obt'] > 0 ? $sm['T2']['ex']['obt'] : '—' ?></td>
                            <td class="col-m border-left-strong"><?= $sm['T2']['total_max'] > 0 ? $sm['T2']['total_max'] : '' ?></td>
                            <td class="col-o col-total handwriting"><strong><?= $sm['T2']['total_obt'] > 0 ? $sm['T2']['total_obt'] : '—' ?></strong></td>
                            
                            <!-- 3ème TRIMESTRE -->
                            <td class="col-m"><?= $sm['T3']['p1']['max'] > 0 ? $sm['T3']['p1']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T3']['p1']['obt'] > 0 ? $sm['T3']['p1']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T3']['p2']['max'] > 0 ? $sm['T3']['p2']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T3']['p2']['obt'] > 0 ? $sm['T3']['p2']['obt'] : '—' ?></td>
                            <td class="col-m"><?= $sm['T3']['ex']['max'] > 0 ? $sm['T3']['ex']['max'] : '' ?></td>
                            <td class="col-o handwriting"><?= $sm['T3']['ex']['obt'] > 0 ? $sm['T3']['ex']['obt'] : '—' ?></td>
                            <td class="col-m border-left-strong"><?= $sm['T3']['total_max'] > 0 ? $sm['T3']['total_max'] : '' ?></td>
                            <td class="col-o col-total handwriting"><strong><?= $sm['T3']['total_obt'] > 0 ? $sm['T3']['total_obt'] : '—' ?></strong></td>
                            
                            <!-- TOTAL ANNUEL -->
                            <td class="col-m border-left-strong"><?= $sm['max_annuel'] > 0 ? $sm['max_annuel'] : '' ?></td>
                            <td class="col-o col-total handwriting"><strong><?= $sm['annuel']['obt'] > 0 ? $sm['annuel']['obt'] : '—' ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- SOUS-TOTAL DU GROUPE -->
                    <tr class="sous-total-row">
                        <td class="col-branches"><strong>SOUS-TOTAL</strong></td>
                        <?php 
                        $st = $groupe['sous_total'];
                        foreach (['T1','T2','T3'] as $tk):
                            foreach (['p1','p2','ex','total'] as $pk): 
                                $val = $st[$tk][$pk];
                                $isTot = ($pk === 'total');
                        ?>
                            <td class="col-m <?= $isTot ? 'border-left-strong' : '' ?>"><?= $val['max'] > 0 ? $val['max'] : '' ?></td>
                            <td class="col-o handwriting"><strong><?= $val['obt'] > 0 ? $val['obt'] : '—' ?></strong></td>
                        <?php endforeach; endforeach; ?>
                        <td class="col-m border-left-strong"><?= $st['annuel']['max'] > 0 ? $st['annuel']['max'] : '' ?></td>
                        <td class="col-o handwriting"><strong><?= $st['annuel']['obt'] > 0 ? $st['annuel']['obt'] : '—' ?></strong></td>
                    </tr>
                <?php endforeach; ?>

                <!-- TOTAL GÉNÉRAL -->
                <tr class="total-general-row">
                    <td class="col-branches"><strong>MAXIMUM GÉNÉRAL</strong></td>
                    <?php 
                    $tg = $bulletin['totaux'];
                    foreach (['T1','T2','T3'] as $tk):
                        foreach (['p1','p2','ex','total'] as $pk): 
                            $val = $tg[$tk][$pk];
                            $isTot = ($pk === 'total');
                    ?>
                        <td class="col-m <?= $isTot ? 'border-left-strong' : '' ?>"><?= $val['max'] > 0 ? $val['max'] : '' ?></td>
                        <td class="col-o handwriting"><strong><?= $val['obt'] > 0 ? $val['obt'] : '' ?></strong></td>
                    <?php endforeach; endforeach; ?>
                    <td class="col-m border-left-strong"><?= $tg['annuel']['max'] > 0 ? $tg['annuel']['max'] : '' ?></td>
                    <td class="col-o handwriting"><strong><?= $tg['annuel']['obt'] > 0 ? $tg['annuel']['obt'] : '' ?></strong></td>
                </tr>

                <!-- POURCENTAGES -->
                <tr class="pourcentage-row">
                    <td class="col-branches"><strong>POURCENTAGE</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T1']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T1']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T1']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting border-left-strong"><strong><?= $bulletin['pourcentages']['T1']['trimestre'] ?>%</strong></td>
                    
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T2']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T2']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T2']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting border-left-strong"><strong><?= $bulletin['pourcentages']['T2']['trimestre'] ?>%</strong></td>
                    
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T3']['p1'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T3']['p2'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting"><strong><?= $bulletin['pourcentages']['T3']['ex'] ?>%</strong></td>
                    <td colspan="2" class="text-center handwriting border-left-strong"><strong><?= $bulletin['pourcentages']['T3']['trimestre'] ?>%</strong></td>
                    
                    <td colspan="2" class="text-center handwriting border-left-strong"><strong><?= $bulletin['pourcentages']['annuel']['pct'] ?>%</strong></td>
                </tr>

                <?php require VIEWS_PATH . 'modules' . DIRECTORY_SEPARATOR . 'bulletins' . DIRECTORY_SEPARATOR . '_synthese.php'; ?>
            </tbody>
        </table>
    </div>

    <!-- BOUTONS D'ACTION (MASQUÉS À L'IMPRESSION) -->
    <div class="bulletin-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimer le bulletin</button>
        <a href="/gestion_scolaire/bulletins/generer?classe_id=<?= $bulletin['eleve']['classe_id'] ?>&annee_id=<?= $anneeId ?>" class="btn btn-success"><i class="fa-solid fa-file-invoice"></i> Régénérer la classe</a>
        <a href="/gestion_scolaire/bulletins" class="btn btn-outline-primary"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    </div>
</div>

<?php elseif ($classeId > 0 && !empty($eleves)): ?>
    <div class="empty-state" style="padding:40px;background:#fff;border-radius:16px;border:1px solid var(--border-color);"><i class="fa-solid fa-file-invoice"></i><p>Sélectionnez un élève pour afficher son bulletin.</p></div>
<?php elseif ($classeId <= 0): ?>
    <div class="empty-state" style="padding:40px;background:#fff;border-radius:16px;border:1px solid var(--border-color);"><i class="fa-solid fa-chalkboard"></i><p>Sélectionnez une classe puis un élève.</p></div>
<?php else: ?>
    <div class="empty-state" style="padding:40px;background:#fff;border-radius:16px;border:1px solid var(--border-color);"><i class="fa-solid fa-users-slash"></i><p>Aucun élève trouvé.</p></div>
<?php endif; ?>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
