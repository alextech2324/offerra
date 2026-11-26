<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'O nama';
$page_canonical = page_url('about.php');
$active_page = 'about';
$page_description = 'Quem é a '.SITE_NAME.'.';
$subpage_lead = e(SITE_NAME).' é uma plataforma web para acompanhar ações, forex e criptoativos com ferramentas de análise assistida por IA.';
$subpage_body = '<p>Registration is free. The stated minimum deposit is '.e(min_deposit_label()).'. There is no promised return. The decision stays with you.</p>';
require __DIR__.'/includes/subpage.php';
