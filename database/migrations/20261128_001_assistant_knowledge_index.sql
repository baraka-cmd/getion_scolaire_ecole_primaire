-- Phase F : index documentaire RAG, sans modifier les tables scolaires.
-- Exécuter après la migration assistant de base. Réexécutable sans perte de données.
CREATE TABLE IF NOT EXISTS assistant_knowledge_chunks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    chunk_index SMALLINT UNSIGNED NOT NULL,
    contenu MEDIUMTEXT NOT NULL,
    contenu_hash CHAR(64) NOT NULL,
    source_updated_at DATETIME NULL,
    indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_assistant_knowledge_document_chunk (document_id, chunk_index),
    KEY idx_assistant_knowledge_document (document_id),
    FULLTEXT KEY ft_assistant_knowledge_content (contenu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (code, libelle, description, module, action)
VALUES ('assistant.knowledge.consulter', 'Rechercher dans les documents scolaires',
        'Rechercher les procédures dans les documents autorisés de l’établissement', 'assistant', 'consulter');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, p_knowledge.id
FROM role_permission rp
INNER JOIN permissions p_documents ON p_documents.id = rp.permission_id
INNER JOIN permissions p_knowledge ON p_knowledge.code = 'assistant.knowledge.consulter'
WHERE p_documents.code = 'documents.consulter';

INSERT IGNORE INTO app_migrations (version)
VALUES ('20261128_001_assistant_knowledge_index');
