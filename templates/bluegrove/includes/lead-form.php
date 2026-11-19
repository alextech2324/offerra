<?php
/** Shared lead / registration form. */
/** @var array $langData */
/** @var string $lang */
$h = $langData['hero'];
$already = $langData['form'] ?? [];
$privacyUrl = lang_path($lang, 'privacy');
$termsUrl = lang_path($lang, 'terms');
$legalHtml = str_replace(
    ['{privacy}', '{terms}'],
    [e($privacyUrl), e($termsUrl)],
    $h['legal']
);
$formId = $formId ?? 'lead-form';
$wrapId = $wrapId ?? 'lead-form-wrap';
$formHeading = $formHeading ?? ($h['form_title'] ?? 'Request an account');
$formCompact = !empty($formCompact);
$phoneCountry = function_exists('form_visitor_phone_country')
    ? form_visitor_phone_country()
    : ($phoneCountry ?? 'gb');
$allowedCountries = function_exists('form_allowed_countries') ? form_allowed_countries() : [];
$leadCookie = function_exists('site_slug') ? site_slug() . '_lead' : 'bluegrove_lead';
$cookieDays = defined('FORM_LEAD_COOKIE_DAYS') ? (int) FORM_LEAD_COOKIE_DAYS : 30;
?>
<div class="bg-ledger<?= $formCompact ? ' bg-ledger--compact' : '' ?>" id="<?= e($wrapId) ?>">
  <form id="<?= e($formId) ?>" class="js-lead-form bg-ledger__form" action="<?= e(public_url('integration/send.php')) ?>" method="post" novalidate
    data-ok="<?= e($h['form_ok']) ?>"
    data-err="<?= e($h['form_err']) ?>"
    data-lead-cookie="<?= e($leadCookie) ?>"
    data-cookie-days="<?= $cookieDays ?>">
    <div class="bg-ledger__done hidden" data-already-registered>
      <p class="bg-ledger__done-title"><?= e($already['already_title'] ?? 'Request saved') ?></p>
      <p><?= e($already['already_text'] ?? 'We stored your name, email, and phone so we can contact you about the account.') ?></p>
    </div>
    <div data-form-fields>
      <p class="bg-ledger__heading"><?= e($formHeading) ?></p>
      <input type="hidden" name="language" value="<?= e($lang) ?>">
      <input type="hidden" name="phone_country" value="<?= e($phoneCountry) ?>">
      <input type="hidden" name="only_countries" value='<?= e(json_encode($allowedCountries)) ?>'>
      <input type="hidden" name="subid" value="<?= e(function_exists('offer_click_id') ? offer_click_id() : '') ?>">
      <input type="hidden" name="form_token" value="" autocomplete="off">
      <div class="form-preloader hidden" aria-hidden="true"><span class="bg-spinner"></span></div>
      <div class="bg-fields">
        <div class="field bg-field">
          <label for="<?= e($formId) ?>-first_name"><?= e($h['first_name']) ?></label>
          <input id="<?= e($formId) ?>-first_name" name="first_name" type="text" required autocomplete="given-name" placeholder="<?= e($h['first_ph'] ?? 'Alex') ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field bg-field">
          <label for="<?= e($formId) ?>-last_name"><?= e($h['last_name']) ?></label>
          <input id="<?= e($formId) ?>-last_name" name="last_name" type="text" required autocomplete="family-name" placeholder="<?= e($h['last_ph'] ?? 'Morgan') ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field bg-field bg-field--wide">
          <label for="<?= e($formId) ?>-email"><?= e($h['email']) ?></label>
          <input id="<?= e($formId) ?>-email" name="email" type="email" required autocomplete="email" placeholder="<?= e($h['email_ph'] ?? 'you@email.com') ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field bg-field bg-field--wide">
          <label for="<?= e($formId) ?>-phone"><?= e($h['phone']) ?></label>
          <input id="<?= e($formId) ?>-phone" name="fullphone" type="tel" required autocomplete="tel" class="js-phone-input">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
      </div>
      <div class="honeypot" aria-hidden="true">
        <input type="text" name="hp_trap" tabindex="-1" autocomplete="off" data-honeypot readonly>
        <input type="text" name="hp_trap2" tabindex="-1" autocomplete="off" data-honeypot readonly>
      </div>
      <button class="bg-cta bg-cta--block" type="submit"><?= e($h['submit']) ?></button>
      <p class="bg-ledger__legal"><?= $legalHtml ?></p>
      <div class="form-msg hidden" data-form-message role="status">
        <span data-form-message-content></span>
      </div>
    </div>
  </form>
</div>
