<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Sayfa bulunamadı';
$page_canonical = page_url('404.php');
$active_page = '404';
$page_noindex = true;
$subpage_lead = 'That address does not exist.';
$subpage_body = '<p><a href="'.e(page_url()).'">Ana sayfaya dön</a></p>';
require __DIR__.'/includes/subpage.php';
