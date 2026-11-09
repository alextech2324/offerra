<?php
require_once __DIR__ . '/config.php';
$page_title = $page_title ?? (SITE_NAME . ' ᐉ Visiškai valdykite tiesiogines investicijas');
$page_description = $page_description ?? ('Stebėkite realiu laiku, kaip veikia jūsų kapitalas su ' . SITE_NAME . ': aiškios ataskaitos, asmeninis analitikas ir dirbtinis intelektas. Gaukite papildomų pajamų iš ' . money_min());
$page_canonical = $page_canonical ?? page_url();
$active_page = $active_page ?? 'home';
?><!doctype html>
<html lang="<?= e(site_locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="format-detection" content="telephone=no" />
<meta name="referrer" content="strict-origin-when-cross-origin" />
<meta name="robots" content="<?= e($active_page === 'Thanks' ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1') ?>" />
<title><?= e($page_title) ?></title>
<meta name="description" content="<?= e($page_description) ?>" />
<link rel="canonical" href="<?= e($page_canonical) ?>" />
<meta name="author" content="<?= e(SITE_NAME) ?>" />
<link rel="icon" type="image/svg+xml" href="<?= asset('static/img/icons/favicon.svg') ?>" />
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('static/img/icons/favicon-32.png') ?>" />
<link rel="icon" type="image/png" sizes="16x16" href="<?= asset('static/img/icons/favicon-16.png') ?>" />
<link rel="shortcut icon" href="<?= asset('static/img/icons/favicon.ico') ?>" />
<link rel="apple-touch-icon" sizes="180x180" href="<?= asset('static/img/icons/apple-touch-icon.png') ?>" />
<meta name="msapplication-TileImage" content="<?= asset('static/img/icons/icon-192.png') ?>" />
<meta name="msapplication-TileColor" content="#0b1020" />
<meta name="theme-color" content="#fcfcfd" />
<meta name="color-scheme" content="light" />
<meta name="darkreader-lock" />
<meta name="application-name" content="<?= e(SITE_NAME) ?>" />
<meta name="apple-mobile-web-app-title" content="<?= e(SITE_NAME) ?>" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta property="og:type" content="website" />
<meta property="og:locale" content="lt_LT" />
<meta property="og:site_name" content="<?= e(SITE_NAME) ?>" />
<meta property="og:title" content="<?= e($page_title) ?>" />
<meta property="og:description" content="<?= e($page_description) ?>" />
<meta property="og:url" content="<?= e($page_canonical) ?>" />
<meta property="og:image" content="<?= e(page_url('static/img/og.webp')) ?>" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta property="og:image:type" content="image/webp" />
<meta property="og:image:alt" content="<?= e(SITE_NAME) ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= e($page_title) ?>" />
<meta name="twitter:description" content="<?= e($page_description) ?>" />
<meta name="twitter:image" content="<?= e(page_url('static/img/og.webp')) ?>" />
<meta name="twitter:image:alt" content="<?= e(SITE_NAME) ?>" />
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin />
<link rel="stylesheet" href="<?= asset_version('static/fonts/40a9cfc5225f/f.css') ?>" />
<link rel="stylesheet" href="<?= asset_version('static/css/main.css') ?>" />
<link rel="stylesheet" href="<?= asset_version('integration/default-integration.css') ?>" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.min.css" />
<script>
window.APP_LANG = {
  valPhoneInvalid: 'Įveskite galiojantį telefono numerį',
  valPhoneCountry: 'Netinkamas šalies kodas',
  valPhoneShort: 'Skaičius per trumpas',
  valPhoneLong: 'Skaičius per ilgas'
};
window.OFFER_CURRENCY_SYMBOL = <?= json_encode(currency_symbol(), JSON_UNESCAPED_UNICODE) ?>;
window.OFFER_LOCALE = <?= json_encode(site_locale(), JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php require __DIR__ . '/schema.php'; ?>
<?php if (function_exists('offer_vitals_head')) { offer_vitals_head(); } ?>
</head>
<body>
<?php require __DIR__ . '/icon-sprite.php'; ?>
<a class="nd5hm" href="#top">Pereiti prie turinio</a>
