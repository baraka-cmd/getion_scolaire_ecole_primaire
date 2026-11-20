SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `auth_rate_limits` (
    `scope` ENUM('login','password_reset') NOT NULL,
    `subject_type` ENUM('ip','email') NOT NULL DEFAULT 'ip',
    `subject_hash` CHAR(64) NOT NULL,
    `failed_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME NOT NULL,
    `blocked_until` DATETIME DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`scope`,`subject_type`,`subject_hash`),
    KEY `idx_auth_rate_limits_window` (`window_started_at`),
    KEY `idx_auth_rate_limits_blocked` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uk_password_reset_token_hash` (`token_hash`),
    KEY `idx_password_reset_expires` (`expires_at`),
    CONSTRAINT `fk_password_reset_user`
        FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261119_001_auth_hardening');
