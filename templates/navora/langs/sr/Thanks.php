<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Zahtev primljen';
$page_canonical = page_url('Thanks.php');
$active_page = 'Thanks';
$page_noindex = true;
$page_description = 'The request at '.SITE_NAME.' was received.';
$subpage_lead = 'Thank you. The '.e(SITE_NAME).' team will contact you shortly — keep your phone nearby.';
$subpage_body = '<p><a href="'.e(page_url()).'">Nazad na početnu</a></p>';
require __DIR__.'/includes/subpage.php';
