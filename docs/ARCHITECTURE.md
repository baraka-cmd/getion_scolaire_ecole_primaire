# Architecture MVC du projet

## Responsabilites

- `public/` contient les points d'entree HTTP et les vues.
- `src/controllers/` valide les requetes HTTP, applique les permissions, garde les transactions HTTP/CSRF et adapte les reponses JSON.
- `src/services/` contient les regles metier reutilisables et les calculs.
- `src/repositories/` contient les requetes SQL et les operations de persistance.
- `src/policies/` centralise les decisions d'autorisation transversales.
- `src/views/` ou `public/views/` contient le rendu HTML. Une vue ne doit pas executer de SQL directement.

## Dependances autorisees

Vue -> Controleur -> Service -> Repository -> Database.

Les vues peuvent instancier des services/politiques pour recuperer des donnees de presentation lorsque aucun controleur d'etat HTTP ne gere l'action. Elles ne doivent pas instancier un autre controleur pour reutiliser une regle metier.

Les services peuvent utiliser plusieurs repositories et partager des politiques. Les repositories ne doivent pas appliquer de logique metier ni appeler des controleurs.

Les transactions qui garantissent plusieurs operations atomiques restent dans le controleur ou dans le service qui possede clairement cette transaction. Ne pas eclater une transaction pour contourner une limite MVC.

## Ajout d'une fonctionnalite

1. Identifier la capacite metier et ses permissions.
2. Ajouter ou modifier le repository pour la persistance.
3. Ajouter les regles de validation/calcul dans un service.
4. Ajouter un point d'entree dans le controleur sans dupliquer les regles du service.
5. Ajouter ou modifier la vue sans SQL ni logique persistante.
6. Ajouter un smoke test en memoire ou un test PHP ciblé.
7. Executer les tests, `php -l` sur les fichiers modifies et `git diff --check`.

## Hygienes obligatoires

- SQL parametre uniquement; jamais de concatenation de valeurs utilisateur.
- Aucun appel direct entre controleurs pour reutiliser une regle metier.
- Une policy est reutilisee lorsqu'elle decide d'un droit d'acces transversal.
- Toute suppression d'un ancien chemin exige une recherche d'appels dans `src/`, `public/` et `tests/`.
- Les anciens chemins sont conserves seulement lorsqu'un remplacement progressif ou une compatibilite externe exige leur maintien.

## Phase 6 — Etat du nettoyage

Les anciens cheminements ne sont pas tous supprimables en une seule passe. Les vues historiques contiennent encore de nombreuses requetes SQL directes. Elles sont identifieses comme migration progressive; aucun ancien contrat n'est retire tant qu'il reste reference dans une vue, une route ou un test.

Les appels directs entre controleurs ont ete elimines pour l'acces aux eleves. Le module d'inscription utilise maintenant `AcademicResultsService` pour les resultats annuels.
