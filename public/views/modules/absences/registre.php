<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
requirePermission('absences.consulter');
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AttendanceRegisterController.php';

$controller = new AttendanceRegisterController();
$annees = $controller->getAnnees();
$anneeActiveId = $controller->getAnneeActiveId();
$anneeId = (int) ($_GET['annee_id'] ?? $anneeActiveId);
$anneeSelectionnee = null;
foreach ($annees as $annee) {
    if ((int) $annee['id'] === $anneeId) {
        $anneeSelectionnee = $annee;
        break;
    }
}
if (!$anneeSelectionnee && $annees) {
    $anneeSelectionnee = $annees[0];
    $anneeId = (int) $anneeSelectionnee['id'];
}
$classes = $anneeId > 0 ? $controller->getClasses($anneeId) : [];
$classeId = (int) ($_GET['classe_id'] ?? 0);
$classeIds = array_map('intval', array_column($classes, 'id'));
if (!in_array($classeId, $classeIds, true)) {
    $classeId = $classes ? (int) $classes[0]['id'] : 0;
}
$dateReferenceDemandee = $_GET['date_reference'] ?? null;
if ($dateReferenceDemandee === '') {
    $dateReferenceDemandee = null;
}
$dateReferenceErreur = null;
$dateReference = date('Y-m-d');
if ($anneeSelectionnee) {
    if ($dateReference < $anneeSelectionnee['date_debut']) {
        $dateReference = '';
    } else {
        $dateReference = min($dateReference, $anneeSelectionnee['date_fin']);
    }
}
if ($dateReferenceDemandee !== null) {
    if (
        is_string($dateReferenceDemandee)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReferenceDemandee)
        && isValidDate($dateReferenceDemandee)
        && $anneeSelectionnee
        && $dateReferenceDemandee >= $anneeSelectionnee['date_debut']
        && $dateReferenceDemandee <= $anneeSelectionnee['date_fin']
    ) {
        $dateReference = $dateReferenceDemandee;
    } else {
        $dateReferenceErreur = 'La date choisie doit être comprise dans les limites de l’année scolaire.';
    }
}
$dateCalcul = $dateReference === '' ? null : $dateReference;
$registre = $classeId > 0
    ? $controller->getRegistre($classeId, $anneeId, $dateCalcul)
    : null;
$ecole = trim((string) ($registre['classe']['nom_ecole'] ?? ''));
$logo = trim((string) ($registre['classe']['logo'] ?? ''));
$logoUrl = $logo !== '' ? rtrim(ASSETS_URL, '/') . '/' . ltrim($logo, '/') : '';
$dateNaissance = static function (?string $date): string {
    return $date ? date('d/m/Y', strtotime($date)) : '';
};

$pageTitle = 'Registre annuel des présences';
$pageIcon = 'fa-book-open';
$pageDescription = 'Registre imprimable des présences, absences et jours de classe.';
$extraCSS = ['modules/registre-presence.css'];

require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<nav class="breadcrumb no-print">
    <a href="<?= e(rtrim(BASE_URL, '/')) ?>/dashboard"><i class="fa-solid fa-house"></i></a>
    <span class="separator">/</span>
    <a href="<?= e(rtrim(BASE_URL, '/')) ?>/absences">Absences</a>
    <span class="separator">/</span>
    <span class="current">Registre annuel</span>
</nav>

<section class="register-shell">
    <header class="register-toolbar no-print">
        <div>
            <p class="register-eyebrow">Présences &amp; discipline</p>
            <h1>Registre annuel</h1>
            <p>Les mois suivent les dates de l’année scolaire sélectionnée. La date d’arrêt permet de prévisualiser les calculs sans modifier les pointages.</p>
        </div>
        <div class="register-toolbar-actions">
            <?php if ($classeId > 0): ?>
                <a class="btn btn-outline-primary" href="<?= e(rtrim(BASE_URL, '/')) ?>/absences/appel?annee_id=<?= $anneeId ?>&amp;classe_id=<?= $classeId ?>">
                    <i class="fa-solid fa-check"></i> Faire l’appel
                </a>
                <button class="btn btn-primary" type="button" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Imprimer le registre
                </button>
            <?php endif; ?>
        </div>
    </header>

    <form class="register-filters no-print" method="get" action="<?= e(rtrim(BASE_URL, '/')) ?>/absences/registre">
        <label>
            <span>Année scolaire</span>
            <select name="annee_id" onchange="this.form.submit()">
                <?php foreach ($annees as $annee): ?>
                    <option value="<?= (int) $annee['id'] ?>" <?= (int) $annee['id'] === $anneeId ? 'selected' : '' ?>>
                        <?= e($annee['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
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
        <label>
            <span>Calcul arrêté au</span>
            <input type="date" name="date_reference"
                value="<?= e($dateReference) ?>"
                min="<?= e($anneeSelectionnee['date_debut'] ?? '') ?>"
                max="<?= e($anneeSelectionnee['date_fin'] ?? '') ?>"
                onchange="this.form.submit()">
        </label>
    </form>

    <?php if ($dateReferenceErreur): ?>
        <div class="register-preview-notice is-error no-print" role="alert"><?= e($dateReferenceErreur) ?></div>
    <?php elseif ($dateReference === '' && $anneeSelectionnee && date('Y-m-d') < $anneeSelectionnee['date_debut']): ?>
        <div class="register-preview-notice no-print" role="status">
            Cette année scolaire n’a pas encore commencé. Les pointages futurs restent masqués jusqu’au choix d’une date de prévisualisation.
        </div>
    <?php elseif ($dateReference > date('Y-m-d')): ?>
        <div class="register-preview-notice no-print" role="status">
            Prévisualisation en lecture seule jusqu’au <?= e(date('d/m/Y', strtotime($dateReference))) ?>.
            Aucun appel futur n’est créé ni validé.
        </div>
    <?php endif; ?>

    <?php if (!$classes): ?>
        <div class="register-empty no-print">
            <i class="fa-solid fa-users"></i>
            <h2>Aucune classe disponible</h2>
            <p>Vérifiez l’année scolaire sélectionnée ou l’affectation de votre compte enseignant.</p>
        </div>
    <?php elseif ($registre): ?>
        <?php $classe = $registre['classe']; ?>
        <main class="register-document">
            <section class="register-page cover-page">
                <div class="cover-half">
                    <h2 class="cover-topline">EDUCATION NATIONALE</h2>
                    <?php if ($logoUrl !== ''): ?>
                        <img class="cover-logo" src="<?= e($logoUrl) ?>" alt="Logo de <?= e($ecole) ?>">
                    <?php endif; ?>
                    <h1>REGISTRE DES PRÉSENCES</h1>
                    <div class="cover-fields">
                        <div><span>ÉCOLE :</span><strong><?= e($ecole) ?></strong></div>
                        <div><span>CLASSE :</span><strong><?= e($classe['nom']) ?></strong></div>
                        <div><span>TITULAIRE :</span><strong><?= e(trim($classe['titulaire'])) ?></strong></div>
                        <div><span>ANNÉE SC. :</span><strong><?= e($classe['annee_libelle']) ?></strong></div>
                    </div>
                </div>
            </section>

            <?php
            $lignesRegistre = 70;
            $elevesIdentite = $registre['eleves'];
            ?>
            <section class="register-page identity-page">
                <h2 class="paper-page-title">RENSEIGNEMENTS SUR LES ÉLÈVES</h2>
                <table class="identity-table identity-complete-table">
                    <colgroup>
                        <col class="identity-number"><col class="identity-name"><col class="identity-matricule">
                        <col class="identity-birth"><col class="identity-parent"><col class="identity-parent">
                        <col class="identity-origin"><col class="identity-observations">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>N°</th><th>NOM ET PRÉNOMS</th><th>MATRICULE</th>
                            <th>DATE DE NAISSANCE</th><th>PÈRE</th><th>MÈRE</th>
                            <th>ÉCOLE D’ORIGINE</th><th>OBSERVATIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($index = 0; $index < $lignesRegistre; $index++): ?>
                            <?php $eleve = $elevesIdentite[$index] ?? null; ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td class="identity-student-name"><?= $eleve ? e(trim($eleve['nom'] . ' ' . $eleve['prenom'])) : '' ?></td>
                                <td><?= $eleve ? e($eleve['matricule'] ?? '') : '' ?></td>
                                <td><?= $eleve ? e($dateNaissance($eleve['date_naissance'] ?? null)) : '' ?></td>
                                <td><?= $eleve ? e($eleve['pere'] ?? '') : '' ?></td>
                                <td><?= $eleve ? e($eleve['mere'] ?? '') : '' ?></td>
                                <td></td><td></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </section>

            <?php foreach ($registre['mois'] as $mois): ?>
                <?php
                $jours = $mois['days'];
                $elevesMois = $mois['eleves_mois'];
                $libelleMois = mb_strtoupper($mois['label']);
                ?>
                <section class="register-page month-page">
                    <header class="month-page-heading">
                        <h2>MOIS DE : <strong><?= e($libelleMois) ?></strong></h2>
                        <p>NOMBRE DE JOURS DE CLASSE :
                            <strong><?= $mois['calculated'] ? (int) $mois['jours_classe'] : '' ?></strong>
                        </p>
                    </header>
                    <table class="attendance-register-table month-table">
                        <colgroup>
                            <col class="col-number"><col class="col-student">
                            <?php foreach ($jours as $_): ?><col class="col-day"><?php endforeach; ?>
                            <col class="col-total"><col class="col-total"><col class="col-total"><col class="col-total">
                        </colgroup>
                        <thead>
                            <tr>
                                <th rowspan="2">N°</th><th rowspan="2">NOM ET PRÉNOMS</th>
                                <th colspan="<?= count($jours) ?>">JOURS DU MOIS</th>
                                <th colspan="2">TOTAL MENSUEL</th>
                                <th colspan="2">TOTAL GÉNÉRAL</th>
                            </tr>
                            <tr>
                                <?php foreach ($jours as $jour): ?>
                                    <th class="<?= $jour['off_day'] ? 'off-day' : '' ?>" title="<?= e($jour['closure'] ?: '') ?>"><?= (int) $jour['day'] ?></th>
                                <?php endforeach; ?>
                                <th>Présences</th><th>Absences</th><th>Présences cumulées</th><th>Jours de classe cumulés</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($index = 0; $index < $lignesRegistre; $index++): ?>
                                <?php
                                $eleve = $elevesMois[$index] ?? null;
                                $eleveId = $eleve ? (int) $eleve['id'] : 0;
                                $totauxEleve = $mois['eleves'][$eleveId] ?? [
                                    'presence_mois' => null,
                                    'absence_mois' => null,
                                    'presence_cumulee' => null,
                                    'jours_classe_cumules' => null
                                ];
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td class="student-name"><?= $eleve ? e(trim($eleve['nom'] . ' ' . $eleve['prenom'])) : '' ?></td>
                                    <?php foreach ($jours as $jour): ?>
                                        <?php
                                        $markText = '';
                                        $eleveInscrit = $eleve && $eleve['date_inscription'] <= $jour['date'];
                                        if (!$jour['future'] && $jour['in_school_year']) {
                                            if ($jour['off_day']) {
                                                $markText = '×';
                                            } elseif ($eleveInscrit && isset($jour['marks'][$eleveId])) {
                                                $marque = $jour['marks'][$eleveId];
                                                $markText = $marque['type'] === 'Présent'
                                                    ? '|'
                                                    : '—';
                                            }
                                        }
                                        ?>
                                        <td class="<?= $jour['off_day'] && !$jour['future'] ? 'off-day' : '' ?>" title="<?= e($jour['closure'] ?: '') ?>"><?= e($markText) ?></td>
                                    <?php endforeach; ?>
                                    <td><?= $mois['calculated'] && $eleve ? (int) $totauxEleve['presence_mois'] : '' ?></td>
                                    <td><?= $mois['calculated'] && $eleve ? (int) $totauxEleve['absence_mois'] : '' ?></td>
                                    <td><?= $mois['calculated'] && $eleve ? (int) $totauxEleve['presence_cumulee'] : '' ?></td>
                                    <td><?= $mois['calculated'] && $eleve ? (int) $totauxEleve['jours_classe_cumules'] : '' ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <?php foreach (['present' => 'TOTAL DES PRÉSENCES', 'absent' => 'TOTAL DES ABSENCES'] as $totalKey => $totalLabel): ?>
                                <?php $isPresenceTotal = $totalKey === 'present'; ?>
                                <tr>
                                    <th colspan="2"><?= e($totalLabel) ?></th>
                                    <?php foreach ($jours as $jour): ?>
                                        <td class="<?= $jour['off_day'] ? 'off-day' : '' ?>">
                                            <?= $jour['off_day'] ? '×' : ($mois['calculated'] && $jour['studied'] ? (int) $jour[$totalKey] : '') ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td><?= $mois['calculated'] && $isPresenceTotal ? (int) $mois['totaux_lignes']['presence_mois'] : '' ?></td>
                                    <td><?= $mois['calculated'] && !$isPresenceTotal ? (int) $mois['totaux_lignes']['absence_mois'] : '' ?></td>
                                    <?php if ($isPresenceTotal): ?>
                                        <td rowspan="2"><?= $mois['calculated'] ? (int) $mois['totaux_lignes']['presence_cumulee'] : '' ?></td>
                                        <td rowspan="2"><?= $mois['calculated'] ? (int) $mois['totaux_lignes']['jours_classe_cumules'] : '' ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tfoot>
                    </table>
                    <div class="month-key"><b>|</b> Présent&nbsp;&nbsp;&nbsp; <b>—</b> Absent</div>
                </section>
            <?php endforeach; ?>

            <section class="register-page annual-summary-page">
                <h2 class="paper-page-title">TAUX ET MOYENNES</h2>
                <table class="annual-summary-table">
                    <thead>
                        <tr>
                            <th>Mois</th>
                            <th colspan="2">JOURS DE CLASSE</th>
                            <th>ÉLÈVES INSCRITS</th>
                            <th colspan="2">PRÉSENCES</th>
                            <th>ABSENCES</th>
                            <th>MOYENNE / JOUR</th>
                            <th>VISA</th>
                        </tr>
                        <tr>
                            <th></th><th>Mois</th><th>Cumul</th><th>Mois</th>
                            <th>Mois</th><th>Cumul</th><th>Mois</th><th></th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registre['mois'] as $mois): ?>
                            <tr>
                                <th><?= e($mois['label']) ?></th>
                                <td><?= $mois['calculated'] ? (int) $mois['jours_classe'] : '' ?></td>
                                <td><?= $mois['calculated'] ? (int) $mois['jours_cumules'] : '' ?></td>
                                <td><?= $mois['calculated'] ? (int) $mois['effectif'] : '' ?></td>
                                <td><?= $mois['calculated'] ? (int) $mois['presences'] : '' ?></td>
                                <td><?= $mois['calculated'] ? (int) $mois['presences_cumulees'] : '' ?></td>
                                <td><?= $mois['calculated'] ? (int) $mois['absences'] : '' ?></td>
                                <td><?= $mois['moyenne'] === null ? '' : number_format((float) $mois['moyenne'], 2, ',', ' ') ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <ol class="summary-instructions">
                    <li>La moyenne des présences s’obtient en divisant le total des présences par le nombre de jours d’ouverture.</li>
                    <li>Les présences motivées sont considérées comme des présences.</li>
                    <li>Les totaux sont calculés après la fin du mois et uniquement si au moins un appel a été validé.</li>
                    <li>Le registre est présenté à la direction pour visa.</li>
                </ol>
                <h3 class="observations-title">OBSERVATIONS</h3>
                <div class="observation-lines" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
                <div class="summary-approval"><span>Visa de la direction :</span><span>Date : ____ / ____ / ________</span></div>
            </section>
        </main>
    <?php endif; ?>
</section>

<?php require_once VIEWS_PATH . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
