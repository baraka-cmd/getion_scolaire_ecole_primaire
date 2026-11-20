<?php

defined('ACCESS') or die('Accès interdit');

require_once ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'NotificationPushService.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationPushQueueService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_TIMEOUT_MINUTES = 15;

    public function __construct(private Database $db)
    {
    }

    public function processBatch(int $limit = 20): array
    {
        if (!NotificationPushService::isConfigured()) {
            throw new RuntimeException('La configuration VAPID des notifications push est invalide.');
        }

        $limit = max(1, min($limit, 100));
        $lockToken = bin2hex(random_bytes(16));

        $this->db->update(
            "UPDATE notification_push_queue
             SET statut = 'echec', verrouille_le = NULL, jeton_verrou = NULL,
                 derniere_erreur = 'Le traitement précédent a expiré après le nombre maximal de tentatives.'
             WHERE statut = 'en_cours'
               AND verrouille_le < DATE_SUB(NOW(), INTERVAL " . self::LOCK_TIMEOUT_MINUTES . " MINUTE)
               AND tentatives >= " . self::MAX_ATTEMPTS
        );
        $this->db->update(
            "UPDATE notification_push_queue
             SET statut = 'en_attente', verrouille_le = NULL, jeton_verrou = NULL,
                 disponible_le = NOW()
             WHERE statut = 'en_cours'
               AND verrouille_le < DATE_SUB(NOW(), INTERVAL " . self::LOCK_TIMEOUT_MINUTES . " MINUTE)
               AND tentatives < " . self::MAX_ATTEMPTS
        );
        $this->db->update(
            "UPDATE notification_push_queue
             SET statut = 'en_cours', tentatives = tentatives + 1,
                 verrouille_le = NOW(), jeton_verrou = :token
             WHERE statut = 'en_attente' AND disponible_le <= NOW()
             ORDER BY id
             LIMIT {$limit}",
            ['token' => $lockToken]
        );

        $queueItems = $this->db->fetchAll(
            "SELECT q.id, q.notification_id, q.subscription_id, q.tentatives,
                    s.utilisateur_id, s.endpoint_hash, s.subscription_chiffree, s.statut AS subscription_status
             FROM notification_push_queue q
             INNER JOIN notification_push_subscriptions s ON s.id = q.subscription_id
             WHERE q.jeton_verrou = :token AND q.statut = 'en_cours'
             ORDER BY q.id",
            ['token' => $lockToken]
        );

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => PUSH_VAPID_SUBJECT,
                'publicKey' => PUSH_VAPID_PUBLIC_KEY,
                'privateKey' => PUSH_VAPID_PRIVATE_KEY
            ]
        ], ['TTL' => 86400, 'urgency' => 'normal', 'batchSize' => 1], 20);
        $stats = ['traites' => 0, 'envoyes' => 0, 'en_attente' => 0, 'echecs' => 0, 'invalides' => 0];

        foreach ($queueItems as $item) {
            $stats['traites']++;
            if ($item['subscription_status'] !== 'actif') {
                $this->markInvalid((int) $item['id'], (int) $item['subscription_id'], $lockToken);
                $stats['invalides']++;
                continue;
            }
            if (!$this->isRecipientEligible((int) $item['notification_id'], (int) $item['utilisateur_id'])) {
                $this->markFailed((int) $item['id'], $lockToken, 'Le destinataire ne remplit plus les conditions de réception des notifications.');
                $stats['echecs']++;
                continue;
            }

            try {
                $associatedData = (int) $item['utilisateur_id'] . ':' . $item['endpoint_hash'];
                $plaintext = NotificationPushService::decrypt($item['subscription_chiffree'], $associatedData);
                $subscriptionData = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($subscriptionData)) {
                    throw new UnexpectedValueException('Abonnement push illisible.');
                }
                $subscriptionData = NotificationPushService::validateSubscription($subscriptionData);
                if (!hash_equals($item['endpoint_hash'], hash('sha256', $subscriptionData['endpoint']))) {
                    throw new UnexpectedValueException('Abonnement push incohérent.');
                }

                $subscriptionData['contentEncoding'] = 'aes128gcm';
                $subscription = Subscription::create($subscriptionData);
                $payload = json_encode([
                    'id' => (string) $item['notification_id'],
                    'title' => 'Nouvelle notification de l’école',
                    'body' => 'Connectez-vous à votre espace parent pour la consulter.',
                    'url' => 'notifications'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $report = $webPush->sendOneNotification($subscription, $payload);
            } catch (Throwable $e) {
                error_log('Traitement push impossible pour la livraison #' . $item['id'] . ' (' . get_class($e) . ').');
                $this->markFailed((int) $item['id'], $lockToken, 'La livraison push ne peut pas être traitée avec les données ou la configuration actuelles.');
                $stats['echecs']++;
                continue;
            }

            if ($report->isSuccess()) {
                $this->db->update(
                    "UPDATE notification_push_queue
                     SET statut = 'envoye', envoye_le = NOW(), verrouille_le = NULL,
                         jeton_verrou = NULL, derniere_erreur = NULL
                     WHERE id = :id AND jeton_verrou = :token",
                    ['id' => $item['id'], 'token' => $lockToken]
                );
                $stats['envoyes']++;
                continue;
            }

            $response = $report->getResponse();
            $statusCode = $response ? $response->getStatusCode() : null;
            if ($report->isSubscriptionExpired()) {
                $this->markInvalid((int) $item['id'], (int) $item['subscription_id'], $lockToken);
                $stats['invalides']++;
                continue;
            }

            $attempts = (int) $item['tentatives'];
            $retryable = $statusCode === null || $statusCode === 408 || $statusCode === 429 || $statusCode >= 500;
            if (!$retryable || $attempts >= self::MAX_ATTEMPTS) {
                $error = $statusCode === null
                    ? 'Échec de connexion au fournisseur push après plusieurs tentatives.'
                    : 'Le fournisseur push a refusé la livraison (HTTP ' . $statusCode . ').';
                $this->markFailed((int) $item['id'], $lockToken, $error);
                $stats['echecs']++;
                continue;
            }

            $delaySeconds = min(3600, 60 * (2 ** max(0, $attempts - 1)));
            $this->db->update(
                "UPDATE notification_push_queue
                 SET statut = 'en_attente',
                     disponible_le = DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND),
                     verrouille_le = NULL, jeton_verrou = NULL,
                     derniere_erreur = 'Échec temporaire auprès du fournisseur push; une nouvelle tentative est planifiée.'
                 WHERE id = :id AND jeton_verrou = :token",
                ['id' => $item['id'], 'token' => $lockToken]
            );
            $stats['en_attente']++;
        }

        return $stats;
    }

    private function markFailed(int $queueId, string $lockToken, string $reason): void
    {
        $this->db->update(
            "UPDATE notification_push_queue
             SET statut = 'echec', verrouille_le = NULL, jeton_verrou = NULL,
                 derniere_erreur = :error
             WHERE id = :id AND jeton_verrou = :token",
            ['error' => $reason, 'id' => $queueId, 'token' => $lockToken]
        );
    }

    private function markInvalid(int $queueId, int $subscriptionId, string $lockToken): void
    {
        $this->db->update(
            "UPDATE notification_push_subscriptions
             SET statut = 'invalide', invalide_le = NOW()
             WHERE id = :subscription_id",
            ['subscription_id' => $subscriptionId]
        );
        $this->db->update(
            "UPDATE notification_push_queue
             SET statut = 'invalide', verrouille_le = NULL, jeton_verrou = NULL,
                 derniere_erreur = 'Le fournisseur push a indiqué que cet abonnement est indisponible.'
             WHERE id = :id AND jeton_verrou = :token",
            ['id' => $queueId, 'token' => $lockToken]
        );
    }

    private function isRecipientEligible(int $notificationId, int $userId): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT EXISTS (
                 SELECT 1
                 FROM notifications n
                 INNER JOIN utilisateurs u ON u.id = n.utilisateur_id AND u.est_actif = 1
                 INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
                 INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
                 INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
                 WHERE n.id = :notification_id
                   AND n.utilisateur_id = :user_id
                   AND i.annee_scolaire_id = (
                       SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
                   )
                   AND i.statut IN ('Actif','Redoublant')
             )",
            ['notification_id' => $notificationId, 'user_id' => $userId]
        );
    }
}
