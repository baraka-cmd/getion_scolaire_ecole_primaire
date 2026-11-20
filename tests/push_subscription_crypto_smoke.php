<?php

define('ACCESS', true);
require_once __DIR__ . '/../src/config/app.php';
require_once SRC_PATH . 'services' . DIRECTORY_SEPARATOR . 'NotificationPushService.php';

if (!NotificationPushService::isConfigured()) {
    fwrite(STDERR, "Push configuration is missing or invalid.\n");
    exit(1);
}

$base64Url = static function (string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
$subscription = [
    'endpoint' => 'https://fcm.googleapis.com/fcm/send/opaque',
    'expirationTime' => null,
    'keys' => [
        'p256dh' => $base64Url("\x04" . random_bytes(64)),
        'auth' => $base64Url(random_bytes(16))
    ]
];

$validated = NotificationPushService::validateSubscription($subscription);
$plaintext = json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$ciphertext = NotificationPushService::encrypt($plaintext, 'test-user:' . hash('sha256', $validated['endpoint']));
if (NotificationPushService::decrypt($ciphertext, 'test-user:' . hash('sha256', $validated['endpoint'])) !== $plaintext) {
    fwrite(STDERR, "Push subscription encryption round-trip failed.\n");
    exit(1);
}

$tampered = base64_decode($ciphertext, true);
$tampered[0] = chr(ord($tampered[0]) ^ 1);
$integrityRejected = false;
try {
    NotificationPushService::decrypt(base64_encode($tampered), 'test-user:' . hash('sha256', $validated['endpoint']));
} catch (RuntimeException $e) {
    $integrityRejected = true;
}
if (!$integrityRejected) {
    fwrite(STDERR, "Tampered push subscription ciphertext was accepted.\n");
    exit(1);
}

$invalidEndpointRejected = false;
try {
    $invalid = $subscription;
    $invalid['endpoint'] = 'http://fcm.googleapis.com/fcm/send/opaque';
    NotificationPushService::validateSubscription($invalid);
} catch (InvalidArgumentException $e) {
    $invalidEndpointRejected = true;
}
if (!$invalidEndpointRejected) {
    fwrite(STDERR, "Non-HTTPS push endpoint was accepted.\n");
    exit(1);
}

echo "Push subscription crypto smoke test passed.\n";
