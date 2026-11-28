# Revue de conformité globale — Assistant IA, phases B à H

Date de revue : 2026-11-28
Branche examinée : `feature/phase-h-testing-evaluation`
Référentiels lus : `REFLEXION_AMELIORATION_ASSISTANT_IA.md`, `PLAN_DETAILLE_FONCTIONNEMENT_ASSISTANT_IA.md`, et les documents de phase B à H.

## Conclusion exécutive
La branche regroupe les livraisons B–H et constitue une base d'intégration utile pour les essais locaux. Elle ne doit pas être qualifiée de « plan entièrement réalisé » ni de système prêt à la production. Plusieurs documents de phase ont correctement déclaré des livraisons partielles ; la revue confirme ces écarts.

## Conformité au plan et écarts constatés

| Exigence de référence | État observé sur la branche | Écart / conséquence |
|---|---|---|
| Orchestration multi-étapes, choix d'outils, boucle outil → résultat → réponse (B) | L'orchestrateur produit un plan validé à une étape depuis la classification. | Pas de plan multi-outils ni de boucle d'exécution pilotée par modèle ; questions analytiques complexes restent limitées. |
| Capacités métier découvrables et raccordées (C) | Registre et exécuteur existants, capacités répertoriées. | La présence au registre ne prouve pas l'implémentation de chaque scénario ni une couverture exhaustive. |
| Données réelles, calculs, comparaison (D) | Dispatcher de données valide quelques paramètres et délègue à l'exécuteur. | Comparaisons multi-périodes/multi-classes, contrôle des calculs par fixtures et tests d'intégration à compléter. |
| Réponse finale naturelle fondée sur les résultats, suivi (E) | Contrat d'affichage, rendu sûr, tableaux, graphique conditionnel et suggestions déterministes. | Pas d'appel Gemini de rédaction finale à partir des résultats métier ; clarification multi-tours et données chart encore partielles. |
| RAG : récupération, injection dans génération et citations (F) | Index lexical TXT/MD/CSV et endpoint de recherche protégée. | Recherche non raccordée au prompt de réponse Gemini ; citations conversationnelles automatiques absentes ; formats PDF/Office non pris en charge. |
| Actions sensibles et confirmation utilisateur (G) | Endpoint backend de préparation/confirmation de saisie de note, revalidation et audit. | UX de confirmation pas raccordée à la conversation ; migrations, concurrence, permissions et DB doivent être testées sur une base isolée. |
| Évaluation complète (H) | Corpus initial 20 cas, tests de contrat, workflow CI ciblé. | Pas de test DB/HTTP complet, mesure Gemini, benchmark latence, matrice rôle/objet complète ni résultats CI observés lors de cette revue. |

## Risques à vérifier lors de l'essai local
1. Exécuter toutes les migrations assistant dans l'ordre sur une sauvegarde de la base de test et confirmer les tables/colonnes/FK, en particulier la référence à `assistant_conversations` et les permissions.
2. Exécuter `php -l` sur l'ensemble des nouveaux services/controllers et le test H ; exécuter le workflow GitHub.
3. Vérifier les dépendances de `Database::getPdo()`, les méthodes de transaction et le retour de `insert/update` dans le code DB commun.
4. Tester les routes knowledge/action avec rôles autorisés et refusés, CSRF, propriétaires différents, documents privés et chemins invalides.
5. Tester une saisie de note avec confirmation, expiration, token incorrect, note hors barème, absence, double clic/concurrence et rollback.
6. Contrôler que l'assistant n'expose pas de données enseignants/parents hors périmètre : la protection actuelle refuse les parents pour les capacités génériques, mais le scope des autres rôles/repositories doit être démontré.
7. Réaliser les scénarios B/E/F manquants avant d'activer la rédaction conversationnelle finale et le RAG en production.

## Décision de fusion
Cette branche est destinée à l'intégration et à la récupération locale pour tests, conformément à la demande du propriétaire du dépôt. Une fusion vers `main` ne vaut pas certification fonctionnelle ni autorisation de déploiement. Les points ci-dessus restent des gates explicites pour la validation locale et la mise en production.

## Suite de validation locale
Après clonage : sauvegarde DB → migrations → configuration .env/clé Gemini → lint/tests → tests par rôle → scénario de saisie avec confirmation → rapport de résultats. Ne jamais tester les actions sensibles sur les données scolaires de production.
