<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Vary: CF-IPCountry');

require_once __DIR__ . '/../includes/config.php';

$allowed = form_allowed_countries();
$phoneCountry = form_visitor_phone_country();

echo json_encode([
    'phone_country' => $phoneCountry,
    'allowed' => $allowed,
    'ip_known' => form_ip_country() !== '',
], JSON_UNESCAPED_UNICODE);
