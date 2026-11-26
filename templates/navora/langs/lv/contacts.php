<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Sazinieties';
$page_canonical = page_url('contacts.php');
$active_page = 'contacts';
$page_description = 'Contact '.SITE_NAME.'.';
$subpage_lead = 'Write to us at <a href="mailto:'.e(SUPPORT_EMAIL).'">'.e(SUPPORT_EMAIL).'</a> or send the form. A manager will call you.';
$subpage_form = true;
$form_id = 'contact-form';
require __DIR__.'/includes/subpage.php';
