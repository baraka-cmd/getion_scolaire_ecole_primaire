<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../../../../src/config/app.php';
requireLogin();
redirect(APP_BASE_PATH . '/comptable/rapport-financier');