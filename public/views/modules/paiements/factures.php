<?php
/**
 * Fichier : public/views/modules/paiements/factures.php
 * Rôle    : Liste des reçus/factures générés avec filtres et recherche
 * Système : École primaire congolaise — Gestion financière
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('paiements.consulter');

global $db;

$anneeActiveId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
$annees = $db->fetchAll("SELECT id, libelle FROM annees_scolaires ORDER BY date_debut DESC");
$anneeId = (int) ($_GET['annee_id'] ?? $anneeActiveId);
if ($anneeId <= 0 || !(int) $db->fetchColumn("SELECT COUNT(*) FROM annees_scolaires WHERE id = :id", ['id' => $anneeId])) {
    $anneeId = $anneeActiveId;
}
$toutesAnnees = (int) ($_GET['annee_id'] ?? $anneeActiveId) === 0;
$eleveIdFiltre = max(0, (int) ($_GET['eleve_id'] ?? 0));
$classes = $toutesAnnees
    ? $db->fetchAll(
        "SELECT c.id, CONCAT(c.nom, ' · ', a.libelle) AS nom
         FROM classes c
         INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
         ORDER BY a.date_debut DESC, c.niveau"
    )
    : $db->fetchAll(
        "SELECT id, nom FROM classes WHERE annee_scolaire_id = :annee ORDER BY niveau",
        ['annee' => $anneeId]
    );

$classeId = (int) ($_GET['classe_id'] ?? 0);
$dateDebut = trim((string) ($_GET['date_debut'] ?? ''));
$dateFin = trim((string) ($_GET['date_fin'] ?? ''));
$recherche = trim((string) ($_GET['recherche'] ?? ''));
$statutFiltre = (string) ($_GET['statut'] ?? '');
$modeFiltre = trim((string) ($_GET['mode'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$resetQuery = ['annee_id' => $toutesAnnees ? 0 : $anneeId];
if ($eleveIdFiltre > 0) {
    $resetQuery['eleve_id'] = $eleveIdFiltre;
}

$where = [];
$params = [];
if (!$toutesAnnees) {
    $where[] = 'va.annee_scolaire_id = :annee';
    $params['annee'] = $anneeId;
}
if ($eleveIdFiltre > 0) {
    $where[] = 'e.id = :eleve_id';
    $params['eleve_id'] = $eleveIdFiltre;
}

if ($classeId > 0) {
    $where[] = 'c.id = :classe';
    $params['classe'] = $classeId;
}

if ($dateDebut !== '') {
    $where[] = 'v.date_versement >= :debut';
    $params['debut'] = $dateDebut;
}

if ($dateFin !== '') {
    $where[] = 'v.date_versement < DATE_ADD(:fin, INTERVAL 1 DAY)';
    $params['fin'] = $dateFin;
}

if ($recherche !== '') {
    $where[] = '(e.nom LIKE :r1 OR e.prenom LIKE :r2 OR e.matricule LIKE :r3 OR v.numero_recu LIKE :r4)';
    $term = '%' . $recherche . '%';
    $params['r1'] = $term;
    $params['r2'] = $term;
    $params['r3'] = $term;
    $params['r4'] = $term;
}
if ($statutFiltre === 'Enregistré') {
    $where[] = 'an.id IS NULL';
} elseif ($statutFiltre === 'Annulé' || $statutFiltre === 'Remboursé') {
    $where[] = 'an.type = :statut';
    $params['statut'] = $statutFiltre === 'Annulé' ? 'annulation' : 'remboursement';
}
if ($modeFiltre !== '') {
    $where[] = 'v.mode_paiement = :mode';
    $params['mode'] = $modeFiltre;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joins = "FROM finance_versements v
     INNER JOIN (
         SELECT a.versement_id, MIN(cr.annee_scolaire_id) AS annee_scolaire_id,
                GROUP_CONCAT(DISTINCT CONCAT(cr.libelle_snapshot, ' · ', cr.periode_code)
                    ORDER BY cr.libelle_snapshot, cr.periode_code SEPARATOR ', ') AS frais_libelles
         FROM finance_affectations a
         INNER JOIN finance_creances cr ON cr.id = a.creance_id
         GROUP BY a.versement_id
     ) va ON va.versement_id = v.id
     INNER JOIN eleves e ON e.id = v.eleve_id
     INNER JOIN inscriptions i ON i.eleve_id = e.id AND i.annee_scolaire_id = va.annee_scolaire_id
     INNER JOIN classes c ON i.classe_id = c.id
     LEFT JOIN finance_annulations an ON an.versement_id = v.id
     LEFT JOIN utilisateurs u ON v.enregistre_par = u.id";

$totalEleves = (int) $db->fetchColumn(
    "SELECT COUNT(DISTINCT e.id) {$joins} {$whereClause}",
    $params
);
$parPage = max(1, (int) ITEMS_PER_PAGE);
$pages = max(1, (int) ceil($totalEleves / $parPage));
$page = min($page, $pages);
$offset = ($page - 1) * $parPage;
$groupes = $db->fetchAll(
    "SELECT e.id AS eleve_id, e.matricule, e.nom, e.prenom,
            GROUP_CONCAT(DISTINCT c.nom ORDER BY c.nom SEPARATOR ', ') AS classe_nom,
            COUNT(DISTINCT v.id) AS nombre_recus,
            SUM(CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END) AS recu_usd,
            SUM(CASE WHEN v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END) AS recu_cdf,
            DATE_FORMAT(MAX(v.date_versement), '%d/%m/%Y') AS dernier_versement,
            MAX(v.date_versement) AS dernier_versement_tri
     {$joins}
     {$whereClause}
     GROUP BY e.id, e.matricule, e.nom, e.prenom
     ORDER BY dernier_versement_tri DESC, e.nom, e.prenom
     LIMIT :limit OFFSET :offset",
    array_merge($params, ['limit' => $parPage, 'offset' => $offset])
);
$totalResume = $db->fetchOne(
    "SELECT COUNT(v.id) AS total_recus,
            SUM(CASE WHEN an.id IS NULL AND v.devise_recue = 'USD' THEN v.montant_recu ELSE 0 END) AS total_usd,
            SUM(CASE WHEN an.id IS NULL AND v.devise_recue = 'CDF' THEN v.montant_recu ELSE 0 END) AS total_cdf,
            SUM(CASE WHEN YEAR(v.date_versement) = YEAR(CURDATE())
                      AND MONTH(v.date_versement) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS recus_mois,
            SUM(CASE WHEN an.id IS NOT NULL THEN 1 ELSE 0 END) AS corrections
     {$joins}
     {$whereClause}",
    $params
);
$totalFactures = (int) ($totalResume['total_recus'] ?? 0);
$totalUsd = (float) ($totalResume['total_usd'] ?? 0);
$totalCdf = (float) ($totalResume['total_cdf'] ?? 0);
$nbFacturesMois = (int) ($totalResume['recus_mois'] ?? 0);
$nbCorrections = (int) ($totalResume['corrections'] ?? 0);

$factures = [];
$eleveHistorique = null;
$totalResultats = $totalEleves;
if ($eleveIdFiltre > 0) {
    $eleveHistorique = $db->fetchOne(
        "SELECT id, matricule, nom, prenom FROM eleves WHERE id = :id",
        ['id' => $eleveIdFiltre]
    );
    $totalResultats = (int) $db->fetchColumn(
        "SELECT COUNT(DISTINCT v.id) {$joins} {$whereClause}",
        $params
    );
    $pages = max(1, (int) ceil($totalResultats / $parPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $parPage;
    $factures = $db->fetchAll(
        "SELECT v.id, v.numero_recu, v.montant_recu, v.devise_recue, v.date_versement,
                v.mode_paiement, v.reference_paiement, va.frais_libelles,
                CASE WHEN an.type = 'annulation' THEN 'Annulé'
                     WHEN an.type = 'remboursement' THEN 'Remboursé' ELSE 'Enregistré' END AS statut,
                an.numero_contre_ecriture, an.motif AS motif_correction,
                DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
                TIME_FORMAT(v.date_versement, '%H:%i') AS heure_format
         {$joins}
         {$whereClause}
         ORDER BY v.date_versement DESC, v.id DESC
         LIMIT :limit OFFSET :offset",
        array_merge($params, ['limit' => $parPage, 'offset' => $offset])
    );
}

$pageTitle = 'Registre des reçus';
$pageIcon  = 'fa-receipt';
$pageDescription = 'Liste des reçus et factures générés';
$extraCSS = ['modules/paiements.css'];
$extraJS  = ['modules/paiements.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements">Paiements</a>
    <span class="separator">/</span>
    <span class="current">Factures</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-receipt"></i> Registre des reçus</h4>
        <p><?= $eleveHistorique ? 'Reçus individuels de ' . e($eleveHistorique['prenom'] . ' ' . $eleveHistorique['nom']) : 'Un dossier par élève; ouvrez-le pour retrouver chaque pièce de paiement.' ?></p>
    </div>
    <div class="resultats-header-right">
        <?php if ($eleveIdFiltre > 0): ?>
            <a href="<?= e(APP_BASE_PATH) ?>/paiements/factures?annee_id=<?= $toutesAnnees ? 0 : $anneeId ?>" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Retour au registre
            </a>
        <?php endif; ?>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/enregistrer?annee_id=<?= $anneeId ?>" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> Nouveau paiement
        </a>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements?annee_id=<?= $anneeId ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-list"></i> Paiements
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Imprimer
        </button>
    </div>
</div>

<!-- ============================================================
     STATISTIQUES
     ============================================================ -->
<div class="retards-stats mb-4">
    <div class="retard-stat-card">
        <div class="retard-stat-icon blue">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value"><?= number_format($totalFactures, 0, ',', ' ') ?></div>
            <div class="retard-stat-label">Reçus correspondant aux filtres</div>
            <span class="stat-change neutral"><?= $nbFacturesMois ?> ce mois</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon green">
            <i class="fa-solid fa-hand-holding-dollar"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">$ <?= number_format($totalUsd, 2, ',', ' ') ?></div>
            <div class="retard-stat-label">Reçus actifs en USD</div>
            <span class="stat-change neutral">Annulés/remboursés exclus</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon orange">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value">FC <?= number_format($totalCdf, 0, ',', ' ') ?></div>
            <div class="retard-stat-label">Reçus actifs en CDF</div>
            <span class="stat-change neutral"><?= $nbFacturesMois ?> reçu(s) ce mois</span>
        </div>
    </div>
    <div class="retard-stat-card">
        <div class="retard-stat-icon purple">
            <i class="fa-solid fa-credit-card"></i>
        </div>
        <div class="retard-stat-content">
            <div class="retard-stat-value" style="font-size:1.1rem;"><?= $nbCorrections ?></div>
            <div class="retard-stat-label">Reçus corrigés, historique conservé</div>
        </div>
    </div>
</div>

<!-- ============================================================
     FILTRES
     ============================================================ -->
<div class="retards-filters no-print">
    <form method="GET" action="" class="resultats-selector-form" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;width:100%;">
        <div class="filter-group">
            <label><i class="fa-solid fa-calendar-days"></i> Année scolaire</label>
            <select name="annee_id" class="form-control">
                <option value="0" <?= $toutesAnnees ? 'selected' : '' ?>>Toutes les années</option>
                <?php foreach ($annees as $annee): ?>
                    <option value="<?= (int) $annee['id'] ?>" <?= !$toutesAnnees && $anneeId === (int) $annee['id'] ? 'selected' : '' ?>><?= e($annee['libelle']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($eleveIdFiltre > 0): ?>
            <input type="hidden" name="eleve_id" value="<?= $eleveIdFiltre ?>">
        <?php endif; ?>
        <div class="filter-group">
            <label><i class="fa-solid fa-chalkboard"></i> Classe</label>
            <select name="classe_id" class="form-control">
                <option value="">Toutes les classes</option>
                <?php foreach ($classes as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= $classeId === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fa-solid fa-circle-check"></i> Statut</label>
            <select name="statut" class="form-control">
                <option value="">Tous</option>
                <?php foreach (['Enregistré', 'Annulé', 'Remboursé'] as $statut): ?>
                    <option value="<?= e($statut) ?>" <?= $statutFiltre === $statut ? 'selected' : '' ?>><?= e($statut) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fa-solid fa-credit-card"></i> Moyen</label>
            <select name="mode" class="form-control">
                <option value="">Tous les moyens</option>
                <?php foreach (['Espèces', 'Mobile Money', 'Chèque', 'Virement bancaire', 'Autre'] as $mode): ?>
                    <option value="<?= e($mode) ?>" <?= $modeFiltre === $mode ? 'selected' : '' ?>><?= e($mode) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fa-solid fa-calendar"></i> Du</label>
            <input type="date" name="date_debut" class="form-control" value="<?= e($dateDebut) ?>">
        </div>
        <div class="filter-group">
            <label><i class="fa-solid fa-calendar"></i> Au</label>
            <input type="date" name="date_fin" class="form-control" value="<?= e($dateFin) ?>">
        </div>
        <div class="filter-group">
            <label><i class="fa-solid fa-search"></i> Recherche</label>
            <input type="text" name="recherche" class="form-control" placeholder="Nom, matricule, N° reçu..." value="<?= e($recherche) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;">
            <i class="fa-solid fa-filter"></i> Filtrer
        </button>
        <?php if (!empty($classeId) || !empty($dateDebut) || !empty($dateFin) || !empty($recherche) || $statutFiltre !== '' || $modeFiltre !== ''): ?>
            <a href="<?= e(APP_BASE_PATH) ?>/paiements/factures?<?= e(http_build_query($resetQuery)) ?>" class="btn btn-outline-primary btn-sm" style="align-self:flex-end;">
                <i class="fa-solid fa-rotate"></i> Réinitialiser
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- ============================================================
     TABLEAU
     ============================================================ -->
<div class="retards-table-card">
    <div class="table-header-info" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color-light);">
        <?php if ($eleveIdFiltre > 0): ?>
            <span>
                <strong><?= e($eleveHistorique['matricule'] ?? '') ?> · <?= e(($eleveHistorique['prenom'] ?? '') . ' ' . ($eleveHistorique['nom'] ?? '')) ?></strong>
                — <?= $totalResultats ?> reçu(s) correspondant aux filtres
            </span>
            <span style="font-size:0.75rem;color:var(--color-gray-400);">Les reçus sont paginés pour conserver un historique lisible.</span>
        <?php else: ?>
            <span><strong><?= $totalEleves ?></strong> élève(s) — <strong><?= $totalFactures ?></strong> reçu(s)</span>
            <span style="font-size:0.75rem;color:var(--color-gray-400);">
                <i class="fa-solid fa-info-circle"></i> Ouvrez l’historique d’un élève pour consulter chaque reçu.
            </span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <?php if ($eleveIdFiltre > 0): ?>
            <table class="table payment-register-table">
                <thead><tr><th>N° reçu / date</th><th>Frais concernés</th><th>Montant reçu</th><th>Moyen / référence</th><th>Statut</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($factures)): ?>
                        <tr><td colspan="6"><div class="empty-state" style="padding:40px;"><i class="fa-solid fa-receipt"></i><p>Aucun reçu ne correspond à ces filtres.</p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($factures as $f): ?>
                            <tr>
                                <td><strong><?= e($f['numero_recu']) ?></strong><br><small><?= e($f['date_format']) ?> · <?= e($f['heure_format']) ?></small></td>
                                <td><?= e($f['frais_libelles']) ?></td>
                                <td><strong><?= number_format((float) $f['montant_recu'], 2, ',', ' ') ?> <?= e($f['devise_recue']) ?></strong></td>
                                <td><?= e($f['mode_paiement']) ?><?= $f['reference_paiement'] ? '<br><small>' . e($f['reference_paiement']) . '</small>' : '' ?></td>
                                <td>
                                    <span class="badge <?= $f['statut'] === 'Enregistré' ? 'badge-success' : 'badge-danger' ?>"><?= e($f['statut']) ?></span>
                                    <?= $f['numero_contre_ecriture'] ? '<br><small>' . e($f['numero_contre_ecriture']) . '</small>' : '' ?>
                                </td>
                                <td>
                                    <a href="<?= e(APP_BASE_PATH) ?>/paiements/recu?versement_id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Ouvrir ou imprimer le reçu"><i class="fa-solid fa-receipt"></i></a>
                                    <?php if ($f['statut'] === 'Enregistré'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="contreEcrireVersement(<?= (int) $f['id'] ?>)" title="Annuler ou rembourser"><i class="fa-solid fa-rotate-left"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="table payment-register-table">
                <thead><tr><th>Élève</th><th>Classe</th><th>Nombre de reçus</th><th>Montant reçu · USD</th><th>Montant reçu · CDF</th><th>Dernier versement</th><th>Historique</th></tr></thead>
                <tbody>
                    <?php if (empty($groupes)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state" style="padding:40px;">
                                    <i class="fa-solid fa-receipt"></i>
                                    <p>Aucun reçu ne correspond à ces filtres.</p>
                                    <a href="<?= e(APP_BASE_PATH) ?>/paiements/enregistrer?annee_id=<?= $anneeId ?>" class="btn btn-primary btn-sm mt-3">
                                        <i class="fa-solid fa-plus"></i> Enregistrer un paiement
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($groupes as $groupe): ?>
                            <?php
                            $detailQuery = $_GET;
                            unset($detailQuery['page']);
                            $detailQuery['eleve_id'] = (int) $groupe['eleve_id'];
                            $detailQuery['page'] = 1;
                            ?>
                            <tr>
                                <td><span class="badge badge-primary"><?= e($groupe['matricule']) ?></span><br><strong><?= e($groupe['prenom'] . ' ' . $groupe['nom']) ?></strong></td>
                                <td><?= e($groupe['classe_nom']) ?></td>
                                <td><?= (int) $groupe['nombre_recus'] ?> reçu(s)</td>
                                <td><?= number_format((float) $groupe['recu_usd'], 2, ',', ' ') ?> USD</td>
                                <td><?= number_format((float) $groupe['recu_cdf'], 2, ',', ' ') ?> CDF</td>
                                <td><?= e($groupe['dernier_versement']) ?></td>
                                <td>
                                    <a href="<?= e(APP_BASE_PATH) ?>/paiements/factures?<?= e(http_build_query($detailQuery)) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-receipt"></i> Afficher les reçus
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination-wrapper no-print">
            <div class="pagination-info">Page <?= $page ?> / <?= $pages ?> — <?= $totalResultats ?> <?= $eleveIdFiltre > 0 ? 'reçu(s)' : 'élève(s)' ?></div>
            <div class="pagination">
                <?php
                $query = $_GET;
                $startPage = max(1, $page - 2);
                $endPage = min($pages, $page + 2);
                if ($startPage > 1) {
                    $query['page'] = 1;
                    echo '<a href="?' . e(http_build_query($query)) . '">1</a><span>…</span>';
                }
                for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                    $query['page'] = $pageNumber;
                ?>
                    <a href="?<?= e(http_build_query($query)) ?>" class="<?= $pageNumber === $page ? 'active' : '' ?>"><?= $pageNumber ?></a>
                <?php endfor; ?>
                <?php if ($endPage < $pages): $query['page'] = $pages; ?>
                    <span>…</span><a href="?<?= e(http_build_query($query)) ?>"><?= $pages ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>