<?php
require_once __DIR__.'/config.php';
$page_title = $page_title ?? page_title((string) ($subpage_heading ?? SITE_NAME));
$page_description = $page_description ?? ($subpage_heading ?? SITE_NAME).' — '.SITE_NAME;
$page_canonical = $page_canonical ?? page_url();
$active_page = $active_page ?? '';
$page_noindex = $page_noindex ?? false;
require __DIR__.'/head.php';
require __DIR__.'/header.php';
?>
<main class="main-wrapper">
  <section class="legal-page">
    <h1><?= e($subpage_heading ?? SITE_NAME) ?></h1>
    <?php if (! empty($subpage_lead)): ?>
      <p><?= $subpage_lead ?></p>
    <?php endif; ?>
    <?= $subpage_body ?? '' ?>
    <?php if (! empty($subpage_form)): ?>
      <?php
        $form_id = $form_id ?? 'subpage-form';
        $form_class = $form_class ?? 'leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form form-pc';
        $form_submit = $form_submit ?? 'Send';
        require __DIR__.'/form.php';
      ?>
    <?php endif; ?>
  </section>
</main>
<?php require __DIR__.'/footer.php'; ?>
