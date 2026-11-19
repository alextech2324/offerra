<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/helpers.php';

$lang = geo_cookie_lang() ?? DEFAULT_LANG;
if (!in_array($lang, SUPPORTED_LANGS, true)) {
    $lang = DEFAULT_LANG;
}

$langData = load_lang($lang);
$page = 'home';
$thanks = $langData['thanks'] ?? [];
$meta = [
    'title' => $thanks['title'] ?? ('Thank you | ' . SITE_NAME),
    'description' => $thanks['lead'] ?? '',
];
$country = geo_detect_country();
$phoneCountry = strtolower($country);

require __DIR__ . '/includes/header.php';
?>
<section class="bg-pagehead">
  <div class="bg-wrap bg-center">
    <p class="bg-kicker"><?= e($thanks['eyebrow'] ?? 'Thank you') ?></p>
    <h1><?= e($thanks['heading'] ?? 'We received your request') ?></h1>
    <p class="bg-lede"><?= e($thanks['lead'] ?? ('Your request has been received. This form does not take a deposit.')) ?></p>
    <p>
      <a class="bg-cta" href="<?= e(lang_path($lang, 'home')) ?>"><?= e($thanks['back'] ?? 'Back to home') ?></a>
    </p>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
