<?php
require_once __DIR__.'/config.php';
$page_title = $page_title ?? page_title('AI market analysis and trading tools');
$page_description = $page_description ?? (SITE_NAME.' helps you follow stocks and crypto with AI. From '.min_deposit_label().'. No promised return.');
$page_canonical = $page_canonical ?? page_url();
$page_noindex = $page_noindex ?? false;
$og_image = canonical_url(og_image_path());
?><!DOCTYPE html>
<html lang="<?= e(site_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?></title>
<meta name="description" content="<?= e($page_description) ?>">
<meta name="robots" content="<?= $page_noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' ?>">
<meta name="author" content="<?= e(SITE_NAME) ?>">
<meta name="theme-color" content="#0b0f19">
<link rel="canonical" href="<?= e($page_canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= e(str_replace('-', '_', site_locale())) ?>">
<meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
<meta property="og:title" content="<?= e($page_title) ?>">
<meta property="og:description" content="<?= e($page_description) ?>">
<meta property="og:url" content="<?= e($page_canonical) ?>">
<meta property="og:image" content="<?= e($og_image) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($page_title) ?>">
<meta name="twitter:description" content="<?= e($page_description) ?>">
<meta name="twitter:image" content="<?= e($og_image) ?>">
<link rel="icon" type="image/png" href="<?= asset('static/images/favicon-96x96.png') ?>" sizes="96x96">
<link rel="icon" type="image/png" href="<?= asset('static/images/favicon-32x32.png') ?>" sizes="32x32">
<link rel="icon" type="image/png" href="<?= asset('static/images/favicon-16x16.png') ?>" sizes="16x16">
<link rel="icon" type="image/svg+xml" href="<?= asset('static/images/favicon.svg') ?>">
<link rel="shortcut icon" href="<?= asset('static/images/favicon.ico') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= asset('static/images/apple-touch-icon.png') ?>">
<link rel="manifest" href="<?= asset('static/site.webmanifest') ?>">
<link rel="preload" href="<?= asset('static/images/investor.webp') ?>" as="image" type="image/webp" fetchpriority="high">
<link href="<?= asset('static/css/normalize.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/wf-theme.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/wf-base.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/main.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/css.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/css2.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/home-review.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/form.css') ?>" rel="stylesheet">
<link href="<?= asset('static/css/inner-pages.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.css">
<link href="<?= asset('integration/default-integration.css') ?>" rel="stylesheet">
<link rel="preload" as="font" type="font/woff2" href="<?= asset('static/fonts/xn7gYHE41ni1AdIRggexSvfedN4.woff2') ?>" crossorigin>
<style>
  :root{
    --iti-path-flags-1x:url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/img/flags.png");
    --iti-path-flags-2x:url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/img/flags@2x.png");
  }
  /* Critical hero — match pnaf-plataforma.site (Manrope body, Rubik headings, LCP sizes) */
  body{margin:0;font-family:Manrope,"Segoe UI","Helvetica Neue",Arial,sans-serif;background:#0b0f19;color:#fff}
  #top-of-page{min-height:100svh}
  .padding-global{padding-left:1.25rem;padding-right:1.25rem}
  .padding-section-large.is-hero{padding-top:3rem;padding-bottom:3rem}
  .header_component{display:block}
  .heading-style-h1{font-size:clamp(2rem,8vw,3.8rem);font-weight:600;line-height:1.1;margin:0;color:#fff}
  .navbar_component{min-height:64px}
  [data-w-id]{opacity:1!important;transform:none!important;-webkit-transform:none!important}
  .navbar_component.w-nav{background:#fff}
  .navbar_link.w-nav-link{color:#111}
  .navbar_link.w-nav-link:hover{color:#5217BF}
  .menu-icon1_line-top,.menu-icon1_line-middle,.menu-icon1_line-bottom{background-color:#111}
  form.js-lead-form input,form.leadform input{min-height:48px;box-sizing:border-box}
  .iti{width:100%;min-height:48px}
  .hero-wrap .hero-main-image,
  .hero-wrap .spacer-small img{
    display:block;width:100%;max-width:720px;height:auto!important;aspect-ratio:720/404;object-fit:contain;border-radius:15px;margin-inline:auto;
  }
  a.button.w-button{background-color:#1565c0!important;color:#fff!important}
  .iti__selected-dial-code,.iti__country-name{color:#000!important}
</style>
<?php require __DIR__.'/schema.php'; ?>
<?php if (function_exists('offer_vitals_head')) { offer_vitals_head(); } ?>
</head>
<body>
