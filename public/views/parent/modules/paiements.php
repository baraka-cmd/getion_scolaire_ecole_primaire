<?php
/**
 * Fichier : public/views/parent/modules/paiements.php
 * Rôle    : Consultation des paiements — Vue Parent
 * Système : École primaire congolaise — Espace Parent
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('paiements.consulter');

global $db;

// Récupérer le parent connecté
$userId = $_SESSION['utilisateur_id'] ?? 0;
$parentId = (int) $db->fetchColumn(
    "SELECT pt.id FROM parents_tuteurs pt WHERE pt.utilisateur_id = :uid LIMIT 1",
    ['uid' => $userId]
);

if ($parentId <= 0) {
    setFlash('warning', 'Aucun profil parent trouvé. Contactez l\'administration.');
    redirect(APP_BASE_PATH . '/dashboard');
}

// Récupérer les enfants du parent
$anneeActive = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
$enfants = $db->fetchAll(
    "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
            c.nom AS classe_nom, c.niveau AS classe_niveau
     FROM eleve_parent ep
     INNER JOIN eleves e ON ep.eleve_id = e.id AND e.est_actif = 1
     LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.annee_scolaire_id = :annee
     LEFT JOIN classes c ON i.classe_id = c.id
     WHERE ep.parent_id = :pid
     ORDER BY ep.est_tuteur_principal DESC, e.nom",
    ['pid' => $parentId, 'annee' => $anneeActive]
);

if (empty($enfants)) {
    setFlash('info', 'Aucun enfant n\'est actuellement lié à votre compte.');
    redirect(APP_BASE_PATH . '/parent/enfants');
}

// Sélectionner l'enfant
$eleveId = (int) ($_GET['enfant_id'] ?? $enfants[0]['id']);
$enfantAutorise = false;
$selectedEnfant = null;
foreach ($enfants as $enf) {
    if ((int) $enf['id'] === $eleveId) {
        $enfantAutorise = true;
        $selectedEnfant = $enf;
        break;
    }
}
if (!$enfantAutorise) {
    $selectedEnfant = $enfants[0];
    $eleveId = (int) $selectedEnfant['id'];
}

$annees = $db->fetchAll(
    "SELECT a.id, a.libelle FROM annees_scolaires a
     INNER JOIN inscriptions i ON i.annee_scolaire_id = a.id
     WHERE i.eleve_id = :eleve
     GROUP BY a.id, a.libelle, a.date_debut
     ORDER BY a.date_debut DESC",
    ['eleve' => $selectedEnfant['id']]
);
$anneeId = (int) ($_GET['annee_id'] ?? $anneeActive);
$anneeDisponible = false;
foreach ($annees as $annee) {
    if ((int) $annee['id'] === $anneeId) {
        $anneeDisponible = true;
        break;
    }
}
if (!$anneeDisponible && !empty($annees)) {
    $anneeId = (int) $annees[0]['id'];
}
$anneeLibelle = '';
foreach ($annees as $annee) {
    if ((int) $annee['id'] === $anneeId) $anneeLibelle = $annee['libelle'];
}
$classeAnnee = $db->fetchOne(
    "SELECT c.nom AS classe_nom, c.niveau AS classe_niveau
     FROM inscriptions i
     INNER JOIN classes c ON c.id = i.classe_id
     WHERE i.eleve_id = :eleve AND i.annee_scolaire_id = :annee
     LIMIT 1",
    ['eleve' => $selectedEnfant['id'], 'annee' => $anneeId]
);
if ($classeAnnee) {
    $selectedEnfant['classe_nom'] = $classeAnnee['classe_nom'];
    $selectedEnfant['classe_niveau'] = $classeAnnee['classe_niveau'];
}

$fraisActifs = $db->fetchAll(
    "SELECT cr.id AS creance_id, cr.libelle_snapshot AS libelle, cr.periode_code,
            cr.montant_du AS montant_attendu, cr.devise, cr.date_echeance,
            COALESCE(p.montant_paye, 0) AS montant_paye,
            GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) AS reste_a_payer,
            CASE WHEN GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0) = 0 THEN 'Soldé'
                 WHEN COALESCE(p.montant_paye, 0) > 0 THEN 'Partiel' ELSE 'À payer' END AS statut_paiement,
            DATE_FORMAT(p.date_dernier_paiement, '%d/%m/%Y') AS date_paiement_format,
            p.mode_paiement
     FROM finance_creances cr
     LEFT JOIN (
         SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye,
                MAX(v.date_versement) AS date_dernier_paiement,
                SUBSTRING_INDEX(GROUP_CONCAT(v.mode_paiement ORDER BY v.date_versement DESC), ',', 1) AS mode_paiement
         FROM finance_affectations a
         INNER JOIN finance_versements v ON v.id = a.versement_id
         LEFT JOIN finance_annulations an ON an.versement_id = v.id
         WHERE an.id IS NULL
         GROUP BY a.creance_id
     ) p ON p.creance_id = cr.id
     WHERE cr.eleve_id = :eid AND cr.annee_scolaire_id = :aid
     ORDER BY cr.date_echeance IS NULL, cr.date_echeance, cr.libelle_snapshot, cr.periode_code",
    ['eid' => $selectedEnfant['id'], 'aid' => $anneeId]
);

$totaux = ['du_USD' => 0.0, 'du_CDF' => 0.0, 'paye_USD' => 0.0, 'paye_CDF' => 0.0, 'reste_USD' => 0.0, 'reste_CDF' => 0.0];
$nbPayes = 0;
$nbPartiels = 0;
$nbImpayes = 0;

foreach ($fraisActifs as $frais) {
    $devise = $frais['devise'];
    $totaux['du_' . $devise] += (float) $frais['montant_attendu'];
    $totaux['paye_' . $devise] += (float) $frais['montant_paye'];
    $totaux['reste_' . $devise] += (float) $frais['reste_a_payer'];
    if ($frais['statut_paiement'] === 'Soldé') $nbPayes++;
    elseif ($frais['statut_paiement'] === 'Partiel') $nbPartiels++;
    else $nbImpayes++;
}

// Récupérer l'historique détaillé des paiements
$historiquePaiements = $db->fetchAll(
    "SELECT DISTINCT v.id, v.numero_recu, v.montant_recu, v.devise_recue,
            v.mode_paiement, v.reference_paiement, v.date_versement,
            DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
            TIME_FORMAT(v.date_versement, '%H:%i') AS heure_format,
            cr.libelle_snapshot AS frais_libelle, cr.periode_code,
            cr.devise AS devise_creance, a.montant_affecte,
            CASE WHEN an.type = 'remboursement' THEN 'Remboursé'
                 WHEN an.type = 'annulation' THEN 'Annulé' ELSE 'Enregistré' END AS statut,
            an.numero_contre_ecriture
     FROM finance_versements v
     INNER JOIN finance_affectations a ON a.versement_id = v.id
     INNER JOIN finance_creances cr ON cr.id = a.creance_id
     LEFT JOIN finance_annulations an ON an.versement_id = v.id
     WHERE v.eleve_id = :eid AND cr.annee_scolaire_id = :annee
     ORDER BY v.date_versement DESC
     LIMIT 50",
    ['eid' => $selectedEnfant['id'], 'annee' => $anneeId]
);
$tauxPaiementUsd = $totaux['du_USD'] > 0 ? round($totaux['paye_USD'] / $totaux['du_USD'] * 100) : 0;
$tauxPaiementCdf = $totaux['du_CDF'] > 0 ? round($totaux['paye_CDF'] / $totaux['du_CDF'] * 100) : 0;

$pageTitle = 'Paiements — ' . e($selectedEnfant['prenom'] . ' ' . $selectedEnfant['nom']);
$pageIcon  = 'fa-money-bill-wave';
$pageDescription = 'Suivi des paiements et frais scolaires';
$extraCSS = ['modules/paiements.css'];
$extraJS  = [];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/parent/enfants">Mes enfants</a>
    <span class="separator">/</span>
    <span class="current">Paiements — <?= e($selectedEnfant['prenom'] . ' ' . $selectedEnfant['nom']) ?></span>
</nav>

<!-- EN-TÊTE -->
<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-money-bill-wave"></i> Paiements — <?= e($selectedEnfant['prenom'] . ' ' . $selectedEnfant['nom']) ?></h4>
        <p>
            <i class="fa-solid fa-graduation-cap"></i> <?= e($selectedEnfant['matricule']) ?> — 
            <i class="fa-solid fa-chalkboard"></i> <?= e($selectedEnfant['classe_nom'] ?? 'Non inscrit') ?>
        </p>
    </div>
    <div class="resultats-header-right">
        <form method="get" class="d-flex gap-sm align-items-center">
            <input type="hidden" name="enfant_id" value="<?= (int) $selectedEnfant['id'] ?>">
            <label for="anneeScolaireParent" class="mb-0">Année :</label>
            <select id="anneeScolaireParent" name="annee_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($annees as $annee): ?>
                    <option value="<?= (int) $annee['id'] ?>" <?= $anneeId === (int) $annee['id'] ? 'selected' : '' ?>>
                        <?= e($annee['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (count($enfants) > 1): ?>
        <select id="enfantSelector" class="form-control" style="width:250px;" onchange="changerEnfant(this.value)">
            <?php foreach ($enfants as $enf): ?>
                <option value="<?= $enf['id'] ?>" <?= $selectedEnfant['id'] === (int) $enf['id'] ? 'selected' : '' ?>>
                    <?= e($enf['prenom'] . ' ' . $enf['nom']) ?> — <?= e($enf['classe_nom'] ?? 'Non inscrit') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <a href="<?= e(APP_BASE_PATH) ?>/parent/enfants" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Imprimer
        </button>
    </div>
</div>

<!-- ============================================================
     STATISTIQUES FINANCIÈRES
     ============================================================ -->
<div class="stats-grid mb-4">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fa-solid fa-calculator"></i></div>
        <div class="stat-content">
            <div class="stat-value">$ <?= number_format($totaux['du_USD'], 2, ',', ' ') ?></div>
            <div class="stat-label">Dû · USD</div>
            <span class="stat-change neutral"><?= count($fraisActifs) ?> créance(s) · <?= e($anneeLibelle) ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div class="stat-content">
            <div class="stat-value">$ <?= number_format($totaux['reste_USD'], 2, ',', ' ') ?></div>
            <div class="stat-label">Solde ouvert · USD</div>
            <span class="stat-change down"><?= $tauxPaiementUsd ?>% réglé</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="stat-content">
            <div class="stat-value">FC <?= number_format($totaux['du_CDF'], 0, ',', ' ') ?></div>
            <div class="stat-label">Dû · CDF</div>
            <span class="stat-change neutral"><?= $nbPayes ?> soldée(s) · <?= $nbPartiels ?> partielle(s)</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fa-solid fa-chart-pie"></i></div>
        <div class="stat-content">
            <div class="stat-value">FC <?= number_format($totaux['reste_CDF'], 0, ',', ' ') ?></div>
            <div class="stat-label">Solde ouvert · CDF</div>
            <span class="stat-change down"><?= $tauxPaiementCdf ?>% réglé · <?= $nbImpayes ?> à payer</span>
        </div>
    </div>
</div>

<!-- Progression séparée par devise; aucune conversion implicite des anciens paiements. -->
<div class="card mb-4">
    <div class="card-body">
        <strong><i class="fa-solid fa-chart-line text-primary"></i> Progression des règlements par devise</strong>
        <div class="mt-3">USD · <?= $tauxPaiementUsd ?>% — <?= number_format($totaux['paye_USD'], 2, ',', ' ') ?> / <?= number_format($totaux['du_USD'], 2, ',', ' ') ?> USD</div>
        <div style="width:100%;height:10px;background:#e2e8f0;border-radius:20px;overflow:hidden;margin:6px 0 14px;">
            <div style="width:<?= $tauxPaiementUsd ?>%;height:100%;background:#10b981;border-radius:20px;"></div>
        </div>
        <div>CDF · <?= $tauxPaiementCdf ?>% — <?= number_format($totaux['paye_CDF'], 0, ',', ' ') ?> / <?= number_format($totaux['du_CDF'], 0, ',', ' ') ?> CDF</div>
        <div style="width:100%;height:10px;background:#e2e8f0;border-radius:20px;overflow:hidden;margin:6px 0 0;">
            <div style="width:<?= $tauxPaiementCdf ?>%;height:100%;background:#10b981;border-radius:20px;"></div>
        </div>
    </div>
</div>

<!-- ============================================================
     DÉTAIL DES FRAIS
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header">
        <div class="card-header-left">
            <div class="card-header-icon blue"><i class="fa-solid fa-list-check"></i></div>
            <div>
                <div class="card-header-title">Détail des frais scolaires</div>
                <div class="card-header-subtitle"><?= e($anneeLibelle) ?></div>
            </div>
        </div>
        <div>
            <span class="badge badge-success"><?= $nbPayes ?> payé(s)</span>
            <span class="badge badge-warning ms-1"><?= $nbPartiels ?> partiel(s)</span>
            <span class="badge badge-danger ms-1"><?= $nbImpayes ?> impayé(s)</span>
        </div>
    </div>
    <div class="card-body no-padding">
        <?php if (empty($fraisActifs)): ?>
            <div class="empty-state" style="padding:40px;">
                <i class="fa-solid fa-inbox"></i>
                <p>Aucun frais configuré pour cette année scolaire.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Frais</th>
                            <th>Période</th>
                            <th>Montant dû</th>
                            <th>Affecté</th>
                            <th>Reste</th>
                            <th>Progression</th>
                            <th>Statut</th>
                            <th>Dernier paiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fraisActifs as $frais): 
                            $montantAttendu = (float) $frais['montant_attendu'];
                            $montantPaye = (float) $frais['montant_paye'];
                            $reste = (float) $frais['reste_a_payer'];
                            $pctFrais = $montantAttendu > 0 ? min(100, round(($montantPaye / $montantAttendu) * 100)) : 0;
                            $statutClass = $frais['statut_paiement'] === 'Soldé' ? 'badge-success' : ($frais['statut_paiement'] === 'Partiel' ? 'badge-warning' : 'badge-danger');
                        ?>
                            <tr>
                                <td><strong><?= e($frais['libelle']) ?></strong></td>
                                <td><?= e($frais['periode_code']) ?><br><small><?= $frais['date_echeance'] ? e(date('d/m/Y', strtotime($frais['date_echeance']))) : '—' ?></small></td>
                                <td><strong><?= e($frais['devise']) ?> <?= number_format($montantAttendu, 2, ',', ' ') ?></strong></td>
                                <td><strong style="color:#065f46;"><?= e($frais['devise']) ?> <?= number_format($montantPaye, 2, ',', ' ') ?></strong></td>
                                <td>
                                    <?php if ($reste > 0): ?>
                                        <strong style="color:#ef4444;"><?= e($frais['devise']) ?> <?= number_format($reste, 2, ',', ' ') ?></strong>
                                    <?php else: ?>
                                        <span style="color:#10b981;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="flex:1;height:8px;background:#e2e8f0;border-radius:10px;overflow:hidden;min-width:80px;">
                                            <div style="width:<?= $pctFrais ?>%;height:100%;background:<?= $pctFrais >= 80 ? '#10b981' : ($pctFrais >= 40 ? '#f59e0b' : '#ef4444') ?>;border-radius:10px;"></div>
                                        </div>
                                        <span style="font-size:0.72rem;font-weight:600;"><?= $pctFrais ?>%</span>
                                    </div>
                                </td>
                                <td><span class="badge <?= $statutClass ?>"><?= e($frais['statut_paiement']) ?></span></td>
                                <td>
                                    <?php if ($frais['date_paiement_format']): ?>
                                        <?= e($frais['date_paiement_format']) ?>
                                        <?php if ($frais['mode_paiement']): ?>
                                            <br><small class="text-muted"><?= e($frais['mode_paiement']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     HISTORIQUE DES PAIEMENTS
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header">
        <div class="card-header-left">
            <div class="card-header-icon green"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="card-header-title">Historique des transactions</div>
                <div class="card-header-subtitle"><?= count($historiquePaiements) ?> transaction(s)</div>
            </div>
        </div>
    </div>
    <div class="card-body no-padding">
        <?php if (empty($historiquePaiements)): ?>
            <div class="empty-state" style="padding:40px;">
                <i class="fa-solid fa-receipt"></i>
                <p>Aucune transaction enregistrée pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° reçu</th>
                            <th>Frais / période</th>
                            <th>Reçu</th>
                            <th>Affecté</th>
                            <th>Mode</th>
                            <th>Statut</th>
                            <th>Document</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historiquePaiements as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= e($p['date_format']) ?></strong>
                                    <br><small class="text-muted"><?= e($p['heure_format']) ?></small>
                                </td>
                                <td><span class="badge badge-primary"><?= e($p['numero_recu']) ?></span></td>
                                <td><?= e($p['frais_libelle']) ?><br><small><?= e($p['periode_code']) ?></small></td>
                                <td><strong style="color:#065f46;"><?= e($p['devise_recue']) ?> <?= number_format($p['montant_recu'], 2, ',', ' ') ?></strong></td>
                                <td><?= e($p['devise_creance']) ?> <?= number_format($p['montant_affecte'], 2, ',', ' ') ?></td>
                                <td>
                                    <?php
                                    $modeIcons = [
                                        'Espèces' => 'fa-money-bill',
                                        'Mobile Money' => 'fa-mobile-screen',
                                        'Chèque' => 'fa-money-check',
                                        'Virement bancaire' => 'fa-building-columns'
                                    ];
                                    $modeIcon = $modeIcons[$p['mode_paiement']] ?? 'fa-credit-card';
                                    ?>
                                    <i class="fa-solid <?= $modeIcon ?>"></i> <?= e($p['mode_paiement']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $p['statut'] === 'Enregistré' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= e($p['statut']) ?>
                                        <?php if ($p['numero_contre_ecriture']): ?> · <?= e($p['numero_contre_ecriture']) ?><?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= e(APP_BASE_PATH) ?>/paiements/recu?versement_id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="fa-solid fa-receipt"></i> Reçu
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     ACTIONS
     ============================================================ -->
<div class="d-flex gap-sm justify-content-center mt-4">
    <a href="<?= e(APP_BASE_PATH) ?>/parent/enfants" class="btn btn-outline-primary">
        <i class="fa-solid fa-arrow-left"></i> Retour
    </a>
    <a href="<?= e(APP_BASE_PATH) ?>/parent/bulletins?enfant_id=<?= (int) $selectedEnfant['id'] ?>" class="btn btn-outline-primary">
        <i class="fa-solid fa-file-invoice"></i> Voir le bulletin
    </a>
</div>

<script>
function changerEnfant(enfantId) {
    var url = new URL(window.location.href);
    url.searchParams.set('enfant_id', enfantId);
    window.location.href = url.toString();
}
</script>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>