<?php
require_once __DIR__.'/config.php';

$form_id = $form_id ?? 'lead-form';
$form_class = $form_class ?? 'leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form';
$form_submit = $form_submit ?? 'Send';
$phone_country = form_visitor_phone_country();
$allowed_countries = form_allowed_countries();
$lead_cookie = site_slug().'_lead';
?>
<form
  id="<?= e($form_id) ?>"
  class="<?= e($form_class) ?>"
  name="form"
  method="post"
  action="<?= asset('integration/send.php') ?>"
  dir="ltr"
  data-form
  data-leadform
  data-lead-cookie="<?= e($lead_cookie) ?>"
  data-cookie-days="<?= (int) FORM_LEAD_COOKIE_DAYS ?>"
>
  <div class="form-already-registered hidden" data-already-registered>
    <p class="form-already-registered__title">Anmodning allerede modtaget</p>
    <p class="form-already-registered__text">
      Din anmodning hos <?= e(SITE_NAME) ?> was received. Please wait for the manager’s call.
    </p>
  </div>

  <div data-form-fields>
    <input type="hidden" name="language" value="<?= e(SITE_LANG) ?>">
    <input type="hidden" name="phone_country" value="<?= e($phone_country) ?>">
    <input type="hidden" name="only_countries" value='<?= e(json_encode($allowed_countries)) ?>'>
    <input type="hidden" name="subid" value="<?= e(function_exists('offer_click_id') ? offer_click_id() : '') ?>">
    <input type="hidden" name="form_token" value="" autocomplete="off">

    <div class="form-preloader hidden" aria-hidden="true">
      <svg width="50" height="50" class="spinner" viewBox="0 0 50 50"><circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle></svg>
    </div>

    <div class="block-center__wrapper">
      <div class="form-group input-wrap">
        <label for="<?= e($form_id) ?>-first">Fornavn</label>
        <input
          placeholder="Fornavn"
          name="first_name"
          required
          type="text"
          class="form-control w-input regFormFirstName"
          minlength="2"
          maxlength="50"
          inputmode="text"
          autocapitalize="words"
          spellcheck="false"
          id="<?= e($form_id) ?>-first"
          autocomplete="given-name"
        >
        <span class="field-error hide" role="alert" aria-live="polite"></span>
      </div>
      <div class="form-group input-wrap">
        <label for="<?= e($form_id) ?>-last">Efternavn</label>
        <input
          placeholder="Efternavn"
          name="last_name"
          required
          type="text"
          class="form-control w-input regFormLastNname"
          minlength="2"
          maxlength="50"
          inputmode="text"
          autocapitalize="words"
          spellcheck="false"
          id="<?= e($form_id) ?>-last"
          autocomplete="family-name"
        >
        <span class="field-error hide" role="alert" aria-live="polite"></span>
      </div>
      <div class="form-group form-group__emailValid input-wrap">
        <label for="<?= e($form_id) ?>-email">E-mail</label>
        <input
          placeholder="E-mail"
          name="email"
          required
          type="email"
          class="form-control w-input regFormEmail"
          id="<?= e($form_id) ?>-email"
          autocomplete="email"
          inputmode="email"
        >
        <span class="field-error hide" role="alert" aria-live="polite"></span>
      </div>
      <div class="form-group reg-phone input-wrap lead-form-field lead-form-field--phone">
        <label for="<?= e($form_id) ?>-phone">Telefonnummer</label>
        <input
          name="phone"
          required
          type="tel"
          class="form-control w-input regFormPhoneNumber"
          id="<?= e($form_id) ?>-phone"
          autocomplete="tel"
        >
        <span class="field-error hide error-msg" role="alert" aria-live="polite"></span>
      </div>
      <div class="btn-holder js-buttons form-group input-wrap">
        <button class="zoom regFormButton button btn_send submit" type="submit"><?= e($form_submit) ?></button>
      </div>
      <div class="form-message hidden" data-form-message role="alert">
        <p class="form-message-title" data-form-message-title></p>
        <div data-form-message-content></div>
      </div>
    </div>
  </div>
</form>
<?php
unset($form_id, $form_class, $form_submit);
