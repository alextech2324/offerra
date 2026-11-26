<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = '口座を開く';
$page_canonical = page_url('sign.php');
$active_page = 'sign';
$page_description = 'Create the account at '.SITE_NAME.' in a few steps.';
$subpage_lead = 'Fill in the details. A manager will contact you for the next steps.';
$subpage_form = true;
$form_id = 'sign-form';
require __DIR__.'/includes/subpage.php';
