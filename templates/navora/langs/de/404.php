<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Seite nicht gefunden';
$page_canonical = page_url('404.php');
$active_page = '404';
$page_noindex = true;
$subpage_lead = 'That address does not exist.';
$subpage_body = '<p><a href="'.e(page_url()).'">Zurück zur Startseite</a></p>';
require __DIR__.'/includes/subpage.php';
