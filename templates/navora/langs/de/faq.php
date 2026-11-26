<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Häufige Fragen';
$page_canonical = page_url('faq.php');
$active_page = 'faq';
$page_description = 'Perguntas frequentes sobre a '.SITE_NAME.'.';
$subpage_lead = 'If your question is not here, send the form — a manager will cover it on the call.';
$subpage_body = '<h2>How much do I need to start?</h2><p>Pode começar a partir de '.e(min_deposit_label()).'. The stated minimum is not an investment recommendation.</p><h2>Die Plattform promete rendimento?</h2><p>Não. A '.e(SITE_NAME).' disponibiliza ferramentas de análise com apoio de IA. There are no daily profits or predetermined results.</p><h2>How do I get help?</h2><p>Envie o pedido na <a href="'.htmlspecialchars(page_url('contacts.php'), ENT_QUOTES, 'UTF-8').'">contact page</a>. A equipa esclarece procedimentos da conta, não aconselhamento personalizado.</p>';
require __DIR__.'/includes/subpage.php';
