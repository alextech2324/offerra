<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Comece a explorar '.SITE_NAME.' de forma simples e rápida';
$page_canonical = page_url('sign.php');
$active_page = 'sign';
$page_title = page_title('Crie a sua conta na '.SITE_NAME);
$page_description = 'Crie a conta na '.SITE_NAME.' em poucos passos.';
$heading_class = 'heading-style-h2';
$subpage_narrow = true;
$subpage_form = true;
$form_id = 'sign-form';
require __DIR__.'/includes/subpage.php';
