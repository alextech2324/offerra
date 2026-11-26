<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'プライバシー';
$page_canonical = page_url('privacy.php');
$active_page = 'privacy';
$page_description = 'How '.SITE_NAME.' handles personal data.';
$subpage_lead = e(SITE_NAME).' processes form data to handle the request and meet legal duties.';
$subpage_body = '<h2>1. Data we collect</h2><p>Name, email, phone, language, form country, IP address and browser data.</p><h2>2. Use</h2><p>To process the request, verify the contact, prevent abuse and talk to you about the account.</p><h2>3. Sharing</h2><p>We may share data with the lead processor, support and authorities when the law requires it. We do not sell personal data.</p><h2>4. Retention</h2><p>We keep data as long as needed for the request and legal duties.</p><h2>5. Your rights</h2><p>You can request access, correction or deletion via <a href="mailto:'.e(SUPPORT_EMAIL).'">'.e(SUPPORT_EMAIL).'</a>.</p><h2>6. Contact</h2><p><a href="'.e(page_url('contacts.php')).'">Contact page</a>.</p>';
require __DIR__.'/includes/subpage.php';
