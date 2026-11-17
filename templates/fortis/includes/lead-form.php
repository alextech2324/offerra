<?php
/** Shared lead / registration form (home, contact, sign-up). */
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
$phoneCountry = function_exists('form_visitor_phone_country')
    ? form_visitor_phone_country()
    : ($phoneCountry ?? 'gb');
$allowedCountries = function_exists('form_allowed_countries') ? form_allowed_countries() : [];
$leadCookie = function_exists('site_slug') ? site_slug() . '_lead' : 'fortis_lead';
$cookieDays = defined('FORM_LEAD_COOKIE_DAYS') ? (int) FORM_LEAD_COOKIE_DAYS : 30;
?>
<div class="ft-ledger" id="<?= e($wrapId) ?>">
  <form id="<?= e($formId) ?>" class="js-lead-form ft-ledger__form" action="<?= e(public_url('integration/send.php')) ?>" method="post" novalidate
    data-ok="<?= e($h['form_ok']) ?>"
    data-err="<?= e($h['form_err']) ?>"
    data-lead-cookie="<?= e($leadCookie) ?>"
    data-cookie-days="<?= $cookieDays ?>">
    <div class="ft-ledger__done hidden" data-already-registered>
      <p class="ft-ledger__done-title"><?= e($already['already_title'] ?? 'You are already registered') ?></p>
      <p><?= e($already['already_text'] ?? 'Your request has been received. Please wait for a call from our adviser.') ?></p>
    </div>
    <div data-form-fields>
      <input type="hidden" name="language" value="<?= e($lang) ?>">
      <input type="hidden" name="phone_country" value="<?= e($phoneCountry) ?>">
      <input type="hidden" name="only_countries" value='<?= e(json_encode($allowedCountries)) ?>'>
      <input type="hidden" name="subid" value="<?= e(function_exists('offer_click_id') ? offer_click_id() : '') ?>">
      <input type="hidden" name="form_token" value="" autocomplete="off">
      <div class="form-preloader hidden" aria-hidden="true"><span class="ft-spinner"></span></div>
      <div class="ft-fields">
        <div class="field ft-field">
          <label for="<?= e($formId) ?>-first_name"><?= e($h['first_name']) ?></label>
          <input id="<?= e($formId) ?>-first_name" name="first_name" type="text" required autocomplete="given-name" placeholder="<?= e($h['first_name']) ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field ft-field">
          <label for="<?= e($formId) ?>-last_name"><?= e($h['last_name']) ?></label>
          <input id="<?= e($formId) ?>-last_name" name="last_name" type="text" required autocomplete="family-name" placeholder="<?= e($h['last_name']) ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field ft-field">
          <label for="<?= e($formId) ?>-email"><?= e($h['email']) ?></label>
          <input id="<?= e($formId) ?>-email" name="email" type="email" required autocomplete="email" placeholder="<?= e($h['email']) ?>">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
        <div class="field ft-field">
          <label for="<?= e($formId) ?>-phone"><?= e($h['phone']) ?></label>
          <input id="<?= e($formId) ?>-phone" name="fullphone" type="tel" required autocomplete="tel" class="js-phone-input">
          <span class="field-error hide" role="alert" aria-live="polite"></span>
        </div>
      </div>
      <div class="honeypot" aria-hidden="true">
        <input type="text" name="hp_trap" tabindex="-1" autocomplete="off" data-honeypot readonly>
        <input type="text" name="hp_trap2" tabindex="-1" autocomplete="off" data-honeypot readonly>
      </div>
      <button class="ft-cta ft-cta--block" type="submit"><?= e($h['submit']) ?></button>
      <p class="ft-ledger__legal"><?= $legalHtml ?></p>
      <div class="form-msg hidden" data-form-message role="status">
        <span data-form-message-content></span>
      </div>
      <p class="ft-ledger__rails" aria-label="Payment methods">
        <span>Visa</span>
        <span>Mastercard</span>
        <span>PayPal</span>
        <span><?= e($h['secure']) ?></span>
      </p>
    </div>
  </form>
</div>
