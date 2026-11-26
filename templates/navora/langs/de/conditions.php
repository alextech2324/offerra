<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Nutzungsbedingungen';
$page_canonical = page_url('conditions.php');
$active_page = 'conditions';
$page_description = 'Terms of use for '.SITE_NAME.'.';
$subpage_lead = 'By using this site and the form, you accept these terms.';
$subpage_body = '<h2>1. Service</h2><p>'.e(SITE_NAME).' presents market-tracking tools. It is not a bank, it does not give personal investment advice and it does not promise a return.</p><h2>2. Risk</h2><p>Trading can lose capital. Use only an amount that fits your situation.</p><h2>3. Account</h2><p>Form details must be true. We may refuse access if the criteria are not met.</p><h2>4. Deposits and withdrawals</h2><p>Methods, timelines and fees are shown before confirmation. Blocked deposits or extra charges to release a withdrawal are warning signs — always confirm on the official domain.</p><h2>5. Changes</h2><p>We may update these terms. The version on this page prevails.</p><h2>6. Contact</h2><p><a href="'.e(page_url('contacts.php')).'">Kontakt</a>.</p>';
require __DIR__.'/includes/subpage.php';
