<?php

namespace App\Services;

use App\Models\UserSetting;
use App\Support\MarketOptions;
use App\Support\SecretValue;

class OfferConfigBuilder
{
    public function __construct(
        private readonly MirrorProbeService $mirrorProbe,
        private readonly OfferGeoClickService $geoClicks,
        private readonly OfferClickIdService $clickIds,
    ) {}

    /**
     * @param  array<string, mixed>  $offer
     */
    public function build(array $offer, UserSetting $settings): string
    {
        $brand = $this->quote($offer['brand']);
        $domain = $this->quote($offer['domain']);
        $siteUrl = $this->quote('https://'.$offer['domain']);
        $lang = $this->quote($offer['lang']);
        $minDepositRaw = trim((string) ($offer['min_deposit'] ?? ''));
        $minDeposit = $this->quote($minDepositRaw !== '' ? $minDepositRaw : '250');
        $currencyRaw = strtoupper(trim((string) ($offer['currency'] ?? '')));
        $currency = $this->quote($currencyRaw !== '' ? $currencyRaw : 'EUR');
        $crmKey = $this->quote(SecretValue::normalize($settings->crm_api_key ?? ''));
        $affiliate = $this->quote($settings->affiliate_tag ?? 'BRO');
        $funnel = $this->quote($offer['brand']);
        $country = $this->quote(MarketOptions::normalizeGeo((string) ($offer['geo'] ?? '')));
        $phones = MarketOptions::normalizePhoneFields(
            (string) ($offer['phone'] ?? ''),
            $offer['phone_countries'] ?? '',
            (string) ($offer['geo'] ?? ''),
        );
        $phone = $this->quote($phones['phone']);
        $allowedPhones = $this->quote(implode(',', $phones['phone_countries']));
        $tgToken = $this->quote(SecretValue::normalize($settings->tg_bot_token ?? ''));
        $tgChat = $this->quote($settings->tg_chat_id ?? '');
        $tgGroupChat = $this->quote($settings->tg_group_chat_id ?? '');
        $formTokenSecret = $this->quote($this->resolveFormTokenSecret($offer));
        $vitalsEnabled = ! empty($offer['vitals_enabled']) ? 'true' : 'false';
        $vitalsCdn = ! empty($offer['vitals_enabled'])
            ? $this->quote($this->mirrorProbe->cdnBase())
            : $this->quote('');
        $vitalsToken = ! empty($offer['vitals_enabled'])
            ? $this->quote($this->mirrorProbe->ensureProbeToken($settings))
            : $this->quote('');
        $vitalsEndpoint = ! empty($offer['vitals_enabled'])
            ? $this->quote($this->mirrorProbe->collectUrl($settings))
            : $this->quote('');
        $fromSearchTeam = ! empty($offer['from_search_team']);
        $crmSub7 = $this->quote($fromSearchTeam ? 'SEO' : '');
        $crmSub8 = $this->quote($fromSearchTeam ? 'SearchAM' : '');
        $geoClickUrl = $this->quote($this->geoClicks->clickUrl($settings));
        $clickIdUrl = $this->quote($this->clickIds->registerUrl($settings));
        $offerDomainRaw = $this->quote(strtolower(trim((string) $offer['domain'])));
        $vesperaExtras = $this->dictionaryMlDefines($offer, $siteUrl);

        return <<<PHP
<?php
\$__offerra_cloak = dirname(__DIR__) . '/cloak.php';
if (!is_file(\$__offerra_cloak)) {
    \$__offerra_cloak = dirname(__DIR__, 2) . '/cloak.php';
}
if (is_file(\$__offerra_cloak)) {
    require_once \$__offerra_cloak;
}
unset(\$__offerra_cloak);
// ─── Сайт / бренд / SEO ───────────────────────────────────────────────────
define('SITE_NAME', {$brand});
define('SITE_URL', {$siteUrl});
define('SITE_LANG', {$lang});
define('MIN_DEPOSIT', {$minDeposit});
define('CURRENCY', {$currency});

// ─── CRM (YourLeads API v2) ─────────────────────────────────────────────────
define('CRM_API_URL', 'https://yourleads.org/api/affiliates/v2/leads');
define('CRM_API_KEY', {$crmKey});
define('CRM_AFFILIATE_ID', {$affiliate});
define('CRM_FUNNEL', {$funnel});
define('CRM_COUNTRY', {$country});

define('CRM_AFF_SUB', '');
define('CRM_AFF_SUB2', '');
define('CRM_AFF_SUB3', ''); // click id, filled on submit
define('CRM_AFF_SUB4', '');
define('CRM_AFF_SUB5', '');
define('CRM_AFF_SUB6', '');
define('CRM_AFF_SUB7', {$crmSub7});
define('CRM_AFF_SUB8', {$crmSub8});
define('CRM_AFF_SUB9', '');
define('CRM_AFF_SUB10', '');
define('CRM_AFF_SUB11', '');
define('CRM_AFF_SUB12', '');
define('CRM_AFF_SUB13', '');

// ─── Telegram ───────────────────────────────────────────────────────────────
define('TG_BOT_TOKEN', {$tgToken});
define('TG_CHAT_ID', {$tgChat});
define('TG_GROUP_CHAT_ID', {$tgGroupChat});

// ─── Форма ──────────────────────────────────────────────────────────────────
define('FORM_PHONE_COUNTRY', {$phone});
define('FORM_ALLOWED_COUNTRIES', {$allowedPhones});
define('FORM_THANK_YOU', 'Thanks.php');
define('FORM_LEAD_COOKIE_DAYS', 30);
define('FORM_TOKEN_SECRET', {$formTokenSecret});
define('FORM_TOKEN_TTL', 600);
define('FORM_TOKEN_MIN_AGE', 3);
define('FORM_TOKEN_ISSUE_LIMIT', 8);
define('FORM_TOKEN_SUBMIT_LIMIT', 3);
define('FORM_TOKEN_RATE_WINDOW', 600);
define('FORM_TOKEN_DEBUG', false);
{$vesperaExtras}
if (is_file(__DIR__ . '/routes.php')) {
    require_once __DIR__ . '/routes.php';
}

// ─── Edge CDN assets / RUM ──────────────────────────────────────────────────
define('VITALS_ENABLED', {$vitalsEnabled});
define('VITALS_CDN', {$vitalsCdn});
define('VITALS_TOKEN', {$vitalsToken});
define('VITALS_ENDPOINT', {$vitalsEndpoint});

// ─── Offerra GEO click tracking ─────────────────────────────────────────────
define('OFFERRA_GEO_CLICK_URL', {$geoClickUrl});
define('OFFERRA_GEO_CLICK_DOMAIN', {$offerDomainRaw});
define('OFFERRA_CLICK_ID_URL', {$clickIdUrl});

require_once __DIR__ . '/helpers.php';
if (is_file(__DIR__ . '/i18n-helpers.php')) {
    require_once __DIR__ . '/i18n-helpers.php';
}
if (is_file(__DIR__ . '/keitaro.php')) {
    require_once __DIR__ . '/keitaro.php';
}
offer_send_personalization_headers();

(static function (): void {
    if (! defined('OFFERRA_GEO_CLICK_URL') || OFFERRA_GEO_CLICK_URL === '') {
        return;
    }
    if (! function_exists('form_ip_country')) {
        return;
    }

    \$script = (string) (\$_SERVER['SCRIPT_NAME'] ?? '');
    if (\$script !== '' && preg_match('#/(integration|robots|sitemap|send\\.php|visitor-geo\\.php|form-token\\.php)#i', \$script)) {
        return;
    }

    \$day = gmdate('Ymd', time() + 3 * 3600);
    if (isset(\$_COOKIE['ogc']) && (string) \$_COOKIE['ogc'] === \$day) {
        return;
    }

    \$ipCountry = strtolower(trim((string) form_ip_country()));
    if (\$ipCountry === '' || \$ipCountry === 'xx') {
        return;
    }
    if (\$ipCountry === 'uk') {
        \$ipCountry = 'gb';
    }

    \$allowed = function_exists('form_allowed_countries') ? form_allowed_countries() : [];
    if (\$allowed === []) {
        \$fallback = strtolower(trim((string) (defined('CRM_COUNTRY') ? CRM_COUNTRY : '')));
        if (\$fallback === 'uk') {
            \$fallback = 'gb';
        }
        \$allowed = \$fallback !== '' ? [\$fallback] : [];
    }
    if (\$allowed === [] || ! in_array(\$ipCountry, \$allowed, true)) {
        return;
    }

    \$ua = (string) (\$_SERVER['HTTP_USER_AGENT'] ?? '');
    if (\$ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python-requests|httpclient|scrapy|headless|phantom|selenium|pingdom|uptimerobot|statuscake|monitor/i', \$ua)) {
        return;
    }

    \$ip = (string) (\$_SERVER['HTTP_CF_CONNECTING_IP'] ?? \$_SERVER['REMOTE_ADDR'] ?? '');
    \$visitor = hash('sha256', \$ip . '|' . strtolower(substr(\$ua, 0, 120)));

    @setcookie('ogc', \$day, [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    \$domain = defined('OFFERRA_GEO_CLICK_DOMAIN') ? (string) OFFERRA_GEO_CLICK_DOMAIN : '';
    if (\$domain === '' && defined('SITE_URL')) {
        \$host = parse_url((string) SITE_URL, PHP_URL_HOST);
        \$domain = is_string(\$host) ? strtolower(\$host) : '';
    }
    if (\$domain === '') {
        return;
    }

    \$url = OFFERRA_GEO_CLICK_URL
        . '?d=' . rawurlencode(\$domain)
        . '&c=' . rawurlencode(\$ipCountry)
        . '&v=' . rawurlencode(\$visitor);

    \$ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 1.2,
            'ignore_errors' => true,
            'header' => "User-Agent: OfferraGeoClick/1.0\\r\\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    @file_get_contents(\$url, false, \$ctx);
})();

PHP;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function resolveFormTokenSecret(array $offer): string
    {
        $existing = trim((string) ($offer['form_token_secret'] ?? ''));

        if ($existing !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $existing)) {
            return strtolower($existing);
        }

        if ($existing !== '' && strlen($existing) >= 32) {
            return $existing;
        }

        return bin2hex(random_bytes(32));
    }

    private function quote(string $value): string
    {
        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
    }

    /**
     * Dictionary-i18n extras for Vespera/Fortis. Other templates get an empty string.
     *
     * @param  array<string, mixed>  $offer
     */
    private function dictionaryMlDefines(array $offer, string $siteUrl): string
    {
        $template = (string) ($offer['template'] ?? '');
        if (! \App\Services\TemplateCatalog::isDictionaryMl($template)) {
            return '';
        }

        $supported = $this->dictionarySupportedLangs($template);
        $map = $this->vesperaGeoLangMap($supported);
        $email = $this->quote('support@'.strtolower(trim((string) $offer['domain'])));
        $tpl = $this->quote($template);

        return <<<PHP

define('SITE_DOMAIN', {$siteUrl});
define('SITE_EMAIL', {$email});
define('DEFAULT_LANG', 'en');
define('SUPPORTED_LANGS', {$this->phpArray($supported)});
define('GEO_LANG_MAP', {$this->phpArray($map)});
define('OFFER_TEMPLATE', {$tpl});

PHP;
    }

    /**
     * @return list<string>
     */
    private function dictionarySupportedLangs(string $template): array
    {
        $dir = base_path('templates'.DIRECTORY_SEPARATOR.$template.DIRECTORY_SEPARATOR.'lang');
        $codes = [];

        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $file) {
                if (! preg_match('/^([a-z]{2})\.php$/', $file, $matches)) {
                    continue;
                }
                $codes[] = $matches[1];
            }
        }

        if ($codes === []) {
            $codes = ['en'];
        }

        sort($codes);

        return $codes;
    }

    /**
     * @param  list<string>  $supported
     * @return array<string, string>
     */
    private function vesperaGeoLangMap(array $supported): array
    {
        $map = [];

        foreach (config('offerra.geo_presets', []) as $preset) {
            if (! is_array($preset)) {
                continue;
            }

            $code = strtoupper((string) ($preset['code'] ?? ''));
            $lang = strtolower((string) ($preset['lang'] ?? ''));

            if (! preg_match('/^[A-Z]{2}$/', $code) || ! in_array($lang, $supported, true)) {
                continue;
            }

            $map[$code] = $lang;
        }

        return $map;
    }

    private function phpArray(array $value): string
    {
        return var_export($value, true);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    public function phoneCountriesCsv(array $offer): string
    {
        $normalized = MarketOptions::normalizePhoneFields(
            (string) ($offer['phone'] ?? ''),
            $offer['phone_countries'] ?? '',
            (string) ($offer['geo'] ?? ''),
        );

        return implode(',', $normalized['phone_countries']);
    }
}
