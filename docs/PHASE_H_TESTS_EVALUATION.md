# Phase H — Tests, évaluation et optimisation

## Cadre et prudence
La phase H fournit un corpus versionné et des tests de contrat reproductibles. Les tests sans base de données ne remplacent pas les scénarios d'intégration XAMPP/MariaDB, les essais Gemini réels ni les tests de sécurité en environnement isolé. Aucun score universel de précision n'est revendiqué.

## Livrables
- `tests/fixtures/assistant_school_questions.json` : corpus initial de 20 demandes scolaires et hors périmètre, identifiants stables, intention/capacité attendues et paramètres.
- `tests/assistant_phase_h.php` : contrôle de structure et d'unicité du corpus, présence des capacités dans le registre, normalisation du contrat, clarification, rejet des capacités inconnues et filtrage des paramètres inconnus.
- `.github/workflows/assistant-phase-h.yml` : lint PHP ciblé et exécution automatique de la suite sur PR vers main ou déclenchement manuel.

## Matrice H.1–H.9
| Sous-phase | Vérification prévue / livrée |
|---|---|
| H.1 Corpus | Corpus initial livré; il doit être enrichi par les utilisateurs métiers avec formulations réelles anonymisées. |
| H.2 Intentions/paramètres | Tests de contrat et normalisation livrés; précision Gemini mesurée nécessite jeu annoté et appels reproductibles. |
| H.3 Capacités métier | Couverture de correspondance registre/corpus; exécution réelle de chaque capacité nécessite DB de test. |
| H.4 Calculs/données | Non certifiés par ce test isolé; comparer résultats à des fixtures MariaDB validées manuellement. |
| H.5 Suivi conversationnel | Scénarios identifiés, tests multi-tours HTTP à ajouter au banc d'intégration. |
| H.6 Permissions/accès interdits | Les refus de capacités inconnues sont testés; matrice rôle/permission, IDOR, CSRF et endpoints nécessite tests HTTP/DB. |
| H.7 Qualité des réponses | Définir une grille d'annotation (exactitude, pertinence, clarté, citations, abstention) et calculer précision/rappel par intention. Aucun score Gemini n'est fabriqué ici. |
| H.8 Performance/erreurs | Mesurer p50/p95, timeouts, taux d'erreur, taille contexte et coûts sur environnement de test; pas simulé dans le test PHP. |
| H.9 Revue finale | Rapport de gate; phase non prête au merge final tant que tests d'intégration, migration Phase G, UX confirmation et résultats CI n'ont pas été vérifiés. |

## Protocole d'évaluation conseillé
1. Construire un dataset séparé train/dev/test si des exemples servent à ajuster les prompts; ne pas évaluer sur les exemples de tuning.
2. Pour chaque question, conserver la formulation, rôle simulé, intent/capability/paramètres attendus, résultat DB de référence et verdict annoté.
3. Exécuter les mêmes prompts sur la même version/configuration de modèle et enregistrer date, version, latence, réponse, outils choisis et erreurs, sans stocker données scolaires identifiantes.
4. Calculer exactitude de classification et de paramètres; taux d'exécution correcte; exactitude des valeurs; refus corrects; taux de réponses non sourcées; p50/p95 et taux d'échec.
5. Les tests de notes/paiements utilisent uniquement données synthétiques ou anonymisées; les actions sensibles sont testées en transaction/DB isolée.

## Exécution locale
Depuis la racine du dépôt, avec PHP CLI :
```bash
php tests/assistant_phase_h.php
```
Le script retourne un JSON `passed/failed` et un code de sortie non nul en cas d'échec.

## Limites / statut
Cette livraison automatise les premiers tests de corpus et de contrat. Elle ne prouve pas l'exactitude des calculs scolaires, le fonctionnement complet de Gemini, le suivi conversationnel de bout en bout, ni la sécurité concurrentielle de la saisie des notes. Les tests d'intégration, de permissions et de performance restent des gates avant merge/activation. Aucun merge n'est réalisé par cette phase.
