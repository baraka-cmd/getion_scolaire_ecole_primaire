-- Phase G : demandes d'actions assistant à confirmation explicite.
-- Les modifications métier restent dans leurs services officiels.
CREATE TABLE IF NOT EXISTS assistant_action_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id INT NOT NULL,
    action_code VARCHAR(80) NOT NULL,
    payload_json TEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    confirmation_hash CHAR(64) NOT NULL,
    statut ENUM('pending','executed','cancelled','expired','failed') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    executed_at DATETIME NULL,
    result_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assistant_action_user_status (utilisateur_id, statut, expires_at),
    CONSTRAINT fk_assistant_action_user FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (code, libelle, description, module, action)
VALUES
 ('assistant.actions.proposer','Préparer une action via l’assistant','Préparer une demande de modification soumise à confirmation et revalidation métier','assistant','proposer'),
 ('assistant.security.audit','Consulter les contrôles de sécurité de l’assistant','Consulter les vérifications de configuration des capacités assistant','assistant','consulter');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, pa.id
FROM role_permission rp
JOIN permissions pn ON pn.id = rp.permission_id AND pn.code = 'notes.saisir'
JOIN permissions pa ON pa.code = 'assistant.actions.proposer';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT rp.role_id, pa.id
FROM role_permission rp
JOIN permissions pl ON pl.id = rp.permission_id AND pl.code = 'logs.consulter'
JOIN permissions pa ON pa.code = 'assistant.security.audit';

INSERT IGNORE INTO app_migrations (version)
VALUES ('20261128_002_assistant_security_actions');
