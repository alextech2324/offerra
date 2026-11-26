<?php
require_once __DIR__.'/config.php';
$page_title = $page_title ?? page_title((string) ($subpage_heading ?? SITE_NAME));
$page_description = $page_description ?? ($subpage_heading ?? SITE_NAME).' — '.SITE_NAME;
$page_canonical = $page_canonical ?? page_url();
$active_page = $active_page ?? '';
$page_noindex = $page_noindex ?? false;
$heading_class = $heading_class ?? 'heading-style-h1';
$narrow_style = ! empty($subpage_narrow) ? ' style="max-width: 640px; margin: 0 auto;"' : '';
require __DIR__.'/head.php';
require __DIR__.'/header.php';
?>
<main class="main-wrapper">
  <section class="section_content">
    <div class="padding-global">
      <div class="container-large">
        <div class="padding-vertical padding-xxlarge"<?= $narrow_style ?>>
          <h1 id="heading-style-h1" class="<?= e($heading_class) ?>" style="margin-bottom: 24px;<?= ! empty($subpage_narrow) ? ' text-align: center;' : '' ?>"><?= e($subpage_heading ?? SITE_NAME) ?></h1>
          <?php if (! empty($subpage_image)): ?>
            <img src="<?= e($subpage_image) ?>" alt="<?= e($subpage_image_alt ?? ($subpage_heading ?? SITE_NAME)) ?>" loading="lazy" style="max-width: 100%; height: auto; border-radius: 12px; margin-bottom: 24px;">
          <?php endif; ?>
          <?php if (! empty($subpage_lead)): ?>
            <p class="text-size-large" style="margin-bottom: 24px;"><?= $subpage_lead ?></p>
          <?php endif; ?>
          <?php if (! empty($subpage_body)): ?>
            <div class="<?= e($subpage_body_class ?? 'legal-content') ?>"><?= $subpage_body ?></div>
          <?php endif; ?>
          <?php if (! empty($subpage_form)): ?>
            <div class="inner-panel" style="margin-top: <?= empty($subpage_body) && empty($subpage_lead) ? '0' : '8px' ?>;">
              <?php
                $form_id = $form_id ?? 'subpage-form';
                $form_class = $form_class ?? 'leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form';
                $form_submit = $form_submit ?? 'Hantar';
                require __DIR__.'/form.php';
              ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__.'/footer.php'; ?>
