# Phase 7 - Validation finale

## Tests executes

- Smoke tests PHP du projet: 25 fichiers testes.
- Tests assistant: les suites phase 6 a phase 10 passent.
- Authentification, autorisations, OAuth, push, finances et utilisateurs: passes.
- Controle PHP: 265 fichiers PHP dans `src/` et `public/views/` passent `php -l`.
- Controle du diff: `git diff --check` passe.

## Verification de compatibilite

- Les routes principales restent gerees par `public/index.php`.
- Les actions AJAX restent dans les methodes `handleAjax()` des controleurs.
- Les formats JSON continuent d'utiliser `jsonResponse`.
- Les permissions ont ete preservees dans les controleurs.
- Les vues ont ete conservees; seuls deux controles d'acces ont ete rediriges vers `StudentAccessPolicy`.

## Reste exige avant la production

- Creer une copie de preproduction distincte.
- Restaurer une copie de la base de production avec les donnees de test autorisees.
- Executer les parcours suivants sur cette copie:
  - connexion locale et Google OAuth;
  - CRUD eleves, inscriptions et parents;
  - saisie des notes, resultats, bulletins;
  - pointage absences, retards et registre;
  - paiements, contre-ecritures, recus;
  - utilisateurs, roles et permissions;
  - documents, imports, messages et notifications;
  - assistant, tableau de bord et rapports.
- Rejouer les testes avec la base locale et une base type production.

## Livraison progressive recommandee

1. Commit 1: couches eleves, parents et inscriptions.
2. Commit 2: enseignants, classes, matieres et annees.
3. Commit 3: evaluations, notes, resultats et bulletins.
4. Commit 4: absences, retards et registre.
5. Commit 5: finances, utilisateurs, roles et authentification.
6. Commit 6: documents, imports, messages et notifications.
7. Commit 7: assistant, tableau de bord et statistiques.
8. Commit 8: nettoyage, policy d'acces et documentation.

Ne supprimer definitivement aucun ancien controleur ou ancien chemin tant qu'une vue, une route, un test ou une integration externe ne les a pas remplaces apres validation en preproduction.
