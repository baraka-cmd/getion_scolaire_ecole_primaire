<?php
/**
 * Fichier : public/views/modules/paiements/enregistrer.php
 * Rôle    : Formulaire d'enregistrement d'un paiement
 * Système : École primaire congolaise — Gestion financière
 * Version : 1.0
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('paiements.enregistrer');

global $db;

// Charger le contrôleur
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'PaiementController.php';
$paiementController = new PaiementController();

// Traitement AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $paiementController->handleAjax();
}

// Récupérer les données pour les formulaires
$anneeActiveId = (int) $db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1");
$annees = $db->fetchAll("SELECT id, libelle FROM annees_scolaires ORDER BY date_debut DESC");
$tauxScolariteCdfParUsd = (float) $db->fetchColumn(
    "SELECT taux_cdf_par_usd FROM parametres_ecole WHERE id = 1"
);
if ($tauxScolariteCdfParUsd <= 0) {
    $tauxScolariteCdfParUsd = 2500;
}

// Paramètres
$eleveId = (int) ($_GET['eleve_id'] ?? 0);
$anneeId = (int) ($_GET['annee_id'] ?? $anneeActiveId);
if (!(int) $db->fetchColumn("SELECT COUNT(*) FROM annees_scolaires WHERE id = :id", ['id' => $anneeId])) {
    $anneeId = $anneeActiveId;
}

// Si un élève est présélectionné, récupérer ses infos
$elevePreselectionne = null;
if ($eleveId > 0) {
    $elevePreselectionne = $db->fetchOne(
        "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                c.nom AS classe_nom
         FROM eleves e
         INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.annee_scolaire_id = :annee
         INNER JOIN classes c ON i.classe_id = c.id
         WHERE e.id = :eid AND e.est_actif = 1",
        ['eid' => $eleveId, 'annee' => $anneeId]
    );
}

$pageTitle = 'Enregistrer un paiement';
$pageIcon  = 'fa-money-bill-wave';
$pageDescription = 'Enregistrez un nouveau paiement pour un élève';
$extraCSS = ['modules/paiements.css'];
$extraJS  = ['modules/paiements.js?v=' . filemtime(ASSETS_PATH . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'paiements.js')];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/paiements">Paiements</a>
    <span class="separator">/</span>
    <span class="current">Enregistrer</span>
</nav>

<div class="resultats-header">
    <div class="resultats-header-left">
        <h4><i class="fa-solid fa-money-bill-wave"></i> Enregistrer un paiement</h4>
        <p>Enregistrez un nouveau paiement pour un élève.</p>
    </div>
    <div class="resultats-header-right">
        <a href="<?= e(APP_BASE_PATH) ?>/paiements" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-list"></i> Liste des paiements
        </a>
        <a href="<?= e(APP_BASE_PATH) ?>/paiements/impayes" class="btn btn-outline-warning btn-sm">
            <i class="fa-solid fa-exclamation-circle"></i> Voir les impayés
        </a>
    </div>
</div>

<div id="flashMessage"></div>

<div class="paiement-form-grid">
    <!-- ============================================================
         FORMULAIRE
         ============================================================ -->
    <div class="paiement-form-card">
        <div class="paiement-form-header">
            <div class="paiement-form-icon">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
            <div>
                <h5>Nouveau paiement</h5>
                <p>Remplissez les informations pour enregistrer un paiement</p>
            </div>
        </div>
        <div class="paiement-form-body">
            <form id="formPaiement" autocomplete="off">
                <input type="hidden" id="eleve_id" name="eleve_id" value="<?= $eleveId ?>">
                <input type="hidden" id="annee_scolaire_id" name="annee_id" value="<?= $anneeId ?>">

                <!-- Étape 1 : Année et élève -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fa-solid fa-search"></i></div>
                        <div>
                            <h6 class="form-section-title">1. Année scolaire et élève</h6>
                            <p class="form-section-subtitle">Choisissez l’année concernée, puis l’élève qui remet le paiement.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="anneeChoisie" class="form-label">Année scolaire</label>
                        <select id="anneeChoisie" class="form-control" onchange="changerAnneeFinance(this.value)">
                            <?php foreach ($annees as $annee): ?>
                                <option value="<?= (int) $annee['id'] ?>" <?= $anneeId === (int) $annee['id'] ? 'selected' : '' ?>>
                                    <?= e($annee['libelle']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($elevePreselectionne): ?>
                        <div class="recherche-eleve-container">
                        <div class="eleve-trouve-card"
                             data-eleve-id="<?= (int) $elevePreselectionne['id'] ?>"
                             data-nom="<?= e($elevePreselectionne['prenom'] . ' ' . $elevePreselectionne['nom']) ?>"
                             data-matricule="<?= e($elevePreselectionne['matricule']) ?>"
                             data-classe="<?= e($elevePreselectionne['classe_nom']) ?>"
                             data-sexe="<?= e($elevePreselectionne['sexe']) ?>">
                            <div class="eleve-trouve-avatar <?= $elevePreselectionne['sexe'] === 'M' ? 'male' : 'female' ?>">
                                <?= mb_strtoupper(mb_substr($elevePreselectionne['prenom'], 0, 1) . mb_substr($elevePreselectionne['nom'], 0, 1)) ?>
                            </div>
                            <div class="eleve-trouve-info">
                                <strong><?= e($elevePreselectionne['prenom'] . ' ' . $elevePreselectionne['nom']) ?></strong>
                                <span><?= e($elevePreselectionne['matricule']) ?> — <?= e($elevePreselectionne['classe_nom']) ?></span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="changerEleve()">
                                <i class="fa-solid fa-rotate"></i> Changer
                            </button>
                        </div>
                        </div>
                    <?php else: ?>
                        <div class="recherche-eleve-container">
                            <div class="search-wrapper">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="rechercheEleve" class="form-control" 
                                       placeholder="Rechercher par nom, prénom ou matricule..."
                                       autocomplete="off">
                            </div>
                            <div id="resultatsRecherche" class="resultats-recherche"></div>
                            <small class="form-help">
                                <i class="fa-solid fa-info-circle"></i> Tapez au moins 2 caractères pour lancer la recherche
                            </small>
                        </div>
                    <?php endif; ?>
                </div>

                <hr class="form-divider">

                <!-- Étape 2 : Créance à régler -->
                <div class="form-section" id="sectionFrais" style="<?= $elevePreselectionne ? '' : 'display:none;' ?>">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fa-solid fa-tags"></i></div>
                        <div>
                            <h6 class="form-section-title">2. Frais concerné</h6>
                            <p class="form-section-subtitle">Indiquez à quel frais affecter cette somme. Un paiement partiel est accepté.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="creance_id" class="form-label">
                            <i class="fa-solid fa-tag"></i> Frais à payer <span class="required">*</span>
                        </label>
                        <select id="creance_id" name="creance_id" class="form-control" required>
                            <option value="">— Sélectionnez d’abord un élève —</option>
                        </select>
                    </div>

                    <div class="paiement-montant-attendu" id="montantAttendu" style="display:none;">
                        <div class="montant-attendu-label">Montant prévu · déjà payé · reste à payer</div>
                        <div class="montant-attendu-valeur" id="montantAttenduValeur">—</div>
                    </div>
                </div>

                <!-- Étape 3 : Montant et mode -->
                <div class="form-section" id="sectionMontant" style="display:none;">
                    <div class="form-section-header">
                        <div class="form-section-icon"><i class="fa-solid fa-calculator"></i></div>
                        <div>
                            <h6 class="form-section-title">3. Somme encaissée</h6>
                            <p class="form-section-subtitle">Saisissez le montant réellement remis par le parent et son moyen de paiement.</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="montant_recu" class="form-label">
                                <i class="fa-solid fa-money-bill"></i> Montant remis <span class="required">*</span>
                            </label>
                            <div class="input-icon-wrapper">
                                <input type="number" id="montant_recu" name="montant_recu" class="form-control"
                                       placeholder="Ex. 10" min="0.01" step="0.01" required>
                            </div>
                            <small class="form-help">Un paiement partiel est permis; après conversion, le montant ne peut pas dépasser le reste à payer affiché.</small>
                            <small id="montantConvertiScolarite" class="form-help" style="display:none;"></small>
                        </div>
                        <div class="form-group">
                            <label for="devise_recue" class="form-label">Monnaie remise</label>
                            <select id="devise_recue" name="devise_recue" class="form-control" required>
                                <option value="USD">Dollar américain (USD)</option>
                                <option value="CDF">Franc congolais (CDF)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement" class="form-label">
                                <i class="fa-solid fa-credit-card"></i> Moyen de paiement <span class="required">*</span>
                            </label>
                            <select id="mode_paiement" name="mode_paiement" class="form-control" required>
                                <option value="Espèces">Espèces</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Chèque">Chèque</option>
                                <option value="Virement bancaire">Virement bancaire</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="tauxChangeGroup">
                        <label for="taux_cdf_par_usd" class="form-label">Taux du jour : nombre de CDF pour 1 USD</label>
                        <input type="number" id="taux_cdf_par_usd" name="taux_cdf_par_usd" class="form-control"
                               min="0.0001" step="0.0001" placeholder="Ex. 2 800">
                        <small class="form-help">À remplir seulement si la monnaie remise diffère de celle du frais. Saisissez le taux convenu ce jour-là; il sera conservé sur le reçu.</small>
                    </div>
                    <div class="form-group" id="tauxScolariteInfo" style="display:none;">
                        <div class="paiement-montant-attendu">
                            <div class="montant-attendu-label">Taux paramétré pour la scolarité</div>
                            <div class="montant-attendu-valeur">1 USD = <?= number_format($tauxScolariteCdfParUsd, 4, ',', ' ') ?> CDF</div>
                        </div>
                        <small class="form-help">Le taux est appliqué automatiquement aux paiements de scolarité reçus en CDF et inscrit sur le reçu.</small>
                    </div>
                    <input type="hidden" id="taux_scolarite_configure" value="<?= e($tauxScolariteCdfParUsd) ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="date_versement" class="form-label">Date de réception <span class="required">*</span></label>
                            <input type="date" id="date_versement" name="date_versement" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reference_paiement" class="form-label">
                            <i class="fa-solid fa-hashtag"></i> Référence / N° transaction <span class="form-label-optional">(facultatif)</span>
                        </label>
                        <div class="input-icon-wrapper">
                            <span class="input-icon"><i class="fa-solid fa-barcode"></i></span>
                            <input type="text" id="reference_paiement" name="reference_paiement" class="form-control" 
                                   placeholder="Ex: N° chèque, référence mobile money...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="commentaire" class="form-label">
                            <i class="fa-solid fa-comment"></i> Commentaire <span class="form-label-optional">(facultatif)</span>
                        </label>
                        <textarea id="commentaire" name="commentaire" class="form-control" rows="2" 
                                  placeholder="Note ou observation sur ce paiement..."></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="paiement-form-footer">
            <a href="<?= e(APP_BASE_PATH) ?>/paiements" class="btn btn-outline-primary">
                <i class="fa-solid fa-xmark"></i> Annuler
            </a>
            <button type="button" class="btn btn-primary btn-lg" id="btnEnregistrerPaiement" style="display:none;">
                <i class="fa-solid fa-save"></i> Enregistrer et générer le reçu
            </button>
        </div>
    </div>

    <!-- ============================================================
         RÉCAPITULATIF
         ============================================================ -->
    <div>
        <!-- Dernier paiement de l'élève -->
        <div class="info-card-custom" id="historiqueEleve" style="display:none;">
            <div class="info-card-custom-header">
                <div class="info-card-custom-icon blue"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <h6>Historique des paiements</h6>
            </div>
            <div class="info-card-custom-body" id="historiqueEleveContent">
                <p style="font-size:0.82rem;color:var(--color-gray-500);">Sélectionnez un élève pour voir son historique.</p>
            </div>
        </div>

        <!-- Guide -->
        <div class="info-card-custom mt-4">
            <div class="info-card-custom-header">
                <div class="info-card-custom-icon orange"><i class="fa-solid fa-circle-info"></i></div>
                <h6>Guide</h6>
            </div>
            <div class="info-card-custom-body">
                <ul class="info-list">
                    <li>Choisissez l'<strong>année scolaire</strong>, puis recherchez l’élève par nom ou matricule.</li>
                    <li>Choisissez le <strong>frais à régler</strong>. L’année entière ou le paiement unique est indiqué dans la liste.</li>
                    <li>Le récapitulatif affiche, dans cet ordre, le montant prévu, la somme déjà payée et le reste à payer.</li>
                    <li>Indiquez la somme remise, la monnaie reçue et le moyen de paiement. La monnaie est présélectionnée selon le frais.</li>
                    <li>Pour la scolarité, le taux CDF/USD est configuré dans Paramètres et appliqué automatiquement. Les autres frais gardent leur fonctionnement actuel.</li>
                    <li>La référence et le commentaire sont facultatifs. Chaque versement crée un reçu distinct et traçable.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODALE : REÇU GÉNÉRÉ
     ============================================================ -->
<div class="modal-overlay" id="modalRecu">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h5><i class="fa-solid fa-receipt"></i> Paiement enregistré</h5>
            <button type="button" class="modal-close" onclick="closeModalRecu()">&times;</button>
        </div>
        <div class="modal-body" id="modalRecuContent">
            <!-- Rempli dynamiquement -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary" onclick="closeModalRecu()">
                <i class="fa-solid fa-xmark"></i> Fermer
            </button>
            <a href="#" class="btn btn-primary" id="btnVoirRecu" target="_blank">
                <i class="fa-solid fa-print"></i> Voir / Imprimer le reçu
            </a>
        </div>
    </div>
</div>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>