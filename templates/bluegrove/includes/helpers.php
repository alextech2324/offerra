<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }
    if (preg_match('#^(/preview/[^/]+)(?:/|$)#', $path, $matches)) {
        return $matches[1];
    }

    return null;
}

function public_url(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $preview = offer_preview_base();
    $url = $preview !== null ? rtrim($preview, '/') . $path : $path;

    if (preg_match('/\.(css|js)$/i', $path) === 1) {
        $local = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($local)) {
            $url .= '?v=' . filemtime($local);
        }
    }

    return $url;
}

function currency_symbol(?string $code = null): string
{
    $code = strtoupper($code ?? (defined('CURRENCY') ? (string) CURRENCY : 'EUR'));

    return match ($code) {
        'USD', 'AUD', 'CAD', 'NZD', 'SGD', 'HKD' => '$',
        'NAD' => 'N$',
        'PEN' => 'S/',
        'GBP' => '£',
        'JPY', 'CNY' => '¥',
        'KRW' => '₩',
        'CHF' => 'CHF',
        'EUR' => '€',
        default => $code,
    };
}

function geo_country_name(?string $iso = null): string
{
    $iso = strtoupper($iso ?? (function_exists('geo_detect_country') ? geo_detect_country() : 'US'));
    $names = [
        'US' => 'the United States',
        'GB' => 'the United Kingdom',
        'DE' => 'Germany',
        'AT' => 'Austria',
        'CH' => 'Switzerland',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'the Netherlands',
        'BE' => 'Belgium',
        'PT' => 'Portugal',
        'PL' => 'Poland',
        'CZ' => 'Czechia',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'IE' => 'Ireland',
        'AU' => 'Australia',
        'CA' => 'Canada',
        'NZ' => 'New Zealand',
        'JP' => 'Japan',
        'SG' => 'Singapore',
    ];

    return $names[$iso] ?? $iso;
}

function money_min(): string
{
    $amount = defined('MIN_DEPOSIT') && trim((string) MIN_DEPOSIT) !== '' ? (string) MIN_DEPOSIT : '250';

    return currency_symbol().$amount;
}

function interpolate_placeholders(string $value): string
{
    $brand = defined('SITE_NAME') ? (string) SITE_NAME : 'Bluegrove';
    $email = defined('SITE_EMAIL') && SITE_EMAIL !== ''
        ? (string) SITE_EMAIL
        : 'support@example.com';
    $deposit = defined('MIN_DEPOSIT') && trim((string) MIN_DEPOSIT) !== ''
        ? (string) MIN_DEPOSIT
        : '250';
    $currency = defined('CURRENCY') ? (string) CURRENCY : 'EUR';
    $domain = function_exists('site_domain') ? site_domain() : 'example.com';

    return str_replace(
        ['{brand}', '{email}', '{min_deposit}', '{currency}', '{currency_symbol}', '{domain}'],
        [$brand, $email, $deposit, $currency, currency_symbol($currency), $domain],
        $value
    );
}

function interpolate_lang(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = interpolate_lang($value);
        } elseif (is_string($value)) {
            $data[$key] = interpolate_placeholders($value);
        }
    }

    return $data;
}

function t(array $lang, string $key, string $fallback = ''): string
{
    $parts = explode('.', $key);
    $cur = $lang;
    foreach ($parts as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return interpolate_placeholders($fallback !== '' ? $fallback : $key);
        }
        $cur = $cur[$part];
    }
    $value = is_string($cur) ? $cur : ($fallback !== '' ? $fallback : $key);

    return interpolate_placeholders($value);
}

function base_url(): string
{
    if (SITE_DOMAIN !== '') {
        return rtrim(SITE_DOMAIN, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function lang_path(string $lang, string $page = 'home'): string
{
    $slug = PAGES[$page] ?? $page;
    $isDefault = ($lang === DEFAULT_LANG);
    if ($slug === '' || $slug === 'home') {
        $path = $isDefault ? '/' : '/' . $lang . '/';
        $preview = offer_preview_base();

        return $preview !== null ? rtrim($preview, '/') . $path : $path;
    }
    $slug = trim((string) $slug, '/');
    $path = $isDefault ? '/' . $slug . '/' : '/' . $lang . '/' . $slug . '/';
    $preview = offer_preview_base();

    return $preview !== null ? rtrim($preview, '/') . $path : $path;
}

function abs_lang_url(string $lang, string $page = 'home'): string
{
    return base_url() . lang_path($lang, $page);
}

function current_request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $preview = offer_preview_base();
    if ($preview !== null && str_starts_with($path, $preview)) {
        $path = substr($path, strlen($preview)) ?: '/';
    }

    // Origin nginx rewrites /de/ → /langs/de/index.php. Some stacks overwrite REQUEST_URI.
    if (preg_match('#^/langs/([a-z]{2})/index\.php$#', $path, $matches)) {
        return '/'.$matches[1].'/';
    }

    if ($path === '/sitemap.php') {
        return '/sitemap.xml';
    }

    if ($path === '/robots.php') {
        return '/robots.txt';
    }

    return $path === '' ? '/' : $path;
}

function resolve_page_slug(string $pageSlug): ?string
{
    if ($pageSlug === '') {
        return 'home';
    }
    foreach (PAGES as $key => $slug) {
        if ($slug === $pageSlug) {
            return $key;
        }
    }
    return null;
}

/**
 * URL scheme (like Altvecto sample):
 * - Default language (en) has no prefix: /, /about/, /privacy/
 * - Other languages keep a prefix: /de/, /de/about/
 * - Legacy /en/... permanently redirects to the unprefixed URL
 */
function parse_route(string $path): array
{
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        return ['type' => 'root'];
    }

    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
    $first = $segments[0] ?? '';

    // Legacy default-lang prefix → 301 to unprefixed URL
    if ($first === DEFAULT_LANG) {
        $pageSlug = $segments[1] ?? '';
        $page = resolve_page_slug($pageSlug);
        if ($page === null) {
            return ['type' => '404', 'lang' => DEFAULT_LANG];
        }
        if (count($segments) > 2) {
            return ['type' => '404', 'lang' => DEFAULT_LANG];
        }
        return [
            'type' => 'redirect',
            'to' => lang_path(DEFAULT_LANG, $page),
            'code' => 301,
        ];
    }

    // Prefixed non-default languages: /de/...
    if (in_array($first, SUPPORTED_LANGS, true) && $first !== DEFAULT_LANG) {
        $lang = $first;
        $pageSlug = $segments[1] ?? '';
        $page = resolve_page_slug($pageSlug);
        if ($page === null) {
            return ['type' => '404', 'lang' => $lang];
        }
        if (count($segments) > 2) {
            return ['type' => '404', 'lang' => $lang];
        }
        return ['type' => 'page', 'lang' => $lang, 'page' => $page];
    }

    // Unprefixed path = default language page: /about/, /privacy/, ...
    $page = resolve_page_slug($first);
    if ($page === null || $page === 'home') {
        // bare unknown slug, or accidental "home" slug
        return ['type' => '404', 'lang' => DEFAULT_LANG];
    }
    if (count($segments) > 1) {
        return ['type' => '404', 'lang' => DEFAULT_LANG];
    }
    return ['type' => 'page', 'lang' => DEFAULT_LANG, 'page' => $page];
}

function sitemap_page_keys(): array
{
    return array_keys(PAGES);
}

function load_lang(string $lang): array
{
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    if (!is_file($file)) {
        $file = __DIR__ . '/../lang/' . DEFAULT_LANG . '.php';
    }
    /** @var array $data */
    $data = require $file;
    return interpolate_lang(is_array($data) ? $data : []);
}

function render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $path = __DIR__ . '/../templates/' . $template . '.php';
    if (!is_file($path)) {
        http_response_code(404);
        $path = __DIR__ . '/../templates/404.php';
    }
    require $path;
}

function page_meta(array $langData, string $page): array
{
    $meta = $langData['meta'][$page] ?? $langData['meta']['home'] ?? [];
    return [
        'title' => $meta['title'] ?? (SITE_NAME),
        'description' => $meta['description'] ?? '',
    ];
}

function site_slug(): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', SITE_NAME) ?: 'offer');
}

function bg_photo_files(): array
{
    return [
        'hero' => 'hero-crypto.jpg',
        'desk' => 'night-desk.jpg',
        'office' => 'night-office.jpg',
        'director' => 'team-director.jpg',
        'accounts' => 'team-accounts.jpg',
        'support' => 'team-support.jpg',
        'markets' => 'team-markets.jpg',
        'product' => 'team-product.jpg',
        'notes' => 'team-notes.jpg',
        'clients' => 'team-clients.jpg',
    ];
}

function bg_photo_keys(): array
{
    return array_keys(bg_photo_files());
}

function bg_photo_rel(string $key): string
{
    $key = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?: 'hero';
    $files = bg_photo_files();
    $file = $files[$key] ?? $files['hero'];
    $slug = site_slug();
    $root = dirname(__DIR__);
    foreach ([
        'assets/img/'.$slug.'-'.$key.'.jpg',
        'assets/img/'.$file,
    ] as $rel) {
        $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($full)) {
            return $rel;
        }
    }

    return 'assets/img/'.$file;
}

function bg_photo_abs(string $key): string
{
    return rtrim(base_url(), '/').'/'.ltrim(bg_photo_rel($key), '/');
}

function bg_photo_size(string $key): array
{
    return match ($key) {
        'hero', 'office' => [1600, 900],
        'desk' => [900, 900],
        default => [900, 1200],
    };
}

function bg_photo_alt(array $langData, string $key): string
{
    $alt = $langData['photos'][$key] ?? '';

    return is_string($alt) && $alt !== '' ? $alt : (defined('SITE_NAME') ? (string) SITE_NAME : 'Bluegrove');
}

function bg_quotes(): array
{
    return [
        ['symbol' => 'BTC', 'pair' => 'BTC/USD', 'price' => '67,420', 'change' => '+0.30%', 'up' => true],
        ['symbol' => 'ETH', 'pair' => 'ETH/USD', 'price' => '3,184', 'change' => '+0.36%', 'up' => true],
        ['symbol' => 'SOL', 'pair' => 'SOL/USD', 'price' => '178.4', 'change' => '-0.40%', 'up' => false],
        ['symbol' => 'XRP', 'pair' => 'XRP/USD', 'price' => '0.62', 'change' => '+0.36%', 'up' => true],
        ['symbol' => 'EUR/USD', 'pair' => 'EUR/USD', 'price' => '1.08', 'change' => '+0.12%', 'up' => true],
        ['symbol' => 'GBP/USD', 'pair' => 'GBP/USD', 'price' => '1.27', 'change' => '-0.08%', 'up' => false],
    ];
}

function bg_candle_chart(string $class = 'bg-chart'): string
{
    $candles = [
        [42, 55, 60, 38],
        [55, 48, 58, 44],
        [48, 62, 66, 46],
        [62, 58, 68, 54],
        [58, 72, 76, 56],
        [72, 66, 78, 62],
        [66, 80, 84, 64],
        [80, 74, 86, 70],
        [74, 88, 92, 72],
        [88, 82, 94, 78],
    ];
    $width = 320;
    $height = 160;
    $pad = 6;
    $slot = ($width - $pad * 2) / count($candles);
    $svg = '<svg viewBox="0 0 '.$width.' '.$height.'" class="'.e($class).'" role="img" aria-label="Sample candlestick chart, not a live price">';
    foreach ($candles as $index => $c) {
        [$open, $close, $high, $low] = $c;
        $up = $close >= $open;
        $color = $up ? '#3dce7a' : '#e07a7a';
        $bodyWidth = $slot * 0.55;
        $x = $pad + $index * $slot + ($slot - $bodyWidth) / 2;
        $y = static fn (int $value): float => $height - $pad - ($value / 100) * ($height - $pad * 2);
        $top = min($y($open), $y($close));
        $body = max(2, abs($y($open) - $y($close)));
        $mid = $x + $bodyWidth / 2;
        $svg .= '<g><line x1="'.$mid.'" x2="'.$mid.'" y1="'.$y($high).'" y2="'.$y($low).'" stroke="'.$color.'" stroke-width="1.5"/><rect x="'.$x.'" y="'.$top.'" width="'.$bodyWidth.'" height="'.$body.'" rx="1.5" fill="'.$color.'"/></g>';
    }

    return $svg.'</svg>';
}

function bg_quote_rows(int $limit = 6, bool $light = false): void
{
    echo '<ul class="bg-quotes'.($light ? ' bg-quotes--light' : '').'">';
    foreach (array_slice(bg_quotes(), 0, $limit) as $quote) {
        $tone = $quote['up'] ? 'is-up' : 'is-down';
        echo '<li><strong>'.e($quote['pair']).'</strong><span>'.e($quote['price']).'</span><span class="bg-quotes__chg '.$tone.'">'.e($quote['change']).'</span></li>';
    }
    echo '</ul>';
}

function bg_figure(array $langData, string $key, string $class = '', bool $priority = false, string $caption = ''): void
{
    [$width, $height] = bg_photo_size($key);
    $className = trim('bg-shot '.$class);
    $loading = $priority ? ' fetchpriority="high"' : ' loading="lazy"';

    echo '<figure class="'.e($className).'">';
    echo '<img src="'.e(public_url(bg_photo_rel($key))).'" alt="'.e(bg_photo_alt($langData, $key)).'" width="'.$width.'" height="'.$height.'" decoding="async"'.$loading.'>';
    if ($caption !== '') {
        echo '<figcaption>'.e($caption).'</figcaption>';
    }
    echo '</figure>';
}

function bg_page_photos(string $page): array
{
    return match ($page) {
        'home' => ['hero', 'desk'],
        'about' => ['office'],
        'team' => ['director', 'accounts', 'support', 'markets', 'product', 'notes', 'clients'],
        default => [],
    };
}

function hero_image_rel(): string
{
    return bg_photo_rel('hero');
}

function hero_image_url(): string
{
    return public_url(hero_image_rel());
}

function hero_image_abs(): string
{
    return bg_photo_abs('hero');
}

function site_domain(): string
{
    if (defined('SITE_URL') && SITE_URL !== '') {
        return parse_url((string) SITE_URL, PHP_URL_HOST) ?: 'localhost';
    }
    if (defined('SITE_DOMAIN') && SITE_DOMAIN !== '') {
        return parse_url((string) SITE_DOMAIN, PHP_URL_HOST) ?: 'localhost';
    }

    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function crm_funnel(): string
{
    $funnel = defined('CRM_FUNNEL') ? trim((string) CRM_FUNNEL) : '';

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
    if (!defined('FORM_ALLOWED_COUNTRIES')) {
        return [];
    }

    $raw = array_filter(array_map('trim', explode(',', strtolower((string) FORM_ALLOWED_COUNTRIES))));
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
    $default = defined('FORM_PHONE_COUNTRY') ? strtolower(trim((string) FORM_PHONE_COUNTRY)) : 'ip';
    $autoIp = ($default === 'ip' || $default === 'auto');
    $ipCode = form_phone_code_from_ip(form_ip_country());

    if ($autoIp) {
        if ($ipCode !== '') {
            return $ipCode;
        }

        if ($allowed !== []) {
            return in_array('gb', $allowed, true) ? 'gb' : $allowed[0];
        }

        $fromGeo = function_exists('geo_phone_country') ? geo_phone_country() : '';
        if ($fromGeo !== '' && $fromGeo !== 'us') {
            return $fromGeo;
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

