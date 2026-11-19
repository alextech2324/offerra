<?php
/** @var string $lang */
/** @var array $langData */
$nav = $langData['nav'];
$footer = $langData['footer'];
$year = (string) date('Y');
$email = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? (string) SITE_EMAIL : 'support@example.com';
?>
</main>
<footer class="bg-colophon">
  <div class="bg-wrap">
    <div class="bg-colophon__grid">
      <div>
        <a class="bg-brand" href="<?= e(lang_path($lang, 'home')) ?>" aria-label="<?= e($nav['home_aria']) ?>">
          <img class="bg-brand__mark" src="<?= e(public_url('assets/icons/mark.svg')) ?>" width="36" height="36" alt="" aria-hidden="true">
          <span class="bg-brand__name"><?= e(SITE_NAME) ?></span>
        </a>
        <a class="bg-colophon__mail" href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
      </div>
      <nav class="bg-colophon__nav" aria-label="Footer">
        <a href="<?= e(lang_path($lang, 'about')) ?>"><?= e($nav['about']) ?></a>
        <a href="<?= e(lang_path($lang, 'product')) ?>"><?= e($nav['product']) ?></a>
        <a href="<?= e(lang_path($lang, 'team')) ?>"><?= e($nav['team']) ?></a>
        <a href="<?= e(lang_path($lang, 'faq')) ?>"><?= e($nav['faq']) ?></a>
        <a href="<?= e(lang_path($lang, 'contact')) ?>"><?= e($nav['contact']) ?></a>
        <a href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($nav['signup']) ?></a>
        <a href="<?= e(lang_path($lang, 'privacy')) ?>"><?= e($nav['privacy']) ?></a>
        <a href="<?= e(lang_path($lang, 'terms')) ?>"><?= e($nav['terms']) ?></a>
        <a href="<?= e(lang_path($lang, 'cookie-policy')) ?>"><?= e($nav['cookies']) ?></a>
        <a href="<?= e(lang_path($lang, 'abuse')) ?>"><?= e($nav['abuse']) ?></a>
      </nav>
    </div>
    <p class="bg-colophon__risk"><?= e($footer['risk']) ?></p>
    <p class="bg-colophon__copy"><?= e(str_replace('{year}', $year, $footer['copy'])) ?></p>
  </div>
</footer>
<script>
  window.APP_LANG = <?= json_encode([
      'valPhoneInvalid' => $langData['form']['val_phone_invalid'] ?? 'Enter a valid phone number',
      'valPhoneCountry' => $langData['form']['val_phone_country'] ?? 'Invalid country code',
      'valPhoneShort' => $langData['form']['val_phone_short'] ?? 'The phone number is too short',
      'valPhoneLong' => $langData['form']['val_phone_long'] ?? 'The phone number is too long',
      'valPhoneRequired' => $langData['form']['val_phone_required'] ?? 'Enter your phone number',
      'valSessionExpired' => $langData['form']['val_session'] ?? 'Session expired. Please reload the page and try again.',
      'valGenericError' => $langData['form']['val_generic'] ?? ($langData['hero']['form_err'] ?? 'Something went wrong. Please try again later.'),
      'valConnectionError' => $langData['form']['val_connection'] ?? 'Connection error. Check your internet connection and try again.',
      'themeNight' => $nav['night'] ?? 'Night',
      'themeLight' => $nav['light'] ?? 'Light',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/intlTelInput.min.js"></script>
<script src="<?= e(public_url('assets/js/bluegrove.js')) ?>"></script>
<script src="<?= e(public_url('integration/validation.js')) ?>"></script>
</body>
</html>
