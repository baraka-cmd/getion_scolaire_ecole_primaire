<?php
defined('ACCESS') or define('ACCESS', true);

$configPath = __DIR__ . '/../../../src/config/app.php';

if (!file_exists($configPath)) {
    http_response_code(404);
    exit;
}

require_once $configPath;
require_once SRC_PATH . 'controllers' . DIRECTORY_SEPARATOR . 'AuthController.php';
(new AuthController())->redirectToGoogle();
