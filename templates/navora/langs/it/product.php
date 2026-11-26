<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'La piattaforma';
$page_canonical = page_url('product.php');
$active_page = 'product';
$page_description = SITE_NAME.' — ferramentas de IA para acompanhar ações, forex e criptoativos.';
$subpage_lead = SITE_NAME.' brings charts, market data and alerts into one dashboard. Decisions and capital stay with you.';
$subpage_body = '<p>After you open the account you can view markets, set risk limits and follow positions. Nothing is applied in your name without your confirmation.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Apri un conto</a></p>';
require __DIR__.'/includes/subpage.php';
