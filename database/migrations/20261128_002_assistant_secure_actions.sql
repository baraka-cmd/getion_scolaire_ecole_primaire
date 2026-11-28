-- Phase G : demandes d'actions assistant et journal d'audit append-only.
-- Aucune écriture scolaire n'est activée par cette migration.
CREATE TABLE IF NOT EXISTS assistant_action_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id INT NOT NULL,
    conversation_id BIGINT UNSIGNED NULL,
    action_code VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    payload_json LONGTEXT NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    confirmation_token_hash CHAR(64) NULL,
    statut ENUM('draft','confirmed','cancelled','expired','executed','rejected') NOT NULL DEFAULT 'draft',
    expires_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    executed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assistant_action_user_status (utilisateur_id, statut, expires_at),
    KEY idx_assistant_action_conversation (conversation_id),
    CONSTRAINT fk_assistant_action_user FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id) ON DELETE CASCADE,
    CONSTRAINT fk_assistant_action_conversation FOREIGN KEY (conversation_id)
        REFERENCES assistant_conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistant_security_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT NULL,
    event_code VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NULL,
    target_id VARCHAR(100) NULL,
    outcome ENUM('success','denied','error') NOT NULL,
    request_id VARCHAR(64) NULL,
    details_json LONGTEXT NULL,
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assistant_audit_actor_time (actor_user_id, created_at),
    KEY idx_assistant_audit_event_time (event_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (code, libelle, description, module, action)
VALUES ('assistant.actions.confirmer', 'Confirmer une action préparée par l’assistant',
        'Confirmer une proposition d’action métier après vérification serveur', 'assistant', 'confirmer');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, p_action.id
FROM role_permission rp
INNER JOIN permissions p_evaluation ON p_evaluation.id = rp.permission_id
INNER JOIN permissions p_action ON p_action.code = 'assistant.actions.confirmer'
WHERE p_evaluation.code = 'evaluations.modifier';

INSERT IGNORE INTO app_migrations (version)
VALUES ('20261128_002_assistant_secure_actions');
