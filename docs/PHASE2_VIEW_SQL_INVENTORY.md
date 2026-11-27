# Étape 2 — inventaire des vues et extraction de la lecture

Date de contrôle : 27 novembre 2026  
Branche : `refactor/mvc-phase12-13-completion`

## Règle

Les vues ne doivent pas exécuter de SQL ni réaliser de mutations. Les requêtes sont nommées dans les repositories, les cas d'usage/assemblages sont portés par les services, et le contrôleur prépare les données de vue. Les opérations POST restent dans les contrôleurs/services existants et ne sont pas modifiées sans audit spécifique.

## Lot ajouté à cette branche

Les vues suivantes ont été déchargées de leurs accès SQL directs :

- `public/views/modules/paiements/liste.php`
- `public/views/modules/paiements/impayes.php`
- `public/views/modules/frais/liste.php`

Leur chaîne de lecture est `PaymentReadController → PaymentReadService → PaymentReadRepository`. Les contrôles d'année, statistiques de versements, soldes impayés, échéances et frais sont désormais des méthodes de repository nommées. Le lookup du nom de classe dans la vue Frais a également été déplacé. Le contrôleur de paiement existant demeure utilisé pour son endpoint AJAX : il n'a pas été remplacé ni supprimé.

Test de garde ajouté : `tests/financial_views_mvc_read_smoke.php`, relié au workflow Phase 12. Il vérifie l'absence de SQL direct dans ces trois vues, la chaîne de dépendances et des invariants financiers structurants.

## Inventaire élargi

L'arborescence de la branche contient 106 vues PHP. Le contrôle source a trouvé des accès SQL directs hors des dashboards déjà migrés, notamment dans les vues :

- enseignant : bulletins, élèves, emploi, notes, résultats ;
- années scolaires et classes : formulaires d'ajout/modification ;
- bulletins : consulter, générer, imprimer ;
- élèves : ajout, fiche, import, modification ;
- enseignants : ajout, fiche, liste, modification ;
- matières : ajout et modification ;
- messages : boîte, conversation, envoi ;
- notes : ajout, liste, saisie ;
- notifications : liste ;
- paiements : enregistrer, factures, reçu ;
- parents : ajout, fiche, liste, modification et espace parent (enfants, bulletins, paiements) ;
- résultats : consulter, délibérations, procès-verbaux, proclamation ;
- sauvegardes, logs, utilisateurs (ajout, changement de mot de passe, fiche, modification, profil) ;
- templates communs : header et sidebar.

Ces fichiers exigent des lots supplémentaires, notamment pour séparer les lectures des mutations et garantir les scopes d'accès (parent/enfant, enseignant/classe, année scolaire, droits administratifs).

## État de validation

- Vérification par lecture GitHub : les trois vues du lot n'ont plus de requêtes directes `$db->fetch...`.
- Les fichiers ont été ajoutés au workflow de syntaxe et au smoke MVC.
- Résultats CI du dernier SHA : à confirmer par une exécution visible.
- Tests de navigation, de base de données XAMPP, de droits et de comparaison des résultats : non exécutés ici.

**L'étape 2 globale reste non terminée. Aucun merge dans `main` ne doit être effectué avant la migration de toutes les vues et la validation comportementale.**
