<?php
declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

date_default_timezone_set('Europe/Kyiv');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/FormToken.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Always return a token. Soft blocks (bot UA / rate) mark drop server-side;
// send.php then fakes thank-you success without CRM.
echo json_encode([
    'ok' => true,
    'token' => FormToken::issue(),
    'ttl' => defined('FORM_TOKEN_TTL') ? (int) FORM_TOKEN_TTL : 600,
    'min_age' => FormToken::minAge(),
], JSON_UNESCAPED_UNICODE);
