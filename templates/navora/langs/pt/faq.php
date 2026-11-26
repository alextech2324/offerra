<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Dúvidas frequentes';
$page_canonical = page_url('faq.php');
$active_page = 'faq';
$page_description = 'Perguntas frequentes sobre a '.SITE_NAME.'.';
$subpage_lead = 'Se a sua questão não estiver aqui, envie o formulário — um gestor esclarece no contacto.';
$subpage_body = '<h2>Que montante preciso para começar?</h2><p>Pode começar a partir de '.e(min_deposit_label()).'. O mínimo indicado não é uma recomendação de investimento.</p><h2>A plataforma promete rendimento?</h2><p>Não. A '.e(SITE_NAME).' disponibiliza ferramentas de análise com apoio de IA. Não há lucros diários nem resultados determinados.</p><h2>Como obtenho ajuda?</h2><p>Envie o pedido na <a href="'.htmlspecialchars(page_url('contacts.php'), ENT_QUOTES, 'UTF-8').'">página de contacto</a>. A equipa esclarece procedimentos da conta, não aconselhamento personalizado.</p>';
require __DIR__.'/includes/subpage.php';
