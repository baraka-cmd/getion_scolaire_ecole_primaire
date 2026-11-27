<?php
/**
 * Fichier : public/views/modules/inscriptions/reinscription.php
 * Rôle    : Réinscription massive des élèves par classe
 */

defined('ACCESS') or define('ACCESS', true);

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('inscriptions.creer');

global $db;

// Charger le service de lecture des années scolaires
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'InscriptionService.php';
$inscriptionService = new InscriptionService();

// Récupérer les années scolaires
$annees = $inscriptionService->getSchoolYears();

// Récupérer les classes de toutes les années pour le sélecteur source
$classesToutes = $db->fetchAll(
    "SELECT c.id, c.nom, c.niveau, a.libelle AS annee_libelle, a.id AS annee_id,
            a.date_debut AS annee_date_debut, a.est_active
     FROM classes c
     INNER JOIN annees_scolaires a ON c.annee_scolaire_id = a.id
     ORDER BY a.date_debut DESC, c.niveau"
);

$anneesDestination = $db->fetchAll(
    "SELECT a.id, a.libelle, a.date_debut, a.est_active
     FROM annees_scolaires a
     WHERE EXISTS (
         SELECT 1 FROM classes c
         WHERE c.annee_scolaire_id = a.id AND c.est_actif = 1
     )
     ORDER BY a.date_debut"
);

$pageTitle = 'Réinscription massive';
$pageIcon  = 'fa-rotate';
$pageDescription = 'Réinscrire les élèves pour la nouvelle année scolaire';
$extraCSS = ['modules/inscriptions.css', 'modules/reinscription.css'];
$extraJS  = [];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(APP_BASE_PATH) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(APP_BASE_PATH) ?>/inscriptions">Inscriptions</a>
    <span class="separator">/</span>
    <span class="current">Réinscription massive</span>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 style="font-weight:600;color:var(--color-dark);margin-bottom:4px;">
            <i class="fa-solid fa-rotate text-primary me-2"></i>Réinscription massive
        </h4>
        <p style="font-size:0.82rem;color:var(--color-gray-500);margin:0;">
            Réinscrivez les élèves d'une classe dans une nouvelle classe pour l'année suivante.
        </p>
    </div>
    <a href="<?= e(APP_BASE_PATH) ?>/inscriptions" class="btn btn-outline-primary btn-sm">
        <i class="fa-solid fa-arrow-left"></i> Retour à la liste
    </a>
</div>

<!-- ============================================================
     CARTE DE RÉINSCRIPTION
     ============================================================ -->
<div class="reinscription-card">
    <div class="reinscription-header">
        <div class="reinscription-icon">
            <i class="fa-solid fa-rotate"></i>
        </div>
        <div>
            <h5>Réinscription des élèves</h5>
            <p>Sélectionnez la classe source et la classe de destination</p>
        </div>
    </div>
    <div class="reinscription-body">

        <!-- Sélecteurs Source → Destination -->
        <div class="reinscription-selectors">
            <div class="form-group">
                <label for="classe_source_id" class="form-label">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Classe source
                </label>
                <select id="classe_source_id" class="form-control" onchange="loadElevesReinscription()">
                    <option value="">— Sélectionnez une classe —</option>
                    <?php foreach ($classesToutes as $classe): ?>
                        <option value="<?= $classe['id'] ?>" 
                                data-annee="<?= $classe['annee_id'] ?>" 
                                data-date="<?= e($classe['annee_date_debut']) ?>"
                                data-niveau="<?= $classe['niveau'] ?>"
                                data-nom="<?= e($classe['nom']) ?>">
                            <?= e($classe['nom']) ?> — <?= e($classe['annee_libelle']) ?>
                            <?= $classe['est_active'] ? '(Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="annee_destination_id" class="form-label">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Année de destination
                </label>
                <select id="annee_destination_id" class="form-control" onchange="loadElevesReinscription()">
                    <option value="">— Sélectionnez l'année cible —</option>
                    <?php foreach ($anneesDestination as $anneeDestination): ?>
                        <option value="<?= (int) $anneeDestination['id'] ?>"
                                data-date="<?= e($anneeDestination['date_debut']) ?>">
                            <?= e($anneeDestination['libelle']) ?>
                            <?= $anneeDestination['est_active'] ? '(Active)' : '(Préparée)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block;margin-top:6px;color:var(--color-gray-500);">
                    Vous pouvez préparer les inscriptions dans une année future sans l’activer.
                </small>
            </div>
        </div>

        <!-- Résumé -->
        <div class="reinscription-summary" id="reinscriptionSummary" style="display:none;">
            <div class="reinscription-summary-item total">
                <i class="fa-solid fa-users"></i>
                <span>Total : <strong id="summaryTotal">0</strong></span>
            </div>
            <div class="reinscription-summary-item passe">
                <i class="fa-solid fa-check-circle"></i>
                <span>Passent : <strong id="summaryPasse">0</strong></span>
            </div>
            <div class="reinscription-summary-item redouble">
                <i class="fa-solid fa-rotate"></i>
                <span>Redoublent : <strong id="summaryRedouble">0</strong></span>
            </div>
            <div class="reinscription-summary-item passe">
                <i class="fa-solid fa-graduation-cap"></i>
                <span>Diplômés : <strong id="summaryDiplome">0</strong></span>
            </div>
            <div class="reinscription-summary-item exclu">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>À compléter : <strong id="summaryIncomplet">0</strong></span>
            </div>
        </div>

        <!-- Liste des élèves (chargée dynamiquement) -->
        <div id="elevesContainer">
            <div class="empty-state" style="padding:40px;">
                <i class="fa-solid fa-rotate"></i>
                <p>Sélectionnez une classe source pour afficher les élèves.</p>
            </div>
        </div>

        <!-- Actions -->
        <div id="reinscriptionActions" style="display:none;margin-top:20px;justify-content:flex-end;gap:10px;">
            <label class="select-all-wrapper" style="margin-right:auto;">
                <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes(this)">
                <span class="select-all-custom"><i class="fa-solid fa-check"></i></span>
                <span class="select-all-label">Tout sélectionner</span>
            </label>
            <button type="button" class="btn btn-outline-primary" onclick="setAllPasse()">
                <i class="fa-solid fa-check-double"></i> Sélectionner les éligibles
            </button>
            <button type="button" class="btn btn-primary btn-lg" id="btnReinscrire" onclick="executerReinscription()">
                <i class="fa-solid fa-rotate"></i> Réinscrire les élèves sélectionnés
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     GUIDE
     ============================================================ -->
<div class="guide-card">
    <div class="guide-card-header">
        <div class="guide-card-icon blue">
            <i class="fa-solid fa-circle-info"></i>
        </div>
        <h6>Fonctionnement de la réinscription</h6>
    </div>
    <div class="guide-card-body">
        <ul class="guide-list">
            <li>Sélectionnez la <strong>classe source</strong> (année précédente ou actuelle).</li>
            <li>Le passage est calculé d’après les notes annuelles : <strong>50 % ou plus</strong> fait passer au niveau supérieur.</li>
            <li>Un passage ou un redoublement n’est possible que si <strong>100 % des notes attendues</strong> sont saisies; une absence officiellement enregistrée compte comme une évaluation renseignée.</li>
            <li>Les destinations sont choisies par élève : niveau supérieur en cas de réussite, même niveau en cas d’échec.</li>
            <li>Un élève de 6e ayant réussi est marqué <strong>Diplômé</strong> et n’est pas réinscrit dans le primaire.</li>
            <li>Les élèves au bulletin incomplet sont bloqués jusqu’à la saisie des notes manquantes.</li>
            <li>L'<strong>historique</strong> des inscriptions précédentes est conservé.</li>
        </ul>
    </div>
</div>

<script>
const inscriptionsUrl = <?= json_encode(APP_BASE_PATH . '/inscriptions', JSON_UNESCAPED_SLASHES) ?>;

// ============================================================
// CHARGEMENT DES ÉLÈVES POUR RÉINSCRIPTION
// ============================================================
function loadElevesReinscription() {
    const classeSourceId = document.getElementById('classe_source_id').value;
    if (!classeSourceId) {
        document.getElementById('elevesContainer').innerHTML = '<div class="empty-state" style="padding:40px;"><i class="fa-solid fa-rotate"></i><p>Sélectionnez une classe source pour afficher les élèves.</p></div>';
        document.getElementById('reinscriptionSummary').style.display = 'none';
        document.getElementById('reinscriptionActions').style.display = 'none';
        return;
    }
    document.getElementById('reinscriptionSummary').style.display = 'none';
    document.getElementById('reinscriptionActions').style.display = 'none';

    const selectedOption = document.getElementById('classe_source_id').selectedOptions[0];
    const anneeId = selectedOption.dataset.annee || '';
    const destinationSelect = document.getElementById('annee_destination_id');
    const sourceDate = selectedOption.dataset.date || '';
    Array.from(destinationSelect.options).forEach(option => {
        option.disabled = Boolean(option.value) && option.dataset.date <= sourceDate;
    });
    const currentDestination = destinationSelect.selectedOptions[0];
    if (!currentDestination || !currentDestination.value || currentDestination.dataset.date <= sourceDate) {
        const nextYear = Array.from(destinationSelect.options).find(option =>
            option.value && option.dataset.date > sourceDate
        );
        destinationSelect.value = nextYear ? nextYear.value : '';
    }
    if (!destinationSelect.value) {
        document.getElementById('elevesContainer').innerHTML = '<div class="empty-state" style="padding:40px;"><i class="fa-solid fa-calendar"></i><p>Aucune année de destination postérieure avec des classes n’est disponible.</p></div>';
        return;
    }

    const formData = new FormData();
    formData.append('action', 'getElevesPourReinscription');
    formData.append('classe_source_id', classeSourceId);
    formData.append('annee_source_id', anneeId);
    formData.append('annee_destination_id', destinationSelect.value);

    document.getElementById('elevesContainer').innerHTML = '<div style="text-align:center;padding:30px;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;color:var(--color-primary);"></i><p style="margin-top:10px;color:var(--color-gray-500);">Chargement des élèves...</p></div>';

    fetch(inscriptionsUrl, {
        method: 'POST', body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderElevesReinscription(data);
            document.getElementById('reinscriptionSummary').style.display = 'flex';
            document.getElementById('reinscriptionActions').style.display = 'flex';
        } else {
            document.getElementById('elevesContainer').innerHTML = '<div class="empty-state" style="padding:40px;"><i class="fa-solid fa-exclamation-circle"></i><p>' + data.message + '</p></div>';
        }
    })
    .catch(err => {
        console.error('Erreur:', err);
        document.getElementById('elevesContainer').innerHTML = '<div class="empty-state" style="padding:40px;"><i class="fa-solid fa-exclamation-circle"></i><p>Erreur de chargement. Vérifiez la console.</p></div>';
    });
}

function renderElevesReinscription(data) {
    const container = document.getElementById('elevesContainer');
    
    if (!data.eleves || data.eleves.length === 0) {
        container.innerHTML = '<div class="empty-state" style="padding:40px;"><i class="fa-solid fa-users"></i><p>Aucun élève trouvé dans cette classe.</p></div>';
        document.getElementById('reinscriptionSummary').style.display = 'none';
        document.getElementById('reinscriptionActions').style.display = 'none';
        return;
    }

    let html = '';
    const classes = data.classes_disponibles || [];
    const niveauSource = parseInt(data.classe_source.niveau || 0, 10);
    const effectifsProjetes = {};
    classes.forEach(c => {
        effectifsProjetes[c.id] = parseInt(c.nb_inscrits, 10);
    });
    data.eleves.forEach(e => {
        const pourcentage = e.pourcentage_annuel === null ? null : parseFloat(e.pourcentage_annuel);
        const decision = e.decision_auto;
        const estDiplome = decision === 'Diplômé';
        const estIncomplet = decision === 'A_COMPLETER';
        const niveauDestination = decision === 'Passe' ? niveauSource + 1 : niveauSource;
        const moyenneClass = pourcentage === null ? '' : (pourcentage >= 50 ? 'reussite' : 'echec');
        const destinations = estDiplome ? [] : classes.filter(c => parseInt(c.niveau, 10) === niveauDestination);
        const destinationParDefaut = destinations.find(c =>
            effectifsProjetes[c.id] < parseInt(c.capacite_max, 10)
        );
        const selectionnable = estDiplome || (!estIncomplet && Boolean(destinationParDefaut));
        const rowClass = estIncomplet ? 'exclu' : (decision === 'Redouble' ? 'redouble' : 'passe');
        let destinationHtml = '';
        if (estDiplome) {
            destinationHtml = '<span class="destination-text">Fin du cycle — diplômé</span>';
        } else if (estIncomplet) {
            destinationHtml = '<span class="destination-text">À compléter : ' + e.notes_completees + '/' + e.notes_attendues + ' notes</span>';
        } else if (destinations.length > 0) {
            const options = destinations.map(c => {
                const selected = destinationParDefaut && String(c.id) === String(destinationParDefaut.id);
                const disabled = effectifsProjetes[c.id] >= parseInt(c.capacite_max, 10) && !selected;
                return '<option value="' + c.id + '"' + (selected ? ' selected' : '') + (disabled ? ' disabled' : '') + '>' +
                    escapeHtml(c.nom) + ' — ' + effectifsProjetes[c.id] + '/' + c.capacite_max + '</option>';
            }).join('');
            destinationHtml = '<select class="form-control destination-select"' + (!destinationParDefaut ? ' disabled' : '') + '>' + options + '</select>';
        } else {
            destinationHtml = '<span class="destination-text">Aucune classe disponible au niveau ' + niveauDestination + '</span>';
        }
        if (selectionnable && !estDiplome && destinationParDefaut) {
            effectifsProjetes[destinationParDefaut.id]++;
        }
        const decisionLabel = estIncomplet
            ? 'Bulletin incomplet'
            : (estDiplome ? 'Diplômé' : (decision === 'Redouble' ? 'Redouble' : 'Passe'));
        const noteResume = e.notes_attendues > 0
            ? e.notes_completees + '/' + e.notes_attendues + ' notes'
            : 'Aucune matière attendue configurée';

        html += `
            <div class="eleve-reinscription-row ${rowClass}" data-inscription-id="${e.inscription_id || ''}" data-decision="${decision}">
                <input type="checkbox" class="eleve-checkbox eleve-reinscription-check" value="${e.id}" ${selectionnable ? 'checked' : 'disabled'} onchange="updateSummary()">
                <div class="eleve-reinscription-info">
                    <div class="eleve-reinscription-name">${escapeHtml(e.prenom)} ${escapeHtml(e.nom)}</div>
                    <div class="eleve-reinscription-matricule">${escapeHtml(e.matricule)} — ${e.sexe === 'M' ? 'Garçon' : 'Fille'}</div>
                </div>
                <div class="eleve-reinscription-moyenne ${moyenneClass}">
                    ${pourcentage !== null
                        ? '<i class="fa-solid fa-' + (pourcentage >= 50 ? 'arrow-up' : 'arrow-down') + '"></i> ' + pourcentage.toFixed(1) + '%'
                        : '<span style="color:var(--color-gray-400);">—</span>'}
                </div>
                <div class="eleve-reinscription-decision">${decisionLabel}<small>${noteResume}</small></div>
                <div class="eleve-reinscription-destination">
                    <i class="fa-solid fa-arrow-right"></i> 
                    ${destinationHtml}
                </div>
            </div>`;
    });

    container.innerHTML = html;
    updateSummary();
}

function updateSummary() {
    let total = 0, passe = 0, redouble = 0, diplome = 0, incomplet = 0;

    document.querySelectorAll('.eleve-reinscription-row').forEach(row => {
        total++;
        const decision = row.dataset.decision;
        if (decision === 'Passe') passe++;
        else if (decision === 'Redouble') redouble++;
        else if (decision === 'Diplômé') diplome++;
        else incomplet++;
    });

    document.getElementById('summaryTotal').textContent = total;
    document.getElementById('summaryPasse').textContent = passe;
    document.getElementById('summaryRedouble').textContent = redouble;
    document.getElementById('summaryDiplome').textContent = diplome;
    document.getElementById('summaryIncomplet').textContent = incomplet;
}

function toggleAllCheckboxes(checkbox) {
    document.querySelectorAll('.eleve-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSummary();
}

function setAllPasse() {
    document.querySelectorAll('.eleve-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
    updateSummary();
    showToast('Tous les élèves éligibles sont sélectionnés.', 'success');
}

// ============================================================
// EXÉCUTION DE LA RÉINSCRIPTION
// ============================================================
async function executerReinscription() {
    const sourceOption = document.getElementById('classe_source_id').selectedOptions[0];
    const selected = [];
    document.querySelectorAll('.eleve-checkbox:checked').forEach(cb => {
        const row = cb.closest('.eleve-reinscription-row');
        selected.push({
            eleve_id: cb.value,
            nouvelle_classe_id: row.dataset.decision === 'Diplômé'
                ? 0
                : (row.querySelector('.destination-select')?.value || 0),
            ancienne_inscription_id: row.dataset.inscriptionId || ''
        });
    });

    if (selected.length === 0) {
        showToast('Veuillez sélectionner au moins un élève.', 'warning');
        return;
    }

    if (!confirm(`Voulez-vous réinscrire ${selected.length} élève(s) ?`)) return;

    const btn = document.getElementById('btnReinscrire');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Réinscription en cours...';

    const formData = new FormData();
    formData.append('action', 'reinscrireMassivement');
    formData.append('eleves', JSON.stringify(selected));
    formData.append('classe_source_id', document.getElementById('classe_source_id').value);
    formData.append('annee_source_id', sourceOption.dataset.annee || '');
    formData.append('annee_destination_id', document.getElementById('annee_destination_id').value);

    try {
        const res = await fetch(inscriptionsUrl, {
            method: 'POST', body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    } catch (err) {
        alert('Erreur de connexion.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Réinscrire les élèves sélectionnés';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type) {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}
</script>

<?php
require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>