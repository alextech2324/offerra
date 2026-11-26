<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Contacte-nos';
$page_canonical = page_url('contacts.php');
$active_page = 'contacts';
$page_description = 'Contacto '.SITE_NAME.'.';
$subpage_lead = 'Escreva-nos para <a href="mailto:'.e(SUPPORT_EMAIL).'">'.e(SUPPORT_EMAIL).'</a> ou envie o formulário. Um gestor liga-lhe.';
$subpage_form = true;
$form_id = 'contact-form';
require __DIR__.'/includes/subpage.php';
