<?php

defined('ACCESS') or die('Accès interdit');

class NotificationPushService
{
    private const MAX_SUBSCRIPTIONS_PER_USER = 10;

    public function __construct(private Database $db)
    {
    }

    public static function isConfigured(): bool
    {
        $publicKey = self::decodeBase64Url((string) PUSH_VAPID_PUBLIC_KEY);
        $privateKey = self::decodeBase64Url((string) PUSH_VAPID_PRIVATE_KEY);
        $encryptionKey = self::decodeBase64Url((string) PUSH_SUBSCRIPTION_ENCRYPTION_KEY);
        $subject = (string) PUSH_VAPID_SUBJECT;
        $subjectUrl = filter_var($subject, FILTER_VALIDATE_URL) !== false ? parse_url($subject) : false;
        $validSubject = preg_match('/^mailto:[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/i', $subject) === 1
            || (
                is_array($subjectUrl)
                && strtolower((string) ($subjectUrl['scheme'] ?? '')) === 'https'
                && !isset($subjectUrl['user'])
                && !isset($subjectUrl['pass'])
            );

        return $publicKey !== false
            && strlen($publicKey) === 65
            && ord($publicKey[0]) === 4
            && $privateKey !== false
            && strlen($privateKey) === 32
            && $encryptionKey !== false
            && strlen($encryptionKey) === 32
            && $validSubject;
    }

    public function register(int $userId, array $subscription): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Compte utilisateur invalide.');
        }
        if (!self::isConfigured() || !function_exists('openssl_encrypt')) {
            throw new RuntimeException('Les notifications push ne sont pas encore configurées sur le serveur.');
        }

        $validated = self::validateSubscription($subscription);
        $endpointHash = hash('sha256', $validated['endpoint']);
        $existing = $this->db->fetchOne(
            'SELECT id, utilisateur_id FROM notification_push_subscriptions WHERE endpoint_hash = :endpoint_hash',
            ['endpoint_hash' => $endpointHash]
        );

        if ($existing && (int) $existing['utilisateur_id'] !== $userId) {
            throw new DomainException('Cet abonnement est déjà associé à un autre compte.');
        }

        $activeCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM notification_push_subscriptions
             WHERE utilisateur_id = :user_id AND statut = 'actif'",
            ['user_id' => $userId]
        );
        if (!$existing && $activeCount >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw new LengthException('Ce compte a atteint la limite de 10 appareils enregistrés.');
        }

        $encryptedSubscription = self::encrypt(
            json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $userId . ':' . $endpointHash
        );
        if ($existing) {
            $this->db->update(
                "UPDATE notification_push_subscriptions
                 SET subscription_chiffree = :subscription, statut = 'actif',
                     dernier_enregistrement = NOW(), invalide_le = NULL
                 WHERE id = :id AND utilisateur_id = :user_id",
                [
                    'subscription' => $encryptedSubscription,
                    'id' => $existing['id'],
                    'user_id' => $userId
                ]
            );
            return ['id' => (int) $existing['id'], 'updated' => true];
        }

        $id = $this->db->insert(
            "INSERT INTO notification_push_subscriptions
                (utilisateur_id, endpoint_hash, subscription_chiffree, statut, dernier_enregistrement)
             VALUES (:user_id, :endpoint_hash, :subscription, 'actif', NOW())",
            [
                'user_id' => $userId,
                'endpoint_hash' => $endpointHash,
                'subscription' => $encryptedSubscription
            ]
        );

        return ['id' => $id, 'updated' => false];
    }

    public function remove(int $userId, string $endpoint): bool
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Compte utilisateur invalide.');
        }
        $validated = self::validateEndpoint($endpoint);
        return $this->db->delete(
            'DELETE FROM notification_push_subscriptions WHERE utilisateur_id = :user_id AND endpoint_hash = :endpoint_hash',
            ['user_id' => $userId, 'endpoint_hash' => hash('sha256', $validated)]
        ) > 0;
    }

    public static function enqueueForNotification(Database $db, int $notificationId, int $userId): int
    {
        $statement = $db->query(
            "INSERT IGNORE INTO notification_push_queue (notification_id, subscription_id, statut)
             SELECT n.id, s.id, 'en_attente'
             FROM notification_push_subscriptions s
             INNER JOIN notifications n ON n.utilisateur_id = s.utilisateur_id
             INNER JOIN utilisateurs u ON u.id = s.utilisateur_id AND u.est_actif = 1
             INNER JOIN parents_tuteurs pt ON pt.utilisateur_id = u.id
             INNER JOIN eleve_parent ep ON ep.parent_id = pt.id
             INNER JOIN inscriptions i ON i.eleve_id = ep.eleve_id
             WHERE n.id = :notification_id
               AND s.utilisateur_id = :user_id
               AND s.statut = 'actif'
               AND i.annee_scolaire_id = (
                   SELECT annee_scolaire_active_id FROM parametres_ecole WHERE id = 1
               )
               AND i.statut IN ('Actif','Redoublant')",
            ['notification_id' => $notificationId, 'user_id' => $userId]
        );
        return $statement->rowCount();
    }

    public static function encrypt(string $plaintext, string $associatedData = ''): string
    {
        $key = self::decodeBase64Url((string) PUSH_SUBSCRIPTION_ENCRYPTION_KEY);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('La clé de chiffrement des abonnements push est invalide.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Impossible de chiffrer l’abonnement push.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded, string $associatedData = ''): string
    {
        $key = self::decodeBase64Url((string) PUSH_SUBSCRIPTION_ENCRYPTION_KEY);
        $payload = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32 || $payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Données chiffrées d’abonnement push invalides.');
        }

        $plaintext = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
            $associatedData
        );
        if ($plaintext === false) {
            throw new RuntimeException('Impossible de déchiffrer l’abonnement push.');
        }

        return $plaintext;
    }

    public static function validateSubscription(array $subscription): array
    {
        $endpoint = $subscription['endpoint'] ?? null;
        $keys = $subscription['keys'] ?? null;
        if (!is_string($endpoint) || !is_array($keys)) {
            throw new InvalidArgumentException('Abonnement push invalide.');
        }

        $endpoint = self::validateEndpoint($endpoint);
        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;
        if (!is_string($p256dh) || !is_string($auth)) {
            throw new InvalidArgumentException('Clés de chiffrement push absentes.');
        }

        $decodedP256dh = self::decodeBase64Url($p256dh);
        $decodedAuth = self::decodeBase64Url($auth);
        if (
            $decodedP256dh === false
            || strlen($decodedP256dh) !== 65
            || ord($decodedP256dh[0]) !== 4
            || $decodedAuth === false
            || strlen($decodedAuth) !== 16
        ) {
            throw new InvalidArgumentException('Clés de chiffrement push invalides.');
        }

        $expirationTime = $subscription['expirationTime'] ?? null;
        if ($expirationTime !== null && !is_int($expirationTime) && !is_float($expirationTime)) {
            throw new InvalidArgumentException('Expiration d’abonnement push invalide.');
        }

        return [
            'endpoint' => $endpoint,
            'expirationTime' => $expirationTime,
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth]
        ];
    }

    private static function validateEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Adresse d’abonnement push invalide.');
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('L’adresse d’abonnement push doit être une URL HTTPS valide.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedPushHost =
            $host === 'fcm.googleapis.com'
            || in_array($host, ['push.services.mozilla.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || preg_match('/^[a-z0-9-]+(?:\\.[a-z0-9-]+)*\\.notify\\.windows\\.com$/', $host) === 1;
        if (
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !$allowedPushHost
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('L’adresse d’abonnement push doit être une URL HTTPS valide.');
        }

        return $endpoint;
    }

    private static function decodeBase64Url(string $value): string|false
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return false;
        }

        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        return base64_decode($base64, true);
    }
}
