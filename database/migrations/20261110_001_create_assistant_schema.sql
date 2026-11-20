-- Assistant scolaire : schema technique uniquement.
-- Cette migration ne modifie aucune table metier existante.
-- Compatible MySQL/MariaDB et rejouable sans supprimer de donnees.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assistant_conversations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `utilisateur_id` INT(11) NOT NULL,
    `titre` VARCHAR(160) NOT NULL DEFAULT 'Nouvelle conversation',
    `expires_at` DATETIME NOT NULL,
    `last_message_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_assistant_conversations_user_expiry` (`utilisateur_id`, `expires_at`),
    KEY `idx_assistant_conversations_user_updated` (`utilisateur_id`, `updated_at`),
    CONSTRAINT `fk_assistant_conversations_user`
        FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assistant_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('user', 'assistant', 'system', 'tool') NOT NULL,
    `contenu` LONGTEXT NOT NULL,
    `intention` VARCHAR(80) DEFAULT NULL,
    `metadata_json` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_assistant_messages_conversation_created` (`conversation_id`, `created_at`),
    CONSTRAINT `fk_assistant_messages_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `assistant_conversations` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assistant_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `utilisateur_id` INT(11) NOT NULL,
    `nom_original` VARCHAR(255) NOT NULL,
    `nom_stockage` VARCHAR(255) NOT NULL,
    `chemin_stockage` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `extension` VARCHAR(12) NOT NULL,
    `taille_octets` INT UNSIGNED NOT NULL,
    `empreinte_sha256` CHAR(64) NOT NULL,
    `statut` ENUM('pending', 'validated', 'rejected', 'imported') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_assistant_attachment_hash` (`conversation_id`, `empreinte_sha256`),
    KEY `idx_assistant_attachments_user_created` (`utilisateur_id`, `created_at`),
    CONSTRAINT `fk_assistant_attachments_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `assistant_conversations` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assistant_attachments_user`
        FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assistant_imports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attachment_id` BIGINT UNSIGNED NOT NULL,
    `utilisateur_id` INT(11) NOT NULL,
    `statut` ENUM('preview', 'confirmed', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'preview',
    `lignes_total` INT UNSIGNED NOT NULL DEFAULT 0,
    `lignes_valides` INT UNSIGNED NOT NULL DEFAULT 0,
    `lignes_ignorees` INT UNSIGNED NOT NULL DEFAULT 0,
    `lignes_erreur` INT UNSIGNED NOT NULL DEFAULT 0,
    `rapport_json` LONGTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_assistant_imports_user_created` (`utilisateur_id`, `created_at`),
    KEY `idx_assistant_imports_attachment` (`attachment_id`),
    CONSTRAINT `fk_assistant_imports_attachment`
        FOREIGN KEY (`attachment_id`) REFERENCES `assistant_attachments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assistant_imports_user`
        FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `permissions`
    (`code`, `libelle`, `description`, `module`, `action`)
VALUES
    ('assistant.consulter', 'Utiliser l’assistant', 'Poser des questions et consulter ses conversations', 'assistant', 'consulter'),
    ('assistant.importer', 'Importer via l’assistant', 'Prévisualiser et confirmer un import de données', 'assistant', 'importer'),
    ('assistant.supprimer', 'Supprimer ses conversations', 'Supprimer ses conversations et pièces jointes', 'assistant', 'supprimer');

-- L’assistant est visible pour les rôles qui peuvent accéder au tableau de bord.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.role_id, p_assistant.id
FROM `role_permission` rp
INNER JOIN `permissions` p_dashboard ON p_dashboard.id = rp.permission_id
INNER JOIN `permissions` p_assistant ON p_assistant.code = 'assistant.consulter'
WHERE p_dashboard.code = 'dashboard.consulter';

-- L’import reste réservé aux rôles qui peuvent déjà importer des élèves.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.role_id, p_assistant.id
FROM `role_permission` rp
INNER JOIN `permissions` p_import ON p_import.id = rp.permission_id
INNER JOIN `permissions` p_assistant ON p_assistant.code = 'assistant.importer'
WHERE p_import.code = 'eleves.importer';

-- La suppression des conversations suit la permission de suppression des messages.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.role_id, p_assistant.id
FROM `role_permission` rp
INNER JOIN `permissions` p_messages ON p_messages.id = rp.permission_id
INNER JOIN `permissions` p_assistant ON p_assistant.code = 'assistant.supprimer'
WHERE p_messages.code = 'messages.supprimer';

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261110_001_create_assistant_schema');

SET FOREIGN_KEY_CHECKS = 1;
