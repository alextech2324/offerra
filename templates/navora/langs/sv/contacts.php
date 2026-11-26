<?php
require_once __DIR__.'/includes/config.php';
$page_title = page_title('Contact the support team');
$page_description = 'Contact '.SITE_NAME.'.';
$page_canonical = page_url('contacts.php');
$active_page = 'contacts';
require __DIR__.'/includes/head.php';
require __DIR__.'/includes/header.php';
?>
<main class="main-wrapper">
  <section class="section_content">
    <div class="padding-global">
      <div class="container-large">
        <div class="padding-vertical padding-xxlarge">
          <h1 id="heading-style-h1" class="heading-style-h1" style="margin-bottom: 16px;">Contact our team</h1>
          <p class="text-size-large" style="margin-bottom: 24px;">The support team at <?= e(SITE_NAME) ?> is ready to answer your questions and follow each request carefully and promptly. If you need clarifications, have a registered account or already use the platform, we review your case individually so we can give the most suitable guidance.</p>
          <div class="inner-contact-grid">
            <div class="inner-panel">
              <h2 class="heading-style-h4">Kontakta oss</h2>
              <p class="text-size-medium">Our team provides useful, prompt support on account topics and on using <?= e(SITE_NAME) ?>. The service is available Monday to Friday in business hours (CET), and requests are handled according to their priority. The most common topics include:</p>
              <ul>
                <li class="text-size-medium">Opening, verifying and managing the account, including profile settings and risk controls</li>
                <li class="text-size-medium">Technical support for the platform or app, from errors to difficulties using it</li>
                <li class="text-size-medium">Information on deposits, withdrawals, processing times and available payment methods</li>
                <li class="text-size-medium">Guidance on features, approaches, AI solutions and accessible trading instruments</li>
                <li class="text-size-medium">Comments, ideas and recommendations to help the platform evolve</li>
                <li class="text-size-medium">Help with other matters, whether general questions or more specific requests</li>
              </ul>
              <h2 class="heading-style-h4" style="margin-top: 24px;">Kontaktuppgifter</h2>
              <p class="text-size-medium">We want the experience on <?= e(SITE_NAME) ?> to be objective, accessible and safe. We give priority to clear communication, transparent information and support aimed at solutions. You can contact us through the form, by email and, when available, by phone. The security of your data and the quality of the service sit at the centre of our support.</p>
              <p class="text-size-medium">E-mail: <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></p>
            </div>
            <div class="inner-panel">
              <h2 class="heading-style-h4">Submit a request</h2>
              <p class="text-size-medium" style="margin-bottom: 16px;">Use the secure form below and fill in every requested field so we can review the situation and reply accurately. On business days we aim to reply within 24 hours, often sooner.</p>
              <?php
                $form_id = 'contact-form';
                $form_class = 'leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form';
                $form_submit = 'Skicka';
                require __DIR__.'/includes/form.php';
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__.'/includes/footer.php'; ?>
