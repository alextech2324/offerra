<?php
/**
 * Внутрішні хелпери — не редагувати при переносі оффера.
 */

define('PLATFORM_IMAGE_TEMPLATE', 'static/images/img.jpg');

function site_slug(): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', SITE_NAME));
}

function site_domain(): string
{
    return parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost';
}

function site_locale(): string
{
    $map = [
        'en' => 'en-US', 'pl' => 'pl-PL', 'de' => 'de-DE', 'fr' => 'fr-FR',
        'it' => 'it-IT', 'es' => 'es-ES', 'pt' => 'pt-PT', 'hr' => 'hr-HR', 'sr' => 'sr-RS', 'nl' => 'nl-NL', 'no' => 'nb-NO', 'da' => 'da-DK',
        'uk' => 'uk-UA', 'ru' => 'ru-RU', 'cs' => 'cs-CZ', 'sk' => 'sk-SK', 'hu' => 'hu-HU',
        'el' => 'el-GR', 'sv' => 'sv-SE', 'fi' => 'fi-FI', 'ro' => 'ro-RO', 'tr' => 'tr-TR',
        'ms' => 'ms-MY', 'id' => 'id-ID', 'ka' => 'ka-GE',
        'ja' => 'ja-JP',
        'lv' => 'lv-LV',
        'lt' => 'lt-LT',
        'sl' => 'sl-SI',
    ];
    $lang = strtolower(SITE_LANG);

    return $map[$lang] ?? ($lang . '-' . strtoupper($lang));
}

function geo_country_code(): string
{
    $code = strtolower(trim((string) FORM_PHONE_COUNTRY));

    if ($code === '' && defined('CRM_COUNTRY')) {
        $code = strtolower(trim((string) CRM_COUNTRY));
    }

    if ($code === 'uk') {
        $code = 'gb';
    }

    return preg_match('/^[a-z]{2}$/', $code) ? $code : 'gb';
}

function geo_country_name(): string
{
    $code = geo_country_code();
    $iso = strtoupper($code);
    $lang = defined('SITE_LANG') ? strtolower((string) SITE_LANG) : 'en';

    if (class_exists(\Locale::class)) {
        $name = \Locale::getDisplayRegion('_'.$iso, $lang);
        if (is_string($name) && $name !== '' && strcasecmp($name, $iso) !== 0) {
            return $name;
        }
    }

    $names = [
        'gb' => 'United Kingdom', 'us' => 'United States', 'de' => 'Germany',
        'fr' => 'France', 'es' => 'Spain', 'it' => 'Italy', 'pl' => 'Poland',
        'pt' => 'Portugal', 'nl' => 'Netherlands', 'cz' => 'Czech Republic',
        'sk' => 'Slovakia', 'hu' => 'Hungary', 'ro' => 'Romania', 'hr' => 'Croatia',
        'rs' => 'Serbia', 'no' => 'Norway', 'se' => 'Sweden', 'dk' => 'Denmark',
        'fi' => 'Finland', 'gr' => 'Greece', 'tr' => 'Turkey', 'at' => 'Austria',
        'ch' => 'Switzerland', 'be' => 'Belgium', 'ie' => 'Ireland', 'au' => 'Australia',
        'ca' => 'Canada', 'bg' => 'Bulgaria', 'lt' => 'Lithuania', 'lv' => 'Latvia',
        'si' => 'Slovenia', 'ge' => 'Georgia', 'my' => 'Malaysia', 'id' => 'Indonesia',
        'jp' => 'Japan', 'br' => 'Brazil', 'mx' => 'Mexico',
    ];

    return $names[$code] ?? $iso;
}

function currency_symbol(): string
{
    $code = defined('CURRENCY') ? strtoupper((string) CURRENCY) : 'EUR';

    return match ($code) {
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'CHF' => 'CHF',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'HUF' => 'Ft',
        'RON' => 'lei',
        'TRY' => '₺',
        'GEL' => '₾',
        'BRL' => 'R$',
        'MXN' => '$',
        default => $code,
    };
}

function min_deposit_label(): string
{
    $amount = defined('MIN_DEPOSIT') ? trim((string) MIN_DEPOSIT) : '250';
    $symbol = currency_symbol();
    $code = defined('CURRENCY') ? strtoupper((string) CURRENCY) : 'EUR';

    if (in_array($code, ['USD', 'GBP'], true)) {
        return $symbol.$amount;
    }

    if ($symbol === $code) {
        return $amount.' '.$code;
    }

    return $amount.' '.$symbol;
}

function crm_funnel(): string
{
    $funnel = trim((string) CRM_FUNNEL);

    return $funnel !== '' ? $funnel : site_slug();
}

function crm_aff_sub_value(int $index): string
{
    $const = 'CRM_AFF_SUB' . ($index === 1 ? '' : (string) $index);
    if (!defined($const)) {
        return '';
    }
    $value = trim((string) constant($const));
    if ($index === 2 && $value === '') {
        return crm_funnel();
    }

    return $value;
}

function crm_aff_subs_resolved(array $lead = []): array
{
    $subs = [];

    for ($i = 1; $i <= 12; $i++) {
        $key = 'aff_sub' . ($i === 1 ? '' : (string) $i);
        $value = !empty($lead[$key]) ? trim((string) $lead[$key]) : crm_aff_sub_value($i);

        if ($value !== '') {
            $subs[$key] = $value;
        }
    }

    return $subs;
}

function form_allowed_countries(): array
{
    $raw = array_filter(array_map('trim', explode(',', strtolower(FORM_ALLOWED_COUNTRIES))));
    $iso2 = array_values(array_filter(
        $raw,
        static fn (string $code): bool => strlen($code) === 2 && ctype_alpha($code),
    ));

    return array_values(array_unique($iso2));
}

function form_ip_country(): string
{
    $cf = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));

    if ($cf !== '' && $cf !== 'XX' && preg_match('/^[A-Z]{2}$/', $cf)) {
        return strtolower($cf);
    }

    return '';
}

function form_phone_code_from_ip(string $ipCountry): string
{
    $code = strtolower(trim($ipCountry));

    return $code === 'uk' ? 'gb' : $code;
}

function form_visitor_phone_country(): string
{
    $allowed = form_allowed_countries();
    $default = strtolower(trim((string) FORM_PHONE_COUNTRY));
    $autoIp = ($default === 'ip' || $default === 'auto');
    $ipCode = form_phone_code_from_ip(form_ip_country());

    // Режим «по IP»: завжди беремо CF-IPCountry, навіть якщо коду немає в whitelist.
    if ($autoIp) {
        if ($ipCode !== '') {
            return $ipCode;
        }

        if ($allowed !== []) {
            return in_array('gb', $allowed, true) ? 'gb' : $allowed[0];
        }

        return 'gb';
    }

    if ($allowed === []) {
        return $default !== '' ? $default : 'gb';
    }

    if ($ipCode !== '' && in_array($ipCode, $allowed, true)) {
        return $ipCode;
    }

    if (count($allowed) === 1) {
        return $allowed[0];
    }

    if (in_array('gb', $allowed, true)) {
        return 'gb';
    }

    if ($default !== '' && in_array($default, $allowed, true)) {
        return $default;
    }

    return $allowed[0];
}

function offer_send_personalization_headers(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $skip = ['send.php', 'form-token.php', 'visitor-geo.php', 'sitemap.php', 'robots.php'];

    if (in_array($script, $skip, true)) {
        return;
    }

    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Vary: CF-IPCountry');
}

function platform_image_path(): string
{
    static $resolved;

    if ($resolved !== null) {
        return $resolved;
    }

    $root = dirname(__DIR__);
    $branded = 'static/img/platform/' . site_slug() . '-trading-platform-mobile.png';
    $template = PLATFORM_IMAGE_TEMPLATE;

    if (!is_file($root . '/' . $branded) && is_file($root . '/' . $template)) {
        @copy($root . '/' . $template, $root . '/' . $branded);
    }

    $resolved = is_file($root . '/' . $branded) ? $branded : $template;

    return $resolved;
}

function og_image_path(): string
{
    $root = dirname(__DIR__);

    return is_file($root.'/static/images/img.jpg')
        ? 'static/images/img.jpg'
        : platform_image_path();
}

function page_title(string $suffix): string
{
    return SITE_NAME . ' | ' . $suffix;
}

function page_title_lead(string $prefix): string
{
    return $prefix . ' | ' . SITE_NAME;
}

function brand_with(string $text): string
{
    return str_replace('{brand}', SITE_NAME, $text);
}

function platform_image_alt(): string
{
    return SITE_NAME . ' trading platform on mobile — live BTC/USDT chart, order book, and buy/sell interface';
}

function platform_image_caption(): string
{
    return SITE_NAME . ' — mobile trading with real-time cryptocurrency charts';
}

function offer_is_preview(): bool
{
    return (defined('OFFERRA_PREVIEW') && OFFERRA_PREVIEW)
        || getenv('OFFERRA_PREVIEW') === '1'
        || (isset($_ENV['OFFERRA_PREVIEW']) && $_ENV['OFFERRA_PREVIEW'] === '1');
}

function offer_preview_base(): ?string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

    if (preg_match('#^(/preview/[^/]+)(?:/|$)#', $path, $matches)) {
        return rtrim($matches[1], '/').'/';
    }

    return null;
}

function page_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if ($path === 'index.php') {
        $path = '';
    }

    if (offer_is_preview() && ($previewBase = offer_preview_base())) {
        $base = rtrim($previewBase, '/');

        if ($path === '') {
            return $base.'/';
        }

        return $base.'/'.$path;
    }

    return canonical_url($path === '' ? '/' : $path);
}

/**
 * Apex https canonical — strips www / index.php and normalizes trailing slash for home.
 */
function canonical_url(string $urlOrPath = '/'): string
{
    $raw = trim($urlOrPath);
    if ($raw === '') {
        $raw = '/';
    }

    if (! preg_match('#^https?://#i', $raw)) {
        $base = rtrim(SITE_URL, '/');
        $path = ltrim($raw, '/');
        if ($path === 'index.php') {
            $path = '';
        }
        $raw = $path === '' ? $base.'/' : $base.'/'.$path;
    }

    $parts = parse_url($raw);
    if (! is_array($parts) || empty($parts['host'])) {
        return rtrim(SITE_URL, '/').'/';
    }

    $host = strtolower((string) $parts['host']);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    $path = $parts['path'] ?? '/';
    if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
        $path = preg_replace('#/index\.php$#', '/', $path) ?? '/';
    }
    if ($path === '' || $path === '/') {
        $path = '/';
    } else {
        $path = rtrim($path, '/');
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

    return 'https://'.$host.$path.$query;
}

function asset(string $path): string
{
    if (offer_is_preview() && ($previewBase = offer_preview_base())) {
        return rtrim($previewBase, '/').'/'.ltrim($path, '/');
    }

    return './'.ltrim($path, '/');
}

function asset_version(string $path): string
{
    $url = asset($path);
    $local = dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));

    if (is_file($local)) {
        return $url.'?v='.filemtime($local);
    }

    return $url;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** @return array{cdn: string, token: string}|null */
function offer_vitals_parts(): ?array
{
    if (! defined('VITALS_ENABLED') || ! VITALS_ENABLED) {
        return null;
    }

    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    $skip = [
        'sitemap.php',
        'robots.php',
        'send.php',
        'form-token.php',
        'visitor-geo.php',
    ];

    if (in_array($script, $skip, true)) {
        return null;
    }

    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') !== 0) {
            continue;
        }

        $type = strtolower($header);
        if (
            ! str_contains($type, 'text/html')
            && ! str_contains($type, 'application/xhtml')
        ) {
            return null;
        }
    }

    $cdn = defined('VITALS_CDN') ? rtrim(trim((string) VITALS_CDN), '/') : '';
    $token = defined('VITALS_TOKEN') ? trim((string) VITALS_TOKEN) : '';

    if ($cdn === '' || $token === '' || ! preg_match('/^[a-f0-9]{16,64}$/', $token)) {
        return null;
    }

    return ['cdn' => $cdn, 'token' => $token];
}

/** CSS theme — place in <head> among other stylesheets. */
function offer_vitals_head(): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $parts = offer_vitals_parts();
    if (! $parts) {
        return;
    }
    $printed = true;
    echo '  <link rel="stylesheet" href="'.e($parts['cdn'].'/c/'.$parts['token'].'/theme.css').'">'."\n";
}

/** 1×1 beacon — place in footer markup (not next to scripts). */
function offer_vitals_pixel(): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $parts = offer_vitals_parts();
    if (! $parts) {
        return;
    }
    $printed = true;
    echo '<img src="'.e($parts['cdn'].'/i/'.$parts['token'].'/spacer.gif').'" width="1" height="1" alt="" style="position:absolute;width:1px!important;height:1px!important;border:0;overflow:hidden;clip:rect(0,0,0,0);pointer-events:none" aria-hidden="true">'."\n";
}

/** Minified runtime — place after main.js. */
function offer_vitals_script(): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $parts = offer_vitals_parts();
    if ($parts) {
        $printed = true;
        echo '<script src="'.e($parts['cdn'].'/js/'.$parts['token'].'/app.min.js').'" defer></script>'."\n";

        return;
    }

    if (! defined('VITALS_ENABLED') || ! VITALS_ENABLED || ! defined('VITALS_ENDPOINT')) {
        return;
    }

    $endpoint = trim((string) VITALS_ENDPOINT);
    if ($endpoint === '') {
        return;
    }

    $printed = true;
    echo '<script src="'.asset_version('integration/cwv-collector.js').'" defer data-ep="'.e($endpoint).'"></script>'."\n";
}

/** @deprecated Use offer_vitals_script() */
function offer_vitals_boot(): void
{
    offer_vitals_script();
}

/** @deprecated Token is issued via integration/form-token.php (JS only). */
function form_token_issue(): string
{
    return '';
}

define('SUPPORT_EMAIL', 'support@' . site_domain());

if (! function_exists('offer_click_id_normalize')) {
    function offer_click_id_normalize(string $id): string
    {
        $id = trim($id);
        if ($id === '' || strtolower($id) === 'no_subid') {
            return '';
        }
        if (! preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $id)) {
            return '';
        }

        return $id;
    }
}

if (! function_exists('offer_click_id')) {
    function offer_click_id(): string
    {
        if (isset($GLOBALS['_offer_cid']) && is_string($GLOBALS['_offer_cid']) && $GLOBALS['_offer_cid'] !== '') {
            return $GLOBALS['_offer_cid'];
        }

        $cookie = (function_exists('site_slug') ? site_slug() : 'offerra') . '_cid';
        $fromGet = offer_click_id_normalize((string) ($_GET['subid'] ?? $_GET['_subid'] ?? $_GET['cid'] ?? ''));
        $fromCookie = offer_click_id_normalize((string) ($_COOKIE[$cookie] ?? ''));
        $fromPost = offer_click_id_normalize((string) ($_POST['subid'] ?? ''));

        $id = $fromGet !== '' ? $fromGet : ($fromCookie !== '' ? $fromCookie : ($fromPost !== '' ? $fromPost : bin2hex(random_bytes(12))));
        $GLOBALS['_offer_cid'] = $id;

        if (PHP_SAPI !== 'cli' && ! headers_sent()) {
            @setcookie($cookie, $id, [
                'expires' => time() + 90 * 86400,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[$cookie] = $id;
        }

        return $id;
    }
}

if (! function_exists('offer_click_id_remember')) {
    function offer_click_id_remember(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (! defined('OFFERRA_CLICK_ID_URL') || OFFERRA_CLICK_ID_URL === '') {
            return;
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && preg_match('#/(robots|sitemap|visitor-geo\.php|form-token\.php)#i', $script)) {
            return;
        }

        $id = offer_click_id();
        if ($id === '') {
            return;
        }

        $isSend = $script !== '' && (bool) preg_match('#send\.php#i', $script);
        $cookie = (function_exists('site_slug') ? site_slug() : 'offerra') . '_cidr';
        if (! $isSend && isset($_COOKIE[$cookie]) && (string) $_COOKIE[$cookie] === $id) {
            return;
        }

        $domain = '';
        if (defined('OFFERRA_GEO_CLICK_DOMAIN')) {
            $domain = strtolower(trim((string) OFFERRA_GEO_CLICK_DOMAIN));
        }
        if ($domain === '' && function_exists('site_domain')) {
            $domain = strtolower(trim((string) site_domain()));
        }

        $url = OFFERRA_CLICK_ID_URL
            . '?cid=' . rawurlencode($id)
            . ($domain !== '' && $domain !== 'localhost' ? '&d=' . rawurlencode($domain) : '');

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1.2,
                'ignore_errors' => true,
                'header' => "User-Agent: OfferraClickId/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        @file_get_contents($url, false, $ctx);

        if (! headers_sent()) {
            @setcookie($cookie, $id, [
                'expires' => time() + 90 * 86400,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[$cookie] = $id;
        }
    }
}

if (PHP_SAPI !== 'cli') {
    offer_click_id();
}

