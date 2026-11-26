<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Start exploring '.SITE_NAME.' in a simple and fast way';
$page_canonical = page_url('sign.php');
$active_page = 'sign';
$page_title = page_title('Create your account on '.SITE_NAME);
$page_description = 'Create the account at '.SITE_NAME.' in a few steps.';
$heading_class = 'heading-style-h2';
$subpage_narrow = true;
$subpage_form = true;
$form_id = 'sign-form';
require __DIR__.'/includes/subpage.php';
