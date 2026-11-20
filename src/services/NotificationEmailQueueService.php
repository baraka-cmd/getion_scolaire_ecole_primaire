<?php

defined('ACCESS') or die('Accès interdit');

require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'EmailService.php';

class NotificationEmailQueueService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_TIMEOUT_MINUTES = 15;

    public function __construct(private Database $db)
    {
    }

    public function processBatch(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $lockToken = bin2hex(random_bytes(16));

        $this->db->update(
            "UPDATE notification_email_queue
             SET statut = 'echec', verrouille_le = NULL, jeton_verrou = NULL,
                 derniere_erreur = 'Le traitement précédent a expiré après le nombre maximal de tentatives.'
             WHERE statut = 'en_cours'
               AND verrouille_le < DATE_SUB(NOW(), INTERVAL " . self::LOCK_TIMEOUT_MINUTES . " MINUTE)
               AND tentatives >= " . self::MAX_ATTEMPTS
        );
        $this->db->update(
            "UPDATE notification_email_queue
             SET statut = 'en_attente', verrouille_le = NULL, jeton_verrou = NULL,
                 disponible_le = NOW()
             WHERE statut = 'en_cours'
               AND verrouille_le < DATE_SUB(NOW(), INTERVAL " . self::LOCK_TIMEOUT_MINUTES . " MINUTE)
               AND tentatives < " . self::MAX_ATTEMPTS
        );

        $this->db->update(
            "UPDATE notification_email_queue
             SET statut = 'en_cours', tentatives = tentatives + 1,
                 verrouille_le = NOW(), jeton_verrou = :token
             WHERE statut = 'en_attente' AND disponible_le <= NOW()
             ORDER BY id
             LIMIT {$limit}",
            ['token' => $lockToken]
        );

        $queueItems = $this->db->fetchAll(
            "SELECT q.id, q.notification_id, q.destinataire_email, q.tentatives,
                    n.titre, n.message, n.type_notification
             FROM notification_email_queue q
             INNER JOIN notifications n ON n.id = q.notification_id
             WHERE q.jeton_verrou = :token AND q.statut = 'en_cours'
             ORDER BY q.id",
            ['token' => $lockToken]
        );

        $emailService = new EmailService();
        $stats = ['traites' => 0, 'envoyes' => 0, 'en_attente' => 0, 'echecs' => 0];

        foreach ($queueItems as $item) {
            $stats['traites']++;
            $contenu = '<p>Bonjour,</p><p>' . nl2br(e($item['message'])) . '</p>';
            $contenu .= '<p style="color:#64748b;font-size:13px;">Notification officielle de l’établissement scolaire.</p>';

            try {
                $sent = $emailService->envoyerTemplate(
                    $item['destinataire_email'],
                    $item['titre'],
                    $contenu
                );
            } catch (Throwable $e) {
                error_log('Échec du traitement email de notification #' . $item['notification_id'] . ': ' . $e->getMessage());
                $sent = false;
            }

            if ($sent) {
                $this->db->update(
                    "UPDATE notification_email_queue
                     SET statut = 'envoye', envoye_le = NOW(), verrouille_le = NULL,
                         jeton_verrou = NULL, derniere_erreur = NULL
                     WHERE id = :id AND jeton_verrou = :token",
                    ['id' => $item['id'], 'token' => $lockToken]
                );
                $stats['envoyes']++;
                continue;
            }

            $attempts = (int) $item['tentatives'];
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->db->update(
                    "UPDATE notification_email_queue
                     SET statut = 'echec', verrouille_le = NULL, jeton_verrou = NULL,
                         derniere_erreur = :error
                     WHERE id = :id AND jeton_verrou = :token",
                    [
                        'error' => 'Le serveur de messagerie a refusé ou n’a pas confirmé l’envoi après plusieurs tentatives.',
                        'id' => $item['id'],
                        'token' => $lockToken
                    ]
                );
                $stats['echecs']++;
                continue;
            }

            $delaySeconds = min(3600, 60 * (2 ** max(0, $attempts - 1)));
            $this->db->update(
                "UPDATE notification_email_queue
                 SET statut = 'en_attente',
                     disponible_le = DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND),
                     verrouille_le = NULL, jeton_verrou = NULL,
                     derniere_erreur = :error
                 WHERE id = :id AND jeton_verrou = :token",
                [
                    'error' => 'Échec temporaire de l’envoi; une nouvelle tentative est planifiée.',
                    'id' => $item['id'],
                    'token' => $lockToken
                ]
            );
            $stats['en_attente']++;
        }

        return $stats;
    }
}
