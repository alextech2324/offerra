<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Abrir uma conta';
$page_canonical = page_url('sign.php');
$active_page = 'sign';
$page_description = 'Crie a conta na '.SITE_NAME.' em poucos passos.';
$subpage_lead = 'Preencha os dados. Um gestor entra em contacto para os próximos passos.';
$subpage_form = true;
$form_id = 'sign-form';
require __DIR__.'/includes/subpage.php';
