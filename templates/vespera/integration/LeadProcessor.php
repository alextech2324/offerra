<?php
declare(strict_types=1);

/**
 * CRM + Telegram lead processor.
 * Shared across all offers — do not edit per funnel.
 */
final class LeadProcessor
{
    public static function parsePayload(): array
    {
        if (!empty($_POST) && is_array($_POST)) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function normalizePhone(string $phoneRaw): string
    {
        $phone = trim($phoneRaw);
        if ($phone === '') {
            return '';
        }

        if ($phone[0] === '+') {
            return '+' . preg_replace('/\D/', '', substr($phone, 1));
        }

        return preg_replace('/\D/', '', $phone);
    }

    /** Статична країна оффера (у Веспери це ML — не слати в CRM). */
    public static function crmCountryCode(): string
    {
        return strtoupper(trim((string) CRM_COUNTRY));
    }

    /** У Telegram замість ML показуємо назву шаблону. */
    public static function telegramOfferLabel(): string
    {
        if (defined('OFFER_TEMPLATE') && trim((string) OFFER_TEMPLATE) !== '') {
            return ucfirst(trim((string) OFFER_TEMPLATE));
        }

        return 'Vespera';
    }

    /** Країна для CRM: IP (CF), інакше phone GEO. ML як країну не віддаємо. */
    public static function crmPayloadCountryCode(array $lead = []): string
    {
        $ip = self::detectIpCountry();
        if ($ip !== '' && $ip !== 'ML') {
            return $ip;
        }

        $phone = strtoupper(trim((string) ($lead['phone_country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $phone) && $phone !== 'ML') {
            return $phone;
        }

        return '';
    }

    /** Країна по IP (Cloudflare CF-IPCountry), без fallback. */
    public static function detectIpCountry(): string
    {
        $cf = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if ($cf !== '' && $cf !== 'XX' && preg_match('/^[A-Z]{2}$/', $cf)) {
            return $cf;
        }

        return '';
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function generatePassword(int $length = 10): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    public static function normalizeLead(array $lead): array
    {
        $subid = trim((string) ($lead['subid'] ?? ''));
        if ($subid === '' || $subid === 'no_subid') {
            $subid = function_exists('offer_click_id') ? offer_click_id() : '';
        }
        if ($subid !== '' && $subid !== 'no_subid') {
            $lead['subid'] = $subid;
            if (empty($lead['aff_sub3'])) {
                $lead['aff_sub3'] = $subid;
            }
        }


        return $lead;
    }

    public static function buildCrmPayload(array $lead): array
    {
        $lead = self::normalizeLead($lead);
        $countryCode = self::crmPayloadCountryCode($lead);
        $language = strtolower(trim((string) ($lead['language'] ?? SITE_LANG)));

        $payload = [
            'ip' => self::clientIp(),
            'email' => trim((string) ($lead['email'] ?? '')),
            'first_name' => trim((string) ($lead['first_name'] ?? $lead['fname'] ?? '')),
            'last_name' => trim((string) ($lead['last_name'] ?? $lead['lname'] ?? '')),
            'password' => self::generatePassword(10),
            'phone' => self::normalizePhone((string) ($lead['phone'] ?? $lead['fullphone'] ?? '')),
            'affiliate_id' => (string) CRM_AFFILIATE_ID,
            'offer_id' => crm_funnel(),
        ];

        if ($countryCode !== '') {
            $payload['country_code'] = $countryCode;
        }

        if ($language !== '') {
            $payload['lead_language'] = $language;
        }

        return array_merge($payload, crm_aff_subs_resolved($lead));
    }

    /** @return array<string, string> */
    private static function applySpamMarkers(array $crmData, string $reason): array
    {
        $crmData['aff_sub12'] = 'SPAM';
        $crmData['aff_sub13'] = $reason;

        return $crmData;
    }

    public static function resolveSpamReason(array $lead, ?string $preflightReason = null): ?string
    {
        if ($preflightReason !== null && $preflightReason !== '') {
            return $preflightReason;
        }

        $langGeo = self::langGeoSpamReason($lead);
        if ($langGeo !== null) {
            return $langGeo;
        }


        return null;
    }

    /**
     * Vespera multi-offer: CRM country comes from IP, form language from the page.
     * FR on `/` (en) must not reach CRM/Telegram. US is dropped entirely.
     */
    public static function langGeoSpamReason(array $lead): ?string
    {
        $ip = self::detectIpCountry();
        if ($ip === '') {
            return null;
        }
        if ($ip === 'UK') {
            $ip = 'GB';
        }

        if (in_array($ip, self::geoSkipCountries(), true)) {
            return 'GEO_SKIP';
        }

        $submitted = strtolower(trim((string) ($lead['language'] ?? '')));
        if ($submitted === '') {
            $submitted = strtolower(defined('SITE_LANG') ? (string) SITE_LANG : 'en');
        }

        $allow = self::geoLangAllow()[$ip] ?? null;
        if (is_array($allow)) {
            return in_array($submitted, $allow, true) ? null : 'LANG_GEO_MISMATCH';
        }

        $map = defined('GEO_LANG_MAP') && is_array(GEO_LANG_MAP) ? GEO_LANG_MAP : [];
        $expected = strtolower(trim((string) ($map[$ip] ?? '')));
        if ($expected === '') {
            return null;
        }

        return $submitted === $expected ? null : 'LANG_GEO_MISMATCH';
    }

    /** @return list<string> */
    private static function geoSkipCountries(): array
    {
        return ['US'];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function geoLangAllow(): array
    {
        return [
            'CH' => ['de', 'fr', 'it'],
            'BE' => ['fr', 'nl', 'de'],
            'CA' => ['en', 'fr'],
            'LU' => ['fr', 'de', 'en'],
        ];
    }



    public static function sendToCrm(array $crmData): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_code' => 0,
                'curl_error' => 'PHP curl extension is not enabled',
                'response' => ['error' => 'Enable extension=curl in php.ini and restart the server'],
            ];
        }

        $url = (string) CRM_API_URL;
        $apiKey = (string) CRM_API_KEY;

        if ($url === '' || $apiKey === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'curl_error' => 'CRM not configured',
                'response' => ['error' => 'CRM_API_URL or CRM_API_KEY is empty'],
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($crmData, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $crmRaw = curl_exec($ch);
        $curlError = $crmRaw === false ? (string) curl_error($ch) : '';
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $crmResponse = json_decode((string) $crmRaw, true);
        if (!is_array($crmResponse)) {
            $crmResponse = ['raw' => $crmRaw];
        }

        if ($curlError !== '') {
            $crmResponse['curl_error'] = $curlError;
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300) || !empty($crmResponse['lead_uuid']),
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => $crmResponse,
        ];
    }

    public static function sendTelegram(string $text): bool
    {
        $botToken = (string) TG_BOT_TOKEN;

        if ($botToken === '') {
            return false;
        }

        $sent = false;

        foreach (self::telegramChatIds() as $chatId) {
            if (self::sendTelegramToChat($botToken, $chatId, $text)) {
                $sent = true;
            }
        }

        return $sent;
    }

    /** @return list<string> */
    private static function telegramChatIds(): array
    {
        $ids = [];

        $personal = trim((string) TG_CHAT_ID);
        if ($personal !== '') {
            $ids[] = $personal;
        }

        if (defined('TG_GROUP_CHAT_ID')) {
            $group = trim((string) TG_GROUP_CHAT_ID);
            if ($group !== '' && ! in_array($group, $ids, true)) {
                $ids[] = $group;
            }
        }

        return $ids;
    }

    private static function sendTelegramToChat(string $botToken, string $chatId, string $text): bool
    {
        if ($chatId === '') {
            return false;
        }

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        $decoded = json_decode((string) $result, true);

        return is_array($decoded) && ($decoded['ok'] ?? false);
    }

    private static function escapeTelegramHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function formatCrmResponseLog(int $httpCode, array $crmResponse, string $curlError = ''): string
    {
        $log = [
            'http_code' => $httpCode,
            'response' => $crmResponse,
        ];

        if ($curlError !== '') {
            $log['curl_error'] = $curlError;
        }

        $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"error":"Failed to encode CRM response"}';
        }

        $maxLen = 2800;
        if (strlen($json) > $maxLen) {
            $json = substr($json, 0, $maxLen) . "\n... (truncated)";
        }

        return '<pre>' . self::escapeTelegramHtml($json) . '</pre>';
    }

    public static function buildTelegramMessage(
        bool $crmSuccess,
        array $crmData,
        array $crmResult
    ): string {
        $crmResponse = is_array($crmResult['response'] ?? null) ? $crmResult['response'] : [];
        $status = $crmSuccess ? '✅ LEAD ACCEPTED' : '❌ LEAD REJECTED';
        $leadUuid = $crmResponse['lead_uuid'] ?? '—';
        $crmStatus = $crmResponse['status']
            ?? $crmResponse['message']
            ?? ($crmResponse['data']['errorMessage'] ?? '—');

        $ipCountry = self::detectIpCountry();
        $subField = 'aff_sub3';
        $subid = trim((string) ($crmData[$subField] ?? ''));
        $spamReason = trim((string) ($crmData['aff_sub13'] ?? ''));
        $isSpam = (($crmData['aff_sub12'] ?? '') === 'SPAM');

        if ($crmSuccess && $isSpam && $spamReason !== '') {
            $status = '⚠️ LEAD ACCEPTED (' . self::escapeTelegramHtml($spamReason) . ')';
        } elseif ($crmSuccess && $isSpam) {
            $status = '⚠️ LEAD ACCEPTED (SPAM)';
        }

        $lines = [
            '<b>' . $status . '</b>',
            '━━━━━━━━━━━━━━━━━━',
            '',
            '<b>Name:</b> ' . self::escapeTelegramHtml(trim(($crmData['first_name'] ?? '') . ' ' . ($crmData['last_name'] ?? ''))),
            '<b>Email:</b> ' . self::escapeTelegramHtml((string) ($crmData['email'] ?? '')),
            '<b>Phone:</b> ' . self::escapeTelegramHtml((string) ($crmData['phone'] ?? '')),
            '',
            '<b>Affiliate:</b> ' . self::escapeTelegramHtml((string) ($crmData['affiliate_id'] ?? '')),
            '<b>Funnel:</b> ' . self::escapeTelegramHtml((string) ($crmData['offer_id'] ?? '')),
            '<b>Offer:</b> ' . self::escapeTelegramHtml(SITE_NAME),
            '<b>Domain:</b> <a href="' . self::escapeTelegramHtml(rtrim(SITE_URL, '/')) . '">' . self::escapeTelegramHtml(site_domain()) . '</a>',
            '<b>Country (offer):</b> ' . self::escapeTelegramHtml(self::telegramOfferLabel()),
            '<b>Country (IP):</b> ' . self::escapeTelegramHtml($ipCountry !== '' ? $ipCountry : '—'),
            '<b>Spam reason:</b> ' . ($spamReason !== '' ? self::escapeTelegramHtml($spamReason) : '—'),
            '<b>IP:</b> ' . self::escapeTelegramHtml(self::clientIp()),
            '<b>Language:</b> ' . self::escapeTelegramHtml((string) ($crmData['lead_language'] ?? '')),
            '<b>SubID:</b> ' . self::escapeTelegramHtml($subid !== '' ? $subid : '—'),
            '',
            '<b>UUID:</b> ' . self::escapeTelegramHtml((string) $leadUuid),
            '<b>Status:</b> ' . self::escapeTelegramHtml((string) $crmStatus),
            '<b>HTTP:</b> ' . (int) ($crmResult['http_code'] ?? 0),
        ];

        $lines[] = '';
        $lines[] = '<b>CRM response log</b>';
        $lines[] = self::formatCrmResponseLog(
            (int) ($crmResult['http_code'] ?? 0),
            $crmResponse,
            (string) ($crmResult['curl_error'] ?? '')
        );

        return implode("\n", $lines);
    }

    /** Spam that must not hit CRM / Telegram (fake success to the visitor). */
    private static function shouldSilentDrop(?string $spamReason): bool
    {
        return in_array($spamReason, [
            'ORIGIN_MISMATCH',
            'BOT_UA',
            'GEO_MISMATCH',
            'NO_SUBID',
            'KT_CLICK_NOT_FOUND',
            'KT_CAMPAIGN_MISMATCH',
            'FORM_TOKEN_INVALID',
            'FORM_TOKEN_MISSING',
            'RATE_LIMIT',
            'FORM_TOKEN_USED',
            'FORM_TOKEN_TOO_FAST',
            'FORM_TOKEN_DROP',
            'NO_CLOUDFLARE',
            'CHATGPT_REFERRAL',
            'GEO_SKIP',
            'LANG_GEO_MISMATCH',
        ], true);
    }

    public static function process(array $lead, ?string $preflightSpamReason = null): array
    {
        $firstName = trim((string) ($lead['first_name'] ?? $lead['fname'] ?? ''));
        $lastName = trim((string) ($lead['last_name'] ?? $lead['lname'] ?? ''));
        $email = trim((string) ($lead['email'] ?? ''));
        $phone = self::normalizePhone((string) ($lead['phone'] ?? $lead['fullphone'] ?? ''));

        require_once __DIR__ . '/LeadFieldGuard.php';
        $fieldError = LeadFieldGuard::error($firstName, $lastName, $email, $phone);
        if ($fieldError !== null) {
            return [
                'ok' => false,
                'error' => $fieldError,
                'http_status' => 422,
            ];
        }

        $spamReason = self::resolveSpamReason($lead, $preflightSpamReason);

        // Definitive fake-subid spam: look like success, skip CRM/TG.
        if (self::shouldSilentDrop($spamReason)) {
            return [
                'ok' => true,
                'http_status' => 200,
                'crm_success' => true,
                'lead_uuid' => null,
                'telegram_sent' => false,
                'thank_you_url' => FORM_THANK_YOU,
                'lead_language' => strtolower(trim((string) ($lead['language'] ?? SITE_LANG))) ?: SITE_LANG,
                'fullphone' => $phone,
                'spam_silent' => $spamReason,
            ];
        }

        if (function_exists('offer_click_id_remember')) {
            offer_click_id_remember();
        }

        $crmData = self::buildCrmPayload($lead);
        if ($spamReason !== null) {
            $crmData = self::applySpamMarkers($crmData, $spamReason);
        }

        $crmResult = self::sendToCrm($crmData);
        $crmSuccess = (bool) ($crmResult['success'] ?? false);
        $crmResponse = is_array($crmResult['response'] ?? null) ? $crmResult['response'] : [];

        $telegramSent = self::sendTelegram(self::buildTelegramMessage($crmSuccess, $crmData, $crmResult));

        return [
            'ok' => true,
            'http_status' => 200,
            'crm_success' => $crmSuccess,
            'lead_uuid' => $crmResponse['lead_uuid'] ?? null,
            'telegram_sent' => $telegramSent,
            'thank_you_url' => FORM_THANK_YOU,
            'lead_language' => $crmData['lead_language'] ?? SITE_LANG,
            'fullphone' => $phone,
        ];
    }
}
