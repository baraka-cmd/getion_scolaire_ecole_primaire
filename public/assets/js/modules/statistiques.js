'use strict';

var statistiqueCharts = {};

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('btnAppliquerFiltres').addEventListener('click', chargerToutesLesDonnees);
    document.getElementById('filtreAnnee').addEventListener('change', actualiserFiltresAnnee);
    chargerToutesLesDonnees();
});

function statistiqueEndpoint() {
    return (window.APP_BASE_PATH || '') + '/rapports/statistiques';
}

function lireFiltres() {
    return {
        annee_id: document.getElementById('filtreAnnee').value,
        classe_id: document.getElementById('filtreClasse').value,
        trimestre_id: document.getElementById('filtreTrimestre').value
    };
}

async function demander(action, filters) {
    var response = await fetch(statistiqueEndpoint(), {
        method: 'POST',
        body: new URLSearchParams(Object.assign({ action: action }, filters)),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Impossible de charger ces statistiques.');
    }
    return data;
}

async function actualiserFiltresAnnee() {
    var yearSelect = document.getElementById('filtreAnnee');
    var yearId = yearSelect.value;
    var classSelect = document.getElementById('filtreClasse');
    var termSelect = document.getElementById('filtreTrimestre');
    var applyButton = document.getElementById('btnAppliquerFiltres');
    var refreshButton = document.getElementById('btnActualiser');
    yearSelect.disabled = true;
    classSelect.disabled = true;
    termSelect.disabled = true;
    applyButton.disabled = true;
    refreshButton.disabled = true;
    classSelect.innerHTML = '<option value="">Toutes les classes</option>';
    termSelect.innerHTML = '<option value="">Tous les trimestres</option>';
    try {
        var data = await demander('getFilterOptions', { annee_id: yearId });
        classSelect.innerHTML = '<option value="">Toutes les classes</option>' + data.classes.map(function (item) {
            return '<option value="' + Number(item.id) + '">' + echapper(item.nom) + '</option>';
        }).join('');
        termSelect.innerHTML = '<option value="">Tous les trimestres</option>' + data.trimestres.map(function (item) {
            return '<option value="' + Number(item.id) + '">' + echapper(item.libelle) + '</option>';
        }).join('');
    } catch (error) {
        afficherErreur(error);
    } finally {
        yearSelect.disabled = false;
        classSelect.disabled = false;
        termSelect.disabled = false;
        applyButton.disabled = false;
        refreshButton.disabled = false;
    }
}

async function chargerToutesLesDonnees() {
    var applyButton = document.getElementById('btnAppliquerFiltres');
    var refreshButton = document.getElementById('btnActualiser');
    applyButton.disabled = true;
    refreshButton.disabled = true;
    afficherMessage('');
    var filters = lireFiltres();
    try {
        var responses = await Promise.all([
            demander('getKPIs', filters),
            demander('getEffectifsParClasse', filters),
            demander('getResultatsParTrimestre', filters),
            demander('getRecouvrementParClasse', filters),
            demander('getPresencesParMois', filters),
            demander('getIncidentsParMois', filters),
            demander('getTopClasses', filters),
            demander('getClassesARisque', filters)
        ]);
        afficherPeriode(responses[0].kpis);
        afficherKPIs(responses[0].kpis);
        afficherResultatsClasse(responses[1].resultats);
        afficherResultatsTrimestre(responses[2].resultats);
        afficherRecouvrement(responses[3].recouvrement);
        afficherPresences(responses[4].presences);
        afficherIncidents(responses[5].incidents);
        afficherTableauClasses('tbodyTopClasses', responses[6].top_classes, false);
        afficherTableauClasses('tbodyClassesRisque', responses[7].classes_risque, true);
    } catch (error) {
        afficherErreur(error);
    } finally {
        applyButton.disabled = false;
        refreshButton.disabled = false;
    }
}

function afficherPeriode(kpis) {
    var classSelect = document.getElementById('filtreClasse');
    var className = classSelect.selectedOptions.length ? classSelect.selectedOptions[0].textContent : 'Toutes les classes';
    document.getElementById('reportPeriode').textContent = kpis.annee + ' · ' + className + ' · ' + kpis.trimestre;
}

function afficherKPIs(k) {
    valeur('kpiEleves', nombre(k.total_eleves));
    valeur('kpiClasses', nombre(k.total_classes));
    valeur('kpiRatio', k.ratio_eleves_classe === null ? '—' : k.ratio_eleves_classe.toLocaleString('fr-FR') + ' élève(s) par classe');
    valeur('kpiMoyenne', k.moyenne_generale === null ? '—' : k.moyenne_generale.toLocaleString('fr-FR') + ' /100');
    valeur('kpiMoyenneDetail', nombre(k.resultats_saisis) + ' résultat(s) pris en compte · ' + k.trimestre);
    valeur('kpiPresence', k.taux_presence === null ? '—' : k.taux_presence.toLocaleString('fr-FR') + ' %');
    valeur('kpiPresenceDetail', nombre(k.presences_saisies) + ' présents · ' + nombre(k.absences_saisies) + ' absents · ' + nombre(k.pointages_saisis) + ' pointages');
    valeur('kpiReussite', k.taux_reussite === null ? '—' : k.taux_reussite.toLocaleString('fr-FR') + ' %');
    valeur('kpiReussiteDetail', nombre(k.resultats_saisis) + ' résultats · réussite à partir de 50/100');
    valeur('kpiRecouvrement', k.taux_recouvrement === null ? '—' : k.taux_recouvrement.toLocaleString('fr-FR') + ' %');
    valeur('kpiRecouvrementDetail', montant(k.montant_paye) + ' payés sur ' + montant(k.montant_attendu) + ' dus');
}

function afficherResultatsClasse(rows) {
    dessiner('chartResultatsClasse', 'bar', rows.map(function (r) { return r.classe_nom; }), [
        { label: 'Moyenne /100', data: rows.map(function (r) { return r.moyenne; }), backgroundColor: '#2c3e90' },
        { label: 'Réussite (%)', data: rows.map(function (r) { return r.taux_reussite; }), backgroundColor: '#10b981' }
    ], { scales: { y: { beginAtZero: true, max: 100 } } });
}

function afficherResultatsTrimestre(rows) {
    document.getElementById('tbodyResultatsTrimestre').innerHTML = rows.length ? rows.map(function (r) {
        return '<tr><td>' + echapper(r.trimestre) + '</td><td>' + nombre(r.effectif) + '</td><td>' + nombre(r.resultats_saisis) +
            '</td><td>' + (r.moyenne === null ? '—' : nombre(r.moyenne)) + '</td><td>' +
            (r.taux_reussite === null ? '—' : nombre(r.taux_reussite) + ' %') + '</td><td>' +
            (r.couverture === null ? '—' : nombre(r.couverture) + ' %') + '</td></tr>';
    }).join('') : ligneVide(6, 'Aucun résultat pour cette sélection.');
    dessiner('chartResultatsTrimestre', 'bar', rows.map(function (r) { return r.trimestre; }), [
        { label: 'Réussite (moyenne ≥ 50/100)', data: rows.map(function (r) { return Number(r.reussites); }), backgroundColor: '#10b981' },
        { label: 'Sous le seuil', data: rows.map(function (r) { return Number(r.echecs); }), backgroundColor: '#ef4444' }
    ], { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherRecouvrement(rows) {
    document.getElementById('tbodyRecouvrement').innerHTML = rows.length ? rows.map(function (r) {
        return '<tr><td>' + echapper(r.classe_nom) + '</td><td>' + nombre(r.effectif) + '</td><td>' + montant(r.montant_du) +
            '</td><td>' + montant(r.montant_paye) + '</td><td>' + montant(r.montant_impaye) + '</td><td>' +
            (r.taux_recouvrement === null ? '—' : nombre(r.taux_recouvrement) + ' %') + '</td></tr>';
    }).join('') : ligneVide(6, 'Aucun frais obligatoire pour cette sélection.');
    dessiner('chartRecouvrement', 'bar', rows.map(function (r) { return r.classe_nom; }), [
        { label: 'Payé', data: rows.map(function (r) { return Number(r.montant_paye); }), backgroundColor: '#10b981' },
        { label: 'Reste dû', data: rows.map(function (r) { return Number(r.montant_impaye); }), backgroundColor: '#ef4444' }
    ], { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } });
}

function afficherPresences(rows) {
    document.getElementById('tbodyPresences').innerHTML = rows.length ? rows.map(function (r) {
        return '<tr><td>' + echapper(r.label) + '</td><td>' + nombre(r.presents) + '</td><td>' + nombre(r.absences) +
            '</td><td>' + nombre(r.total) + '</td><td>' + (r.taux === null ? '—' : nombre(r.taux) + ' %') + '</td></tr>';
    }).join('') : ligneVide(5, 'Aucun jour dans la période.');
    dessiner('chartPresencesMois', 'line', rows.map(function (r) { return r.label; }), [
        { label: 'Présences', data: rows.map(function (r) { return Number(r.presents); }), borderColor: '#10b981', tension: .25 },
        { label: 'Absences', data: rows.map(function (r) { return Number(r.absences); }), borderColor: '#ef4444', tension: .25 }
    ], { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherIncidents(rows) {
    document.getElementById('tbodyIncidents').innerHTML = rows.length ? rows.map(function (r) {
        return '<tr><td>' + echapper(r.label) + '</td><td>' + nombre(r.legeres) + '</td><td>' + nombre(r.moyennes) +
            '</td><td>' + nombre(r.graves) + '</td><td>' + nombre(r.total) + '</td></tr>';
    }).join('') : ligneVide(5, 'Aucun mois dans la période.');
    dessiner('chartIncidents', 'bar', rows.map(function (r) { return r.label; }), [
        { label: 'Légers', data: rows.map(function (r) { return Number(r.legeres); }), backgroundColor: '#f59e0b' },
        { label: 'Moyens', data: rows.map(function (r) { return Number(r.moyennes); }), backgroundColor: '#f97316' },
        { label: 'Graves', data: rows.map(function (r) { return Number(r.graves); }), backgroundColor: '#ef4444' }
    ], { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherTableauClasses(id, rows, atRisk) {
    var tbody = document.getElementById(id);
    tbody.innerHTML = rows.length ? rows.map(function (r, index) {
        var firstCell = atRisk ? echapper(r.classe_nom) : (index + 1);
        var count = atRisk ? r.resultats_saisis : r.resultats_saisis;
        var lastCells = atRisk
            ? '<td>' + nombre(r.taux_reussite) + ' %</td><td>' + nombre(r.echecs) + '</td>'
            : '<td>' + nombre(r.taux_reussite) + ' %</td>';
        return '<tr>' + (atRisk ? '<td><strong>' + firstCell + '</strong></td>' : '<td>' + firstCell + '</td><td><strong>' + echapper(r.classe_nom) + '</strong></td>') +
            '<td>' + nombre(count) + '</td><td>' + nombre(r.moyenne) + '</td>' + lastCells + '</tr>';
    }).join('') : ligneVide(atRisk ? 5 : 5, atRisk ? 'Aucune classe sous le seuil de réussite.' : 'Aucun résultat à classer.');
}

function dessiner(id, type, labels, datasets, extraOptions) {
    var canvas = document.getElementById(id);
    if (!canvas) return;
    if (statistiqueCharts[id]) statistiqueCharts[id].destroy();
    statistiqueCharts[id] = new Chart(canvas.getContext('2d'), {
        type: type,
        data: { labels: labels, datasets: datasets },
        options: Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }, extraOptions || {})
    });
}

function nombre(value) {
    return value === null || value === undefined ? '—' : Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 1 });
}

function montant(value) {
    var formatted = Number(value || 0).toLocaleString('fr-FR', { maximumFractionDigits: 2 });
    return formatted;
}

function valeur(id, text) {
    var element = document.getElementById(id);
    if (element) element.textContent = text;
}

function ligneVide(columns, text) {
    return '<tr><td colspan="' + columns + '" class="report-empty">' + echapper(text) + '</td></tr>';
}

function echapper(value) {
    var element = document.createElement('span');
    element.textContent = value === null || value === undefined ? '' : String(value);
    return element.innerHTML;
}

function afficherMessage(text) {
    var container = document.getElementById('flashMessage');
    container.textContent = text;
    container.className = text ? 'alert alert-danger' : '';
}

function afficherErreur(error) {
    afficherMessage(error && error.message ? error.message : 'Une erreur est survenue pendant le chargement des statistiques.');
}
