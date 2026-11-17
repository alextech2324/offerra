<?php
/** @var string $lang */
/** @var array $langData */
/** @var string $page */
/** @var array $meta */
/** @var string $phoneCountry */
$nav = $langData['nav'];
$homeUrl = lang_path($lang, 'home');
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($meta['title']) ?></title>
  <meta name="description" content="<?= e($meta['description']) ?>">
  <link rel="canonical" href="<?= e(abs_lang_url($lang, $page)) ?>">
  <?php foreach (SUPPORTED_LANGS as $hl): ?>
  <link rel="alternate" hreflang="<?= e($hl) ?>" href="<?= e(abs_lang_url($hl, $page)) ?>">
  <?php endforeach; ?>
  <link rel="alternate" hreflang="x-default" href="<?= e(abs_lang_url(DEFAULT_LANG, $page)) ?>">
  <link rel="icon" href="<?= e(public_url('assets/icons/mark.svg')) ?>" type="image/svg+xml">
  <meta name="theme-color" content="#1a2744">
  <meta property="og:title" content="<?= e($meta['title']) ?>">
  <meta property="og:description" content="<?= e($meta['description']) ?>">
  <meta property="og:image" content="<?= e(hero_image_abs()) ?>">
  <meta property="og:image:alt" content="<?= e(fortis_photo_alt($langData, 'desk')) ?>">
  <meta property="og:image:width" content="1600">
  <meta property="og:image:height" content="900">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= e(hero_image_abs()) ?>">
  <link rel="stylesheet" href="<?= e(public_url('assets/css/fortis.css')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.css">
</head>
<body class="ft-body" data-phone-country="<?= e($phoneCountry ?? 'us') ?>" data-lang="<?= e($lang) ?>" data-preview-base="<?= e(offer_preview_base() ?? '') ?>">
<a class="ft-skip" href="#main-content"><?= e($nav['skip'] ?? 'Skip to content') ?></a>
<header class="ft-mast">
  <div class="ft-mast__bar">
    <a class="ft-brand" href="<?= e($homeUrl) ?>" aria-label="<?= e($nav['home_aria']) ?>">
      <img class="ft-brand__mark" src="<?= e(public_url('assets/icons/mark.svg')) ?>" width="28" height="28" alt="" aria-hidden="true">
      <span class="ft-brand__name"><?= e(SITE_NAME) ?></span>
    </a>
    <nav class="ft-desk" aria-label="<?= e($nav['home_aria']) ?>">
      <a href="<?= e(lang_path($lang, 'about')) ?>"><?= e($nav['about']) ?></a>
      <a href="<?= e(lang_path($lang, 'product')) ?>"><?= e($nav['product']) ?></a>
      <a href="<?= e(lang_path($lang, 'offer')) ?>"><?= e($nav['offer']) ?></a>
      <a href="<?= e(lang_path($lang, 'faq')) ?>"><?= e($nav['faq']) ?></a>
      <a href="<?= e(lang_path($lang, 'contact')) ?>"><?= e($nav['contact']) ?></a>
    </nav>
    <div class="ft-mast__tools">
      <label class="ft-visually-hidden" for="lang-switch"><?= e($nav['language']) ?></label>
      <select id="lang-switch" class="lang-switch ft-lang" data-page="<?= e($page) ?>" aria-label="<?= e($nav['language']) ?>">
        <?php foreach (SUPPORTED_LANGS as $code): ?>
        <option value="<?= e($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= e(strtoupper($code)) ?></option>
        <?php endforeach; ?>
      </select>
      <a class="ft-cta ft-cta--compact" href="<?= e($homeUrl) ?>#lead-form"><?= e($nav['cta']) ?></a>
      <button type="button" class="nav-burger ft-burger" id="nav-burger"
        aria-expanded="false" aria-controls="nav-drawer"
        aria-label="<?= e($nav['menu'] ?? 'Menu') ?>">
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
      </button>
    </div>
  </div>
  <div class="nav-drawer ft-drawer" id="nav-drawer" hidden>
    <a href="<?= e(lang_path($lang, 'about')) ?>"><?= e($nav['about']) ?></a>
    <a href="<?= e(lang_path($lang, 'product')) ?>"><?= e($nav['product']) ?></a>
    <a href="<?= e(lang_path($lang, 'offer')) ?>"><?= e($nav['offer']) ?></a>
    <a href="<?= e(lang_path($lang, 'faq')) ?>"><?= e($nav['faq']) ?></a>
    <a href="<?= e(lang_path($lang, 'contact')) ?>"><?= e($nav['contact']) ?></a>
    <a class="ft-cta" href="<?= e($homeUrl) ?>#lead-form"><?= e($nav['cta']) ?></a>
  </div>
</header>
<main id="main-content" class="ft-main">
