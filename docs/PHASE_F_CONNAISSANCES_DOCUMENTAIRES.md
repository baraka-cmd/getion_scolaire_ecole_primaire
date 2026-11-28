# Phase F — Connaissances documentaires et procédures scolaires

## Références lues avant l'implémentation
- `docs/REFLEXION_AMELIORATION_ASSISTANT_IA.md`
- `docs/PLAN_DETAILLE_FONCTIONNEMENT_ASSISTANT_IA.md`
- `docs/PHASE_D_DONNEES_REELLES_ANALYSES.md`
- `docs/PHASE_E_CONVERSATION_EXPERIENCE.md`
- Architecture : `DocumentRepository`, `DocumentService`, `DocumentController`, `AssistantRepository`, `GeminiService`, `AssistantController` et routeur frontal.

## Principe
Le RAG est distinct des capacités SQL de Phase D :
document approuvé → extraction contrôlée → découpage → index local → recherche → passages avec références → génération Gemini ancrée → réponse citée.
Les documents sont des données non fiables, jamais des instructions système. Les faits de la base scolaire restent produits par les services métier.

## F.1 — Sources
La bibliothèque existante `documents` est la source centrale : règlement intérieur, procédures d'inscription, règles de notes, paiements, guides administratifs et supports pédagogiques approuvés. Pas de second stockage documentaire métier.

## F.2 — Formats
Indexation initiale : TXT, Markdown et CSV textuels. PDF, DOC/DOCX, XLS/XLSX et PPT/PPTX restent formats candidats, mais ne sont pas ingérés sans extracteur dédié et éprouvé. Taille maximale 2 Mo ; chunks 1 400 caractères, chevauchement 180, maximum 500 chunks/document.

## F.3 — Stockage et indexation
Migration additive `20261128_001_assistant_knowledge_index` crée `assistant_knowledge_chunks`, hash de contenu, identifiant source, index FULLTEXT. Les originaux restent dans le stockage privé du module Documents.

## F.4–F.5 — Recherche et passages
Endpoints ajoutés : `POST /api/assistant/knowledge/index` (indexation réservée à Admin/Directeur avec permission de gestion des documents) et `POST /api/assistant/knowledge/search` (recherche après authentification, permission dédiée et CSRF). La réponse expose les extraits et leur identifiant source pour audit/citation.
`AssistantDocumentKnowledgeService` valide extension, taille, encodage et chemin canonique privé avant indexation. La recherche lexicale classe les extraits et retourne titre, catégorie, source, identifiant et position pour citation. Les utilisateurs non administrateurs ne voient que les documents publics.

## F.6–F.7 — Gemini et citations
Cette tranche crée l'indexeur et le contrat de passages référencés. Le raccordement final de génération Gemini à ce contexte doit passer par l'orchestrateur, sans transformer le classificateur existant en générateur non contrôlé. Les extraits doivent être limités, encadrés comme données non fiables et accompagnés de citations. Gemini n'est donc pas encore déclaré connecté à toutes les questions procédurales.

## F.8 — Contrôles requis
Tester visibilité publique/privée, chemin traversal, source supprimée/modifiée, texte vide/binaire, injection dans les extraits, pertinence et réponse sans source. Le modèle doit reconnaître l'absence de source au lieu d'inventer une règle.

## Sécurité et limites
- Backend responsable de la visibilité et des permissions ; recherche publique uniquement pour les documents marqués publics, sauf rôle Admin/Directeur.
- Indexation atomique : une erreur ne doit pas laisser un index partiellement remplacé.
- Aucun SQL ni accès direct à la base métier par Gemini.
- Chemin résolu sous `DOCUMENTS_STORAGE_PATH`, taille et extensions bornées.
- PDF/Office, embeddings/vector store et tests intégrés restent des évolutions.
- La recherche actuelle est lexicale et limitée à 400 chunks récents : ce n'est pas encore une recherche sémantique complète.

**Statut :** première tranche Phase F écrite sur la branche : migration, indexeur privé, recherche lexicale et endpoints protégés. Elle fournit des extraits citables, mais n'injecte pas encore ces extraits dans une réponse Gemini conversationnelle. Tests syntaxiques, intégration locale/CI et fusion restent à effectuer. RAG conversationnel Gemini complet, tests et fusion ne sont pas déclarés terminés.
