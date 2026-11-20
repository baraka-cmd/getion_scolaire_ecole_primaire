<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('absences.marquer');
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AttendanceRegisterController.php';

$controller = new AttendanceRegisterController();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAjax()) {
    $controller->handleAjax();
    exit;
}

$annees = $controller->getAnnees();
$anneeActiveId = $controller->getAnneeActiveId();
$aujourdhui = date('Y-m-d');
$anneeSelectionnee = null;
foreach ($annees as $annee) {
    if ($annee['date_debut'] <= $aujourdhui && $annee['date_fin'] >= $aujourdhui) {
        $anneeSelectionnee = $annee;
        break;
    }
}
$anneeCouranteDisponible = $anneeSelectionnee !== null;
if (!$anneeSelectionnee) {
    foreach ($annees as $annee) {
        if ((int) $annee['id'] === $anneeActiveId) {
            $anneeSelectionnee = $annee;
            break;
        }
    }
}
if (!$anneeSelectionnee && $annees) {
    $anneeSelectionnee = $annees[0];
}
$anneeId = (int) ($anneeSelectionnee['id'] ?? 0);

$classes = $anneeCouranteDisponible && $anneeId > 0 ? $controller->getClasses($anneeId) : [];
$classeId = (int) ($_GET['classe_id'] ?? 0);
$classeIds = array_map('intval', array_column($classes, 'id'));
if (!in_array($classeId, $classeIds, true)) {
    $classeId = $classes ? (int) $classes[0]['id'] : 0;
}

$dateAppel = $aujourdhui;

$pointage = $anneeCouranteDisponible && $classeId > 0
    ? $controller->getPointage($classeId, $dateAppel)
    : null;
$appelValide = $pointage && $pointage['appel'] && $pointage['appel']['statut'] === 'valide';
$congeManuel = $pointage && $pointage['appel'] && $pointage['appel']['statut'] === 'conge';
$dateLongue = ucfirst(mb_strtolower(jourEnFrancais($dateAppel)))
    . ' ' . date('j', strtotime($dateAppel))
    . ' ' . mb_strtolower(moisEnFrancais((int) date('n', strtotime($dateAppel))))
    . ' ' . date('Y', strtotime($dateAppel));

$pageTitle = 'Appel quotidien';
$pageIcon = 'fa-calendar-check';
$pageDescription = 'Pointage quotidien des élèves et validation des absences.';
$extraCSS = ['modules/pointage-presences.css'];
$extraJS = ['modules/pointage-presences.js'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(rtrim(BASE_URL, '/')) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(rtrim(BASE_URL, '/')) ?>/absences">Absences</a>
    <span class="separator">/</span>
    <span class="current">Appel quotidien</span>
</nav>

<section class="attendance-page">
    <header class="attendance-heading">
        <div>
            <p class="attendance-eyebrow">Présences &amp; discipline</p>
            <h1>Appel quotidien</h1>
            <p>Tous les élèves sont cochés par défaut. Décochez seulement ceux qui sont absents, puis validez l’appel.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= e(rtrim(BASE_URL, '/')) ?>/absences/registre">
            <i class="fa-solid fa-book-open"></i> Registre annuel
        </a>
    </header>

    <div id="pointageMessage" class="pointage-message" role="status" aria-live="polite"></div>

    <form class="attendance-filters" method="get" action="<?= e(rtrim(BASE_URL, '/')) ?>/absences/appel">
        <?php if ($anneeCouranteDisponible): ?>
            <input type="hidden" name="annee_id" value="<?= $anneeId ?>">
        <?php endif; ?>
        <div class="filter-readonly">
            <span>Année scolaire en cours</span>
            <strong><?= e($anneeCouranteDisponible ? $anneeSelectionnee['libelle'] : 'À configurer') ?></strong>
        </div>
        <?php if ($classes): ?>
            <label>
                <span>Classe</span>
                <select name="classe_id" onchange="this.form.submit()">
                    <?php foreach ($classes as $classe): ?>
                        <option value="<?= (int) $classe['id'] ?>" <?= (int) $classe['id'] === $classeId ? 'selected' : '' ?>>
                            <?= e($classe['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>
            <span>Date de l’appel (automatique)</span>
            <input class="automatic-date-display" type="text" value="<?= e($dateLongue) ?>" readonly aria-readonly="true">
        </label>
    </form>

    <?php if (!$anneeCouranteDisponible): ?>
        <div class="attendance-empty">
            <i class="fa-solid fa-calendar-plus"></i>
            <h2>Aucune année scolaire en cours pour aujourd’hui</h2>
            <p>Créez ou activez l’année scolaire qui couvre le <?= e($dateLongue) ?> pour pouvoir faire l’appel.</p>
            <a class="btn btn-outline-primary" href="<?= e(rtrim(BASE_URL, '/')) ?>/annees-scolaires">Gérer les années scolaires</a>
        </div>
    <?php elseif (!$classes): ?>
        <div class="attendance-empty">
            <i class="fa-solid fa-users"></i>
            <h2>Aucune classe disponible</h2>
            <p>Vérifiez l’année scolaire sélectionnée ou l’affectation de votre compte enseignant.</p>
        </div>
    <?php elseif (!$pointage['eleves']): ?>
        <div class="attendance-empty">
            <i class="fa-solid fa-user-slash"></i>
            <h2>Aucun élève inscrit</h2>
            <p>Cette classe ne contient aucun élève actif pour l’année scolaire choisie.</p>
        </div>
    <?php elseif ($pointage['fermeture']): ?>
        <section class="closure-notice" aria-live="polite"
            data-classe-id="<?= $classeId ?>" data-date="<?= e($dateAppel) ?>">
            <i class="fa-solid fa-calendar-xmark"></i>
            <div>
                <h2>Pas d’appel pour cette journée</h2>
                <p><?= e($pointage['fermeture']) ?></p>
                <?php if ($congeManuel): ?>
                    <button type="button" class="btn btn-outline-primary" id="annulerConge">
                        Annuler le congé manuel
                    </button>
                <?php elseif ($pointage['jour_planifie'] === false && !$appelValide): ?>
                    <small>Cette date n’est pas prévue comme jour de classe dans l’emploi du temps.</small>
                <?php else: ?>
                    <small>Cette fermeture provient du calendrier scolaire et ne peut pas être annulée ici.</small>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="attendance-card"
            data-classe-id="<?= $classeId ?>"
            data-date="<?= e($dateAppel) ?>"
            data-annee-id="<?= $anneeId ?>">
            <div class="attendance-card-heading">
                <div>
                    <h2><?= e($classes[array_search($classeId, $classeIds, true)]['nom']) ?></h2>
                    <p><?= e($dateLongue) ?></p>
                </div>
                <div class="attendance-status <?= $appelValide ? 'is-validated' : 'is-pending' ?>">
                    <i class="fa-solid <?= $appelValide ? 'fa-circle-check' : 'fa-hourglass-half' ?>"></i>
                    <?= $appelValide ? 'Appel validé' : 'Appel à valider' ?>
                </div>
            </div>

            <div class="attendance-toolbar">
                <label class="attendance-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="rechercheEleve" placeholder="Rechercher un élève…" autocomplete="off">
                </label>
                <div class="attendance-count">
                    <strong id="compteurPresents">0</strong>
                    <span>présent(s) sur <?= count($pointage['eleves']) ?></span>
                </div>
            </div>
            <div class="attendance-student-list" id="listeEleves">
                <?php foreach ($pointage['eleves'] as $eleve):
                    $dejaPresent = !$eleve['pointage'] || $eleve['pointage']['type_absence'] === 'Présent';
                    $texteEleve = trim($eleve['nom'] . ' ' . $eleve['prenom']);
                ?>
                    <label class="attendance-student" data-search="<?= e(mb_strtolower($texteEleve . ' ' . $eleve['matricule'])) ?>">
                        <input type="checkbox" class="presence-checkbox" value="<?= (int) $eleve['id'] ?>"
                            <?= $dejaPresent ? 'checked' : '' ?>>
                        <span class="attendance-checkmark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        <span class="student-identity">
                            <strong><?= e($texteEleve) ?></strong>
                            <small><?= e($eleve['matricule'] ?: 'Sans matricule') ?></small>
                        </span>
                        <?php if ($eleve['pointage'] && $eleve['pointage']['type_absence'] === 'Absence' && (int) $eleve['pointage']['est_justifiee'] === 1): ?>
                            <span class="student-note">Absence justifiée</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <footer class="attendance-actions">
                <button type="button" class="btn btn-outline-primary" id="ouvrirModalConge">
                    <i class="fa-solid fa-calendar-xmark"></i> Déclarer un congé
                </button>
                <button type="button" class="btn btn-primary" id="validerAppel">
                    <i class="fa-solid fa-check"></i>
                    <?= $appelValide ? 'Enregistrer les modifications' : 'Valider l’appel' ?>
                </button>
            </footer>
        </section>
    <?php endif; ?>
</section>

<dialog class="conge-dialog" id="dialogConge">
    <form method="dialog" id="formConge">
        <button class="dialog-close" value="cancel" aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button>
        <h2>Déclarer un jour de congé</h2>
        <p>Aucun élève ne sera marqué absent. La date sera barrée en rouge dans le registre.</p>
        <label for="motifConge">Motif du congé</label>
        <input id="motifConge" name="motif" maxlength="255" required placeholder="Ex. journée fériée, fermeture exceptionnelle">
        <div class="dialog-actions">
            <button type="button" class="btn btn-outline-primary" id="annulerDialogConge">Retour</button>
            <button type="button" class="btn btn-primary" id="confirmerConge">Confirmer le congé</button>
        </div>
    </form>
</dialog>

<script>
    window.pointageCsrfName = <?= json_encode(CSRF_TOKEN_NAME) ?>;
</script>
<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
