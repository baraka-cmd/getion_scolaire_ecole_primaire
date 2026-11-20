-- Autorise les utilisateurs de l'assistant à supprimer leurs propres conversations.
-- Le contrôleur vérifie toujours la propriété de la conversation.
INSERT IGNORE INTO `role_permission` (`role_id`, `permission_id`)
SELECT rp.role_id, p_delete.id
FROM `role_permission` rp
INNER JOIN `permissions` p_consult
    ON p_consult.id = rp.permission_id
INNER JOIN `permissions` p_delete
    ON p_delete.code = 'assistant.supprimer'
WHERE p_consult.code = 'assistant.consulter';

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261110_003_grant_assistant_conversation_deletion');
