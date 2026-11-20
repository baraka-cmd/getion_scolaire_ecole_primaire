'use strict';

var effectifCharts = {};

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('btnAppliquerFiltres').addEventListener('click', chargerToutesLesDonnees);
    document.getElementById('filtreAnnee').addEventListener('change', actualiserClasses);
    chargerToutesLesDonnees();
});

function rapportEndpoint() {
    return (window.APP_BASE_PATH || '') + '/rapports/effectifs';
}

function lireFiltres() {
    return {
        annee_id: document.getElementById('filtreAnnee').value,
        classe_id: document.getElementById('filtreClasse').value
    };
}

async function demander(action, filters) {
    var body = new URLSearchParams(Object.assign({ action: action }, filters));
    var response = await fetch(rapportEndpoint(), {
        method: 'POST',
        body: body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Impossible de charger ce rapport.');
    }
    return data;
}

async function actualiserClasses() {
    var yearSelect = document.getElementById('filtreAnnee');
    var yearId = yearSelect.value;
    var select = document.getElementById('filtreClasse');
    var applyButton = document.getElementById('btnAppliquerFiltres');
    var refreshButton = document.getElementById('btnActualiser');
    yearSelect.disabled = true;
    select.disabled = true;
    applyButton.disabled = true;
    refreshButton.disabled = true;
    select.innerHTML = '<option value="">Toutes les classes</option>';
    try {
        var data = await demander('getFilterOptions', { annee_id: yearId });
        select.innerHTML = '<option value="">Toutes les classes</option>' + data.classes.map(function (item) {
            return '<option value="' + Number(item.id) + '">' + echapper(item.nom) + '</option>';
        }).join('');
    } catch (error) {
        afficherErreur(error);
    } finally {
        yearSelect.disabled = false;
        select.disabled = false;
        applyButton.disabled = false;
        refreshButton.disabled = false;
    }
}

async function chargerToutesLesDonnees() {
    var button = document.getElementById('btnAppliquerFiltres');
    button.disabled = true;
    document.getElementById('btnActualiser').disabled = true;
    afficherMessage('');
    var filters = lireFiltres();
    try {
        var results = await Promise.all([
            demander('getStatistiques', filters),
            demander('getEffectifsParClasse', filters),
            demander('getRepartitionSexe', filters),
            demander('getEvolutionEffectifs', filters),
            demander('getPyramideAges', filters),
            demander('getRepartitionParAge', filters),
            demander('getTopClasses', filters),
            demander('getNouvellesInscriptions', filters)
        ]);
        afficherPeriode(results[0].stats.annee);
        afficherStatistiques(results[0].stats);
        afficherEffectifs(results[1].effectifs);
        afficherSexe(results[2].global);
        afficherEvolution(results[3].evolution);
        afficherPyramide(results[4]);
        afficherTranchesAges(results[5].repartition);
        afficherTopClasses(results[6].top_classes);
        afficherInscriptions(results[7].inscriptions);
    } catch (error) {
        afficherErreur(error);
    } finally {
        button.disabled = false;
        document.getElementById('btnActualiser').disabled = false;
    }
}

function afficherPeriode(annee) {
    var classeSelect = document.getElementById('filtreClasse');
    var classe = classeSelect.selectedOptions.length ? classeSelect.selectedOptions[0].textContent : 'Toutes les classes';
    document.getElementById('reportPeriode').textContent = annee + ' · ' + classe;
}

function afficherStatistiques(stats) {
    valeur('kpiTotalEleves', nombre(stats.total_eleves));
    valeur('kpiGarcons', nombre(stats.total_garcons));
    valeur('kpiFilles', nombre(stats.total_filles));
    valeur('kpiPctGarcons', stats.pct_garcons + ' % des inscrits');
    valeur('kpiPctFilles', stats.pct_filles + ' % des inscrits');
    valeur('kpiRatio', stats.ratio_gf === null ? '—' : stats.ratio_gf.toLocaleString('fr-FR'));
    valeur('kpiNouveaux', nombre(stats.nouvelles_inscriptions));
    valeur('kpiClasses', nombre(stats.nb_classes));
    valeur('kpiClassesTitre', stats.nb_classes === 1 ? 'Classe' : 'Classes');
    valeur('kpiMoyenneClasse', stats.nb_classes > 0 ? stats.moyenne_par_classe.toLocaleString('fr-FR') + ' élève(s) par classe' : 'Aucune classe');
}

function afficherEffectifs(rows) {
    var tbody = document.getElementById('tbodyEffectifs');
    tbody.innerHTML = rows.length ? rows.map(function (item) {
        var rate = item.taux_remplissage === null ? '—' : item.taux_remplissage + ' %';
        var badge = item.taux_remplissage === null ? 'neutral' : (item.taux_remplissage >= 90 ? 'danger' : (item.taux_remplissage >= 70 ? 'warning' : 'success'));
        var width = item.taux_remplissage === null ? 0 : Math.min(Math.max(item.taux_remplissage, 0), 100);
        return '<tr><td><strong>' + echapper(item.classe_nom) + '</strong></td><td>' + nombre(item.niveau) + '</td>' +
            '<td>' + echapper(item.titulaire) + '</td><td>' + nombre(item.garcons) + '</td><td>' + nombre(item.filles) +
            '</td><td><strong>' + nombre(item.total_eleves) + '</strong></td><td>' + nombre(item.capacite_max) +
            '</td><td><div class="progress-cell"><div class="progress-bar-wrapper"><div class="progress-bar-fill ' + badge + '" style="width:' + width + '%"></div></div><span class="progress-label">' + rate + '</span></div></td></tr>';
    }).join('') : ligneVide(8, 'Aucun effectif pour ces filtres.');
    dessiner('chartEffectifsClasse', 'bar', rows.map(function (r) { return r.classe_nom; }), [
        { label: 'Garçons', data: rows.map(function (r) { return Number(r.garcons); }), backgroundColor: '#3b82f6' },
        { label: 'Filles', data: rows.map(function (r) { return Number(r.filles); }), backgroundColor: '#8b5cf6' }
    ], { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherSexe(data) {
    dessiner('chartRepartitionSexe', 'doughnut', ['Garçons', 'Filles'], [{
        data: [Number(data.garcons), Number(data.filles)],
        backgroundColor: ['#3b82f6', '#8b5cf6']
    }], { cutout: '65%' });
}

function afficherEvolution(rows) {
    dessiner('chartEvolution', 'line', rows.map(function (r) { return r.annee; }), [
        { label: 'Total', data: rows.map(function (r) { return Number(r.total); }), borderColor: '#2c3e90', backgroundColor: 'rgba(44,62,144,.12)', fill: true, tension: .25 },
        { label: 'Garçons', data: rows.map(function (r) { return Number(r.garcons); }), borderColor: '#3b82f6', tension: .25 },
        { label: 'Filles', data: rows.map(function (r) { return Number(r.filles); }), borderColor: '#8b5cf6', tension: .25 }
    ], { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherPyramide(data) {
    var average = data.age_moyen === null ? '—' : data.age_moyen + ' ans';
    var range = data.age_min === null ? 'aucune donnée' : 'de ' + data.age_min + ' à ' + data.age_max + ' ans';
    document.getElementById('pyramideStats').textContent = 'Âge à la rentrée (' + data.date_reference + ') · moyenne : ' + average + ' · ' + range;
    var rows = data.pyramide;
    dessiner('chartPyramideAges', 'bar', rows.map(function (r) { return r.age + ' ans'; }), [
        { label: 'Garçons', data: rows.map(function (r) { return -Number(r.garcons); }), backgroundColor: '#3b82f6' },
        { label: 'Filles', data: rows.map(function (r) { return Number(r.filles); }), backgroundColor: '#8b5cf6' }
    ], {
        indexAxis: 'y',
        scales: {
            x: { ticks: { callback: function (value) { return Math.abs(value); } } },
            y: { beginAtZero: true }
        },
        plugins: { tooltip: { callbacks: { label: function (context) { return context.dataset.label + ' : ' + Math.abs(context.raw); } } } }
    });
}

function afficherTranchesAges(rows) {
    dessiner('chartTranchesAges', 'bar', rows.map(function (r) { return r.tranche_age; }), [
        { label: 'Garçons', data: rows.map(function (r) { return Number(r.garcons); }), backgroundColor: '#3b82f6' },
        { label: 'Filles', data: rows.map(function (r) { return Number(r.filles); }), backgroundColor: '#8b5cf6' }
    ], { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });
}

function afficherTopClasses(rows) {
    document.getElementById('tbodyTopClasses').innerHTML = rows.length ? rows.map(function (r, index) {
        var rate = r.taux_remplissage === null ? '—' : r.taux_remplissage + ' %';
        return '<tr><td>' + (index + 1) + '</td><td><strong>' + echapper(r.classe_nom) + '</strong></td><td>' +
            nombre(r.nb_eleves) + '</td><td>' + nombre(r.capacite_max) + '</td><td>' + rate +
            '</td><td>' + nombre(r.places_disponibles) + '</td></tr>';
    }).join('') : ligneVide(6, 'Aucune classe à afficher.');
}

function afficherInscriptions(rows) {
    dessiner('chartNouvellesInscriptions', 'bar', rows.map(function (r) { return r.label; }), [{
        label: 'Nouvelles inscriptions',
        data: rows.map(function (r) { return Number(r.nb); }),
        backgroundColor: '#10b981',
        borderRadius: 4
    }], { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });
}

function dessiner(id, type, labels, datasets, extraOptions) {
    var canvas = document.getElementById(id);
    if (!canvas) return;
    if (effectifCharts[id]) effectifCharts[id].destroy();
    effectifCharts[id] = new Chart(canvas.getContext('2d'), {
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
    return Number(value || 0).toLocaleString('fr-FR');
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
    afficherMessage(error && error.message ? error.message : 'Une erreur est survenue pendant le chargement du rapport.');
}
