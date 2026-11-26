<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Pedido recebido';
$page_canonical = page_url('Thanks.php');
$active_page = 'Thanks';
$page_noindex = true;
$page_description = 'O pedido na '.SITE_NAME.' foi recebido.';
$subpage_lead = 'Obrigado. A equipa da '.e(SITE_NAME).' vai contactá-lo em breve — tenha o telefone por perto.';
$subpage_body = '<p><a href="'.e(page_url()).'">Voltar ao início</a></p>';
require __DIR__.'/includes/subpage.php';
