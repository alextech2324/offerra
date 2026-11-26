<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Começar a partir de '.min_deposit_label();
$page_canonical = page_url('offer.php');
$active_page = 'offer';
$page_description = 'Depósito mínimo '.min_deposit_label().' na '.SITE_NAME.'. No promised return.';
$subpage_lead = 'Pode conhecer as ferramentas a partir de '.e(min_deposit_label()).'. Esse montante não é aconselhamento nem garantia de valorização.';
$subpage_body = '<p>Use only funds you can afford. Markets move and capital can lose value.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Gönder pedido</a></p>';
require __DIR__.'/includes/subpage.php';
