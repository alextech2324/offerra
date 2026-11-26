<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Kérés megérkezett';
$page_canonical = page_url('Thanks.php');
$active_page = 'Thanks';
$page_noindex = true;
$page_description = 'The request at '.SITE_NAME.' was received.';
$subpage_lead = 'Thank you. The '.e(SITE_NAME).' team will contact you shortly — keep your phone nearby.';
$subpage_body = '<p><a href="'.e(page_url()).'">Vissza a főoldalra</a></p>';
require __DIR__.'/includes/subpage.php';
