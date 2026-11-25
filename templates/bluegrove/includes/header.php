<?php
/** @var string $lang */
/** @var array $langData */
/** @var string $page */
/** @var array $meta */
/** @var string $phoneCountry */
$nav = $langData['nav'];
$homeUrl = lang_path($lang, 'home');
$logoMark = public_url('assets/icons/mark.svg');
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
  <link rel="icon" href="<?= e($logoMark) ?>" type="image/svg+xml">
  <meta name="theme-color" content="#0c2340">
  <meta property="og:title" content="<?= e($meta['title']) ?>">
  <meta property="og:description" content="<?= e($meta['description']) ?>">
  <meta property="og:image" content="<?= e(hero_image_abs()) ?>">
  <meta property="og:image:alt" content="<?= e(bg_photo_alt($langData, 'hero')) ?>">
  <meta property="og:image:width" content="1600">
  <meta property="og:image:height" content="900">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= e(hero_image_abs()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(public_url('assets/css/bluegrove.css')) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.css">
  <script>
    (function(){try{if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}})();
  </script>
</head>
<body class="bg-body" data-phone-country="<?= e($phoneCountry ?? 'us') ?>" data-lang="<?= e($lang) ?>" data-preview-base="<?= e(offer_preview_base() ?? '') ?>">
<div class="bg-orbs" aria-hidden="true"><span class="bg-orb bg-orb--a"></span><span class="bg-orb bg-orb--b"></span></div>
<a class="bg-skip" href="#main-content"><?= e($nav['skip'] ?? 'Skip to content') ?></a>
<div class="bg-stick">
<header class="bg-mast">
  <div class="bg-mast__bar">
    <a class="bg-brand" href="<?= e($homeUrl) ?>" aria-label="<?= e($nav['home_aria']) ?>">
      <img class="bg-brand__mark" src="<?= e($logoMark) ?>" width="36" height="36" alt="" aria-hidden="true">
      <span class="bg-brand__name"><?= e(SITE_NAME) ?></span>
    </a>
    <nav class="bg-desk" aria-label="<?= e($nav['home_aria']) ?>">
      <a href="<?= e(lang_path($lang, 'about')) ?>"<?= $page === 'about' ? ' aria-current="page"' : '' ?>><?= e($nav['about']) ?></a>
      <a href="<?= e(lang_path($lang, 'product')) ?>"<?= $page === 'product' ? ' aria-current="page"' : '' ?>><?= e($nav['product']) ?></a>
      <a href="<?= e(lang_path($lang, 'faq')) ?>"<?= $page === 'faq' ? ' aria-current="page"' : '' ?>><?= e($nav['faq']) ?></a>
      <a href="<?= e(lang_path($lang, 'team')) ?>"<?= $page === 'team' ? ' aria-current="page"' : '' ?>><?= e($nav['team']) ?></a>
      <a href="<?= e(lang_path($lang, 'contact')) ?>"<?= $page === 'contact' ? ' aria-current="page"' : '' ?>><?= e($nav['contact']) ?></a>
    </nav>
    <div class="bg-mast__tools">
      <label class="bg-visually-hidden" for="lang-switch"><?= e($nav['language']) ?></label>
      <select id="lang-switch" class="lang-switch bg-lang" data-page="<?= e($page) ?>" aria-label="<?= e($nav['language']) ?>">
        <?php foreach (SUPPORTED_LANGS as $code): ?>
        <option value="<?= e($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= e(strtoupper($code)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="bg-theme" id="theme-toggle" aria-pressed="false">
        <span class="bg-theme__icon" aria-hidden="true"></span>
        <span data-theme-label><?= e($nav['night'] ?? 'Night') ?></span>
      </button>
      <a class="bg-cta bg-cta--compact" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($nav['cta']) ?></a>
      <button type="button" class="nav-burger bg-burger" id="nav-burger"
        aria-expanded="false" aria-controls="nav-drawer"
        aria-label="<?= e($nav['menu'] ?? 'Menu') ?>">
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
        <span aria-hidden="true"></span>
      </button>
    </div>
  </div>
  <div class="nav-drawer bg-drawer" id="nav-drawer" hidden>
    <a href="<?= e(lang_path($lang, 'about')) ?>"><?= e($nav['about']) ?></a>
    <a href="<?= e(lang_path($lang, 'product')) ?>"><?= e($nav['product']) ?></a>
    <a href="<?= e(lang_path($lang, 'faq')) ?>"><?= e($nav['faq']) ?></a>
    <a href="<?= e(lang_path($lang, 'team')) ?>"><?= e($nav['team']) ?></a>
    <a href="<?= e(lang_path($lang, 'contact')) ?>"><?= e($nav['contact']) ?></a>
    <a class="bg-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($nav['cta']) ?></a>
  </div>
</header>
<div class="bg-tape" aria-label="<?= e($langData['tape']['aria'] ?? 'Market quotes') ?>">
  <div class="bg-tape__track">
    <?php for ($loop = 0; $loop < 2; $loop++): ?>
    <div class="bg-tape__row"<?= $loop === 1 ? ' aria-hidden="true"' : '' ?>>
      <?php foreach (bg_quotes() as $quote): ?>
      <span>
        <strong><?= e($quote['symbol']) ?></strong>
        <?= e($quote['price']) ?>
        <em class="<?= $quote['up'] ? 'is-up' : 'is-down' ?>"><?= e($quote['change']) ?></em>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>
</div>
<main id="main-content">
