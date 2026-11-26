<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Sobre nós';
$page_canonical = page_url('about.php');
$active_page = 'about';
$page_description = 'Quem é a '.SITE_NAME.'.';
$subpage_lead = e(SITE_NAME).' é uma plataforma web para acompanhar ações, forex e criptoativos com ferramentas de análise assistida por IA.';
$subpage_body = '<p>O registo é gratuito. O depósito mínimo indicado é de '.e(min_deposit_label()).'. Não há promessa de retorno. A decisão continua consigo.</p>';
require __DIR__.'/includes/subpage.php';
