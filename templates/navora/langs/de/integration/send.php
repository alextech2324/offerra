<?php
declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');

date_default_timezone_set('Europe/Kyiv');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/FormToken.php';
require_once __DIR__ . '/LeadProcessor.php';

function resolve_preflight_spam_reason(): ?string
{
    if (! FormToken::requestViaCloudflare()) {
        return 'NO_CLOUDFLARE';
    }

    if (! FormToken::requestLooksSameOrigin()) {
        return 'ORIGIN_MISMATCH';
    }

    if (FormToken::looksLikeBotUa()) {
        return 'BOT_UA';
    }

    if (FormToken::requestFromChatGpt()) {
        return 'CHATGPT_REFERRAL';
    }

    if (FormToken::submitRateExceeded()) {
        return 'RATE_LIMIT';
    }

    return null;
}

function resolve_form_token_spam_reason(string $token): ?string
{
    if ($token === '') {
        return 'FORM_TOKEN_MISSING';
    }

    $consumed = FormToken::consume($token);

    if (! ($consumed['ok'] ?? false)) {
        return match ((string) ($consumed['error'] ?? '')) {
            'expired' => 'FORM_TOKEN_EXPIRED',
            'missing_or_used' => 'FORM_TOKEN_USED',
            default => 'FORM_TOKEN_INVALID',
        };
    }

    if (! empty($consumed['drop'])) {
        if (($consumed['error'] ?? '') === 'too_fast') {
            return 'FORM_TOKEN_TOO_FAST';
        }

        return 'FORM_TOKEN_DROP';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$preflightReason = resolve_preflight_spam_reason();

$lead = LeadProcessor::parsePayload();

if ($lead && function_exists('offer_click_id')) {
    $lead['subid'] = offer_click_id();
}

if (! $lead) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty request'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string) ($lead['form_token'] ?? ''));
unset($lead['form_token']);

$spamReason = $preflightReason;

if ($spamReason === null) {
    $spamReason = resolve_form_token_spam_reason($token);
}

$hasContactFields = trim((string) ($lead['first_name'] ?? $lead['fname'] ?? '')) !== ''
    && trim((string) ($lead['last_name'] ?? $lead['lname'] ?? '')) !== ''
    && trim((string) ($lead['email'] ?? '')) !== ''
    && LeadProcessor::normalizePhone((string) ($lead['phone'] ?? $lead['fullphone'] ?? '')) !== '';

// Expired tokens share the submit cap: the first three complete ones per IP
// in the window stay visible, the rest become RATE_LIMIT in preflight.
if ($spamReason === null || ($spamReason === 'FORM_TOKEN_EXPIRED' && $hasContactFields)) {
    FormToken::hitSubmitRate();
}

$result = LeadProcessor::process($lead, $spamReason);
$status = (int) ($result['http_status'] ?? 200);
unset($result['http_status']);

http_response_code($status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
