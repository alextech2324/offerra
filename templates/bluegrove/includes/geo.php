<?php
declare(strict_types=1);

/**
 * Visitor country + preferred language.
 * Country comes from Cloudflare (CF-IPCountry). No third-party geo API.
 */

/** Countries where IP alone is not enough — prefer Accept-Language. */
const GEO_MULTILINGUAL_COUNTRIES = ['CH', 'BE', 'CA', 'LU'];

function geo_country_from_headers(): ?string
{
    $keys = ['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $code = strtoupper(trim((string) $_SERVER[$key]));
            if ($code !== '' && $code !== 'XX' && preg_match('/^[A-Z]{2}$/', $code)) {
                return $code;
            }
        }
    }
    return null;
}

function geo_detect_country(): string
{
    return geo_country_from_headers() ?? 'US';
}

function geo_is_search_bot(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return false;
    }

    return (bool) preg_match(
        '/googlebot|adsbot-google|bingbot|yandex|baiduspider|duckduckbot|slurp|facebookexternalhit|twitterbot|linkedinbot|applebot|semrush|ahrefs|dotbot|mj12bot|petalbot/i',
        $ua
    );
}

function geo_lang_from_country(string $country): ?string
{
    $country = strtoupper($country);
    $map = defined('GEO_LANG_MAP') ? GEO_LANG_MAP : [];
    $lang = $map[$country] ?? null;
    if (!is_string($lang) || $lang === '') {
        return null;
    }

    return in_array($lang, SUPPORTED_LANGS, true) ? $lang : null;
}

function geo_accept_language(bool $primaryOnly = false): ?string
{
    $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($header === '') {
        return null;
    }

    foreach (explode(',', $header) as $part) {
        $tag = strtolower(trim(explode(';', $part, 2)[0]));
        if ($tag === '') {
            continue;
        }
        $short = substr($tag, 0, 2);
        if (in_array($tag, SUPPORTED_LANGS, true)) {
            return $tag;
        }
        if (preg_match('/^[a-z]{2}$/', $short) && in_array($short, SUPPORTED_LANGS, true)) {
            return $short;
        }
        if ($primaryOnly) {
            return null;
        }
    }

    return null;
}

function geo_cookie_lang(): ?string
{
    if (empty($_COOKIE[LANG_COOKIE]) || !is_string($_COOKIE[LANG_COOKIE])) {
        return null;
    }
    $lang = $_COOKIE[LANG_COOKIE];

    return in_array($lang, SUPPORTED_LANGS, true) ? $lang : null;
}

/**
 * Kept for Thanks.php / diagnostics. Homepage no longer auto-redirects:
 * `/` is always DEFAULT_LANG; the visitor picks a prefix in the switcher.
 */
function geo_preferred_lang(): string
{
    $fromCookie = geo_cookie_lang();
    if ($fromCookie !== null) {
        return $fromCookie;
    }

    if (geo_is_search_bot()) {
        return DEFAULT_LANG;
    }

    $country = geo_detect_country();
    $multilingual = in_array($country, GEO_MULTILINGUAL_COUNTRIES, true);

    if ($multilingual) {
        $fromAl = geo_accept_language(true);
        if ($fromAl !== null) {
            return $fromAl;
        }
        $fromIp = geo_lang_from_country($country);
        return $fromIp ?? DEFAULT_LANG;
    }

    $fromIp = geo_lang_from_country($country);
    if ($fromIp !== null) {
        return $fromIp;
    }

    return geo_accept_language() ?? DEFAULT_LANG;
}

function geo_set_lang_cookie(string $lang): void
{
    if (!in_array($lang, SUPPORTED_LANGS, true)) {
        return;
    }
    $expire = time() + (LANG_COOKIE_DAYS * 86400);
    setcookie(LANG_COOKIE, $lang, [
        'expires' => $expire,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/** Lowercase ISO country for intl-tel-input */
function geo_phone_country(): string
{
    return strtolower(geo_detect_country());
}
