<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Começar a partir de '.min_deposit_label();
$page_canonical = page_url('offer.php');
$active_page = 'offer';
$page_description = 'Depósito mínimo '.min_deposit_label().' na '.SITE_NAME.'. Sem promessa de retorno.';
$subpage_lead = 'Pode conhecer as ferramentas a partir de '.e(min_deposit_label()).'. Esse montante não é aconselhamento nem garantia de valorização.';
$subpage_body = '<p>Utilize apenas fundos compatíveis com a sua situação. Os mercados oscilam e o capital pode perder valor.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Enviar pedido</a></p>';
require __DIR__.'/includes/subpage.php';
