<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$path = current_request_path();

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "User-agent: *\nAllow: /\n\nSitemap: " . base_url() . "/sitemap.xml\n";
    exit;
}

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $pages = sitemap_page_keys();
    $langPack = load_lang(DEFAULT_LANG);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach (SUPPORTED_LANGS as $lang) {
        foreach ($pages as $pageKey) {
            $loc = abs_lang_url($lang, $pageKey);
            $priority = $pageKey === 'home' ? '1.0' : '0.8';
            echo "  <url>\n";
            echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
            echo "    <changefreq>weekly</changefreq>\n";
            echo '    <priority>' . $priority . "</priority>\n";
            foreach (bg_page_photos($pageKey) as $photoKey) {
                echo "    <image:image>\n";
                echo '      <image:loc>' . htmlspecialchars(bg_photo_abs($photoKey), ENT_XML1) . "</image:loc>\n";
                echo '      <image:title>' . htmlspecialchars(bg_photo_alt($langPack, $photoKey), ENT_XML1) . "</image:title>\n";
                echo "    </image:image>\n";
            }
            echo "  </url>\n";
        }
    }
    echo "</urlset>\n";
    exit;
}

$route = parse_route($path);

if (($route['type'] ?? '') === 'redirect') {
    header('Location: ' . $route['to'], true, (int) ($route['code'] ?? 301));
    exit;
}

if (($route['type'] ?? '') === 'root') {
    $route = ['type' => 'page', 'lang' => DEFAULT_LANG, 'page' => 'home'];
}

if (($route['type'] ?? '') === '404') {
    http_response_code(404);
    $lang = $route['lang'] ?? DEFAULT_LANG;
    if (!in_array($lang, SUPPORTED_LANGS, true)) {
        $lang = DEFAULT_LANG;
    }
    $langData = load_lang($lang);
    $country = geo_detect_country();
    $phoneCountry = strtolower($country);
    $page = '404';
    $meta = [
        'title' => t($langData, 'meta.404.title', 'Page not found | ' . SITE_NAME),
        'description' => t($langData, 'meta.404.description', ''),
    ];
    render('404', compact('lang', 'langData', 'page', 'meta', 'country', 'phoneCountry'));
    exit;
}

$lang = $route['lang'];
$page = $route['page'];
geo_set_lang_cookie($lang);

$langData = load_lang($lang);
$meta = page_meta($langData, $page);
$country = geo_detect_country();
$phoneCountry = strtolower($country);

$templateMap = [
    'home' => 'home',
    'about' => 'about',
    'product' => 'product',
    'offer' => 'offer',
    'team' => 'team',
    'sign-up' => 'sign-up',
    'contact' => 'contact',
    'faq' => 'faq',
    'privacy' => 'legal',
    'terms' => 'legal',
    'cookie-policy' => 'legal',
    'abuse' => 'legal',
];

$template = $templateMap[$page] ?? 'home';
render($template, compact('lang', 'langData', 'page', 'meta', 'country', 'phoneCountry'));
