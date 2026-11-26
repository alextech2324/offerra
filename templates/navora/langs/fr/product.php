<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'The platform';
$page_canonical = page_url('product.php');
$active_page = 'product';
$page_description = SITE_NAME.' — AI tools to follow stocks, forex and crypto.';
$subpage_lead = SITE_NAME.' brings charts, market data and alerts into one dashboard. Decisions and capital stay with you.';
$subpage_body = '<p>After you open the account you can view markets, set risk limits and follow positions. Nothing is applied in your name without your confirmation.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Ouvrir un compte</a></p>';
require __DIR__.'/includes/subpage.php';
