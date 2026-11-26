<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Página não encontrada';
$page_canonical = page_url('404.php');
$active_page = '404';
$page_noindex = true;
$subpage_lead = 'Esse endereço não existe.';
$subpage_body = '<p><a href="'.e(page_url()).'">Voltar ao início</a></p>';
require __DIR__.'/includes/subpage.php';
