<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Česta pitanja';
$page_canonical = page_url('faq.php');
$active_page = 'faq';
$page_description = 'Frequently asked questions about '.SITE_NAME.'.';
$subpage_lead = 'If your question is not here, send the form — a manager will cover it on the call.';
$subpage_body = '<h2>How much do I need to start?</h2><p>You can start from '.e(min_deposit_label()).'. The stated minimum is not an investment recommendation.</p><h2>Does the platform promise a return?</h2><p>No. '.e(SITE_NAME).' provides AI-assisted analysis tools. There are no daily profits or predetermined results.</p><h2>How do I get help?</h2><p>Send the request on the <a href="'.htmlspecialchars(page_url('contacts.php'), ENT_QUOTES, 'UTF-8').'">contact page</a>. The team explains account procedures, not personal advice.</p>';
require __DIR__.'/includes/subpage.php';
