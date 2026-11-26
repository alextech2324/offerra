<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Start from '.min_deposit_label();
$page_canonical = page_url('offer.php');
$active_page = 'offer';
$page_description = 'Minimum deposit '.min_deposit_label().' at '.SITE_NAME.'. No promised return.';
$subpage_lead = 'You can try the tools from '.e(min_deposit_label()).'. That amount is not advice or a guarantee of growth.';
$subpage_body = '<p>Use only funds you can afford. Markets move and capital can lose value.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Pošalji zahtev</a></p>';
require __DIR__.'/includes/subpage.php';
