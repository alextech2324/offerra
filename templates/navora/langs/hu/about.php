<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Rólunk';
$page_canonical = page_url('about.php');
$active_page = 'about';
$page_description = 'Who is '.SITE_NAME.'.';
$subpage_lead = e(SITE_NAME).' is a web platform for following stocks, forex and crypto with AI-assisted analysis tools.';
$subpage_body = '<p>Registration is free. The stated minimum deposit is '.e(min_deposit_label()).'. There is no promised return. The decision stays with you.</p>';
require __DIR__.'/includes/subpage.php';
