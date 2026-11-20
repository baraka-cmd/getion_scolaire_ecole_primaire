<?php
/**
 * Fichier : public/views/modules/paiements/recu.php
 * Rôle    : Reçu de paiement imprimable
 * Système : École primaire congolaise — Gestion financière
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'EleveController.php';
requireLogin();
requirePermission('paiements.consulter');

global $db;

// Paramètres
$versementId = (int) ($_GET['versement_id'] ?? 0);
$paiementId = (int) ($_GET['paiement_id'] ?? 0);
$factureId  = (int) ($_GET['facture_id'] ?? 0);
$isLedgerReceipt = $versementId > 0;

if ($versementId <= 0 && $paiementId <= 0 && $factureId <= 0) {
    setFlash('error', 'Paramètres invalides.');
    redirect(APP_BASE_PATH . '/paiements');
}

// Récupérer le reçu
$recu = null;
if ($versementId > 0) {
    $recu = $db->fetchOne(
        "SELECT v.numero_recu AS numero_facture, e.id AS eleve_id, v.date_versement,
                DATE_FORMAT(v.date_versement, '%d/%m/%Y') AS date_format,
                TIME_FORMAT(v.date_versement, '%H:%i') AS heure_format,
                v.montant_recu, v.devise_recue, v.taux_cdf_par_usd,
                v.mode_paiement, v.reference_paiement, v.commentaire,
                e.matricule, e.nom, e.prenom, e.sexe,
                c.nom AS classe_nom, c.niveau AS classe_niveau,
                cr.annee_scolaire_id, cr.libelle_snapshot AS frais_libelle,
                cr.periode_code, cr.montant_du AS montant_total,
                cr.devise AS devise_creance, a.montant_affecte AS montant_paye,
                GREATEST(cr.montant_du - COALESCE(solde.total_affecte, 0), 0) AS reste_a_payer,
                CASE WHEN an.type = 'remboursement' THEN 'Remboursé'
                     WHEN an.type = 'annulation' THEN 'Annulé'
                     WHEN GREATEST(cr.montant_du - COALESCE(solde.total_affecte, 0), 0) = 0 THEN 'Soldé'
                     ELSE 'Partiel' END AS statut,
                an.type AS type_correction, an.motif AS motif_correction,
                an.numero_contre_ecriture,
                CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom
         FROM finance_versements v
         INNER JOIN finance_affectations a ON a.versement_id = v.id
         INNER JOIN finance_creances cr ON cr.id = a.creance_id
         INNER JOIN eleves e ON e.id = v.eleve_id
         INNER JOIN inscriptions i ON i.eleve_id = e.id
             AND i.annee_scolaire_id = cr.annee_scolaire_id
         INNER JOIN classes c ON c.id = i.classe_id
         LEFT JOIN utilisateurs u ON u.id = v.enregistre_par
         LEFT JOIN finance_annulations an ON an.versement_id = v.id
         LEFT JOIN (
             SELECT af.creance_id, SUM(af.montant_affecte) AS total_affecte
             FROM finance_affectations af
             INNER JOIN finance_versements vv ON vv.id = af.versement_id
             LEFT JOIN finance_annulations aa ON aa.versement_id = vv.id
             WHERE aa.id IS NULL
             GROUP BY af.creance_id
         ) solde ON solde.creance_id = cr.id
         WHERE v.id = :id",
        ['id' => $versementId]
    );
    if ($recu) {
        $anneeScolaire = $db->fetchOne(
            "SELECT libelle FROM annees_scolaires WHERE id = :id",
            ['id' => $recu['annee_scolaire_id']]
        );
    }
} elseif ($factureId > 0) {
    $recu = $db->fetchOne(
        "SELECT f.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom, e.sexe,
                c.nom AS classe_nom, c.niveau AS classe_niveau,
                DATE_FORMAT(f.date_emission, '%d/%m/%Y') AS date_format,
                TIME_FORMAT(f.date_emission, '%H:%i') AS heure_format,
                fl.libelle AS frais_libelle,
                p.mode_paiement, p.montant_total, p.montant_paye, p.statut, p.reference_paiement, p.commentaire,
                CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom
         FROM factures f
         INNER JOIN eleves e ON f.eleve_id = e.id
         INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
         INNER JOIN classes c ON i.classe_id = c.id
         INNER JOIN paiements p ON f.paiement_id = p.id
         INNER JOIN frais_scolaires fl ON p.frais_scolaire_id = fl.id
         LEFT JOIN utilisateurs u ON f.emise_par = u.id
         WHERE f.id = :id",
        ['id' => $factureId]
    );
} elseif ($paiementId > 0) {
    $recu = $db->fetchOne(
        "SELECT f.*, e.id AS eleve_id, e.matricule, e.nom, e.prenom, e.sexe,
                c.nom AS classe_nom, c.niveau AS classe_niveau,
                DATE_FORMAT(f.date_emission, '%d/%m/%Y') AS date_format,
                TIME_FORMAT(f.date_emission, '%H:%i') AS heure_format,
                fl.libelle AS frais_libelle,
                p.mode_paiement, p.montant_total, p.montant_paye, p.statut, p.reference_paiement, p.commentaire,
                CONCAT(u.prenom, ' ', u.nom) AS emis_par_nom
         FROM factures f
         INNER JOIN eleves e ON f.eleve_id = e.id
         INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
         INNER JOIN classes c ON i.classe_id = c.id
         INNER JOIN paiements p ON f.paiement_id = p.id
         INNER JOIN frais_scolaires fl ON p.frais_scolaire_id = fl.id
         LEFT JOIN utilisateurs u ON f.emise_par = u.id
         WHERE f.paiement_id = :pid
         ORDER BY f.date_emission DESC LIMIT 1",
        ['pid' => $paiementId]
    );
}

if (!$recu) {
    setFlash('error', 'Reçu introuvable.');
    redirect(APP_BASE_PATH . '/paiements');
}
if (!(new EleveController())->canCurrentUserAccessStudent((int) $recu['eleve_id'])) {
    http_response_code(404);
    exit('Reçu introuvable.');
}

$ecole = $db->fetchOne("SELECT * FROM parametres_ecole LIMIT 1");
$anneeScolaire = $anneeScolaire ?? $db->fetchOne("SELECT libelle FROM annees_scolaires WHERE id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)");

$pageTitle = 'Reçu N° ' . e($recu['numero_facture']);
$pageIcon  = 'fa-receipt';
$pageDescription = 'Reçu de paiement';
$extraCSS = ['modules/paiements.css'];
$extraJS  = [];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb no-print">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements">Paiements</a>
    <span class="separator">/</span>
    <span class="current">Reçu N° <?= e($recu['numero_facture']) ?></span>
</nav>

<!-- BOUTONS D'ACTION -->
<div class="no-print" style="display:flex;gap:12px;margin-bottom:20px;justify-content:center;flex-wrap:wrap;">
    <button type="button" class="btn btn-primary btn-lg" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Imprimer le reçu
    </button>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements" class="btn btn-outline-primary">
        <i class="fa-solid fa-arrow-left"></i> Retour aux paiements
    </a>
</div>

<!-- ============================================================
     REÇU IMPRIMABLE
     ============================================================ -->
<div class="recu-document" id="recuDocument">
    
    <!-- En-tête -->
    <div class="recu-header">
        <div class="recu-header-top">
            <div class="recu-logo">
                <i class="fa-solid fa-school"></i>
            </div>
            <div class="recu-ecole-info">
                <h2><?= e($ecole['nom_ecole'] ?? 'École Primaire') ?></h2>
                <p><?= e($ecole['devise'] ?? 'Discipline - Travail - Excellence') ?></p>
                <p><?= e($ecole['adresse'] ?? '') ?> — <?= e($ecole['telephone'] ?? '') ?></p>
                <p><?= e($ecole['email'] ?? '') ?></p>
            </div>
            <div class="recu-titre-wrapper">
                <h1 class="recu-titre">REÇU DE PAIEMENT</h1>
                <div class="recu-numero">N° <?= e($recu['numero_facture']) ?></div>
            </div>
        </div>
    </div>

    <!-- Corps -->
    <div class="recu-body">
        <div class="recu-info-grid">
            <!-- Infos élève -->
            <div class="recu-section">
                <div class="recu-section-title">Informations de l'élève</div>
                <div class="recu-field">
                    <span class="recu-field-label">Élève</span>
                    <span class="recu-field-value"><?= e(mb_strtoupper($recu['nom'] . ' ' . $recu['prenom'])) ?></span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Matricule</span>
                    <span class="recu-field-value"><?= e($recu['matricule']) ?></span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Sexe</span>
                    <span class="recu-field-value"><?= $recu['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Classe</span>
                    <span class="recu-field-value"><?= e($recu['classe_nom']) ?> (<?= $recu['classe_niveau'] ?>)</span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Année scolaire</span>
                    <span class="recu-field-value"><?= e($anneeScolaire['libelle'] ?? '') ?></span>
                </div>
            </div>

            <!-- Infos paiement -->
            <div class="recu-section">
                <div class="recu-section-title">Détails du paiement</div>
                <div class="recu-field">
                    <span class="recu-field-label">Type de frais</span>
                    <span class="recu-field-value"><strong><?= e($recu['frais_libelle']) ?></strong>
                        <?= $isLedgerReceipt ? ' · ' . e($recu['periode_code']) : '' ?>
                    </span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Montant total attendu</span>
                    <span class="recu-field-value">
                        <?= $isLedgerReceipt ? e($recu['devise_creance']) : '$' ?>
                        <?= number_format($recu['montant_total'], 2, ',', ' ') ?>
                    </span>
                </div>
                <div class="recu-field highlight">
                    <span class="recu-field-label"><?= $isLedgerReceipt ? 'Montant reçu' : 'Montant payé' ?></span>
                    <span class="recu-field-value recu-montant">
                        <?= $isLedgerReceipt ? e($recu['devise_recue']) : '$' ?>
                        <?= number_format($isLedgerReceipt ? $recu['montant_recu'] : $recu['montant_paye'], 2, ',', ' ') ?>
                    </span>
                </div>
                <?php if ($isLedgerReceipt): ?>
                <div class="recu-field">
                    <span class="recu-field-label">Affecté à la créance</span>
                    <span class="recu-field-value">
                        <?= e($recu['devise_creance']) ?> <?= number_format($recu['montant_paye'], 2, ',', ' ') ?>
                    </span>
                </div>
                <div class="recu-field">
                    <span class="recu-field-label">Solde après ce versement</span>
                    <span class="recu-field-value" style="color:<?= (float) $recu['reste_a_payer'] > 0 ? '#ef4444' : '#10b981' ?>;font-weight:700;">
                        <?= e($recu['devise_creance']) ?> <?= number_format($recu['reste_a_payer'], 2, ',', ' ') ?>
                    </span>
                </div>
                <?php if ($recu['devise_recue'] !== $recu['devise_creance']): ?>
                <div class="recu-field">
                    <span class="recu-field-label">Taux enregistré</span>
                    <span class="recu-field-value">1 USD = <?= number_format($recu['taux_cdf_par_usd'], 4, ',', ' ') ?> CDF</span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="recu-field">
                    <span class="recu-field-label">Reste à payer</span>
                    <?php $reste = $recu['montant_total'] - $recu['montant_paye']; ?>
                    <span class="recu-field-value" style="color:<?= $reste > 0 ? '#ef4444' : '#10b981' ?>;font-weight:700;">
                        $ <?= number_format($reste, 2, ',', ' ') ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="recu-field">
                    <span class="recu-field-label">Statut</span>
                    <span class="recu-field-value">
                        <?php 
                        $statutClass = in_array($recu['statut'], ['Payé', 'Soldé'], true) ? 'recu-statut-paye'
                            : (in_array($recu['statut'], ['Partiel', 'Remboursé', 'Annulé'], true) ? 'recu-statut-partiel' : 'recu-statut-impaye');
                        ?>
                        <span class="recu-statut-badge <?= $statutClass ?>"><?= e($recu['statut']) ?></span>
                    </span>
                </div>
                <?php if ($isLedgerReceipt && !empty($recu['numero_contre_ecriture'])): ?>
                <div class="recu-field">
                    <span class="recu-field-label">Contre-écriture</span>
                    <span class="recu-field-value">
                        <?= e($recu['numero_contre_ecriture']) ?> · <?= e($recu['type_correction']) ?>
                        <br><small><?= e($recu['motif_correction']) ?></small>
                    </span>
                </div>
                <?php endif; ?>
                <div class="recu-field">
                    <span class="recu-field-label">Mode de paiement</span>
                    <span class="recu-field-value"><?= e($recu['mode_paiement']) ?></span>
                </div>
                <?php if (!empty($recu['reference_paiement'])): ?>
                <div class="recu-field">
                    <span class="recu-field-label">Référence</span>
                    <span class="recu-field-value"><?= e($recu['reference_paiement']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($recu['commentaire'])): ?>
                <div class="recu-field">
                    <span class="recu-field-label">Commentaire</span>
                    <span class="recu-field-value"><?= e($recu['commentaire']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Date et émetteur -->
        <div class="recu-meta">
            <div class="recu-meta-item">
                <i class="fa-regular fa-calendar"></i>
                <span>Fait le <?= e($recu['date_format']) ?> à <?= e($recu['heure_format']) ?></span>
            </div>
            <div class="recu-meta-item">
                <i class="fa-solid fa-user"></i>
                <span>Enregistré par : <strong><?= e($recu['emis_par_nom'] ?? '—') ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="recu-signatures">
        <div class="recu-signature-block">
            <div class="recu-signature-line"></div>
            <span>Le Caissier / Comptable</span>
        </div>
        <div class="recu-signature-block">
            <div class="recu-signature-line"></div>
            <span>Le Parent / Tuteur</span>
        </div>
        <div class="recu-signature-block">
            <div class="recu-signature-line"></div>
            <span>Cachet de l'école</span>
        </div>
    </div>

    <!-- Pied -->
    <div class="recu-footer">
        <p><i class="fa-solid fa-shield-halved"></i> Ce reçu est un document officiel. Conservez-le précieusement.</p>
        <p><?= e($ecole['nom_ecole'] ?? 'École Primaire') ?> — <?= e($anneeScolaire['libelle'] ?? '') ?></p>
    </div>
</div>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>