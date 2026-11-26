<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'A plataforma';
$page_canonical = page_url('product.php');
$active_page = 'product';
$page_description = SITE_NAME.' — ferramentas de IA para acompanhar ações, forex e criptoativos.';
$subpage_lead = SITE_NAME.' reúne gráficos, dados de mercado e alertas num único painel. A decisão e o capital permanecem consigo.';
$subpage_body = '<p>Depois de criar a conta, pode consultar mercados, definir limites de risco e acompanhar posições. Nenhum montante é aplicado em seu nome sem a sua confirmação.</p><p><a href="'.htmlspecialchars(page_url('sign.php'), ENT_QUOTES, 'UTF-8').'">Abrir uma conta</a></p>';
require __DIR__.'/includes/subpage.php';
