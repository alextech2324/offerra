<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Conditions d'utilisation';
$page_canonical = page_url('conditions.php');
$active_page = 'conditions';
$page_description = 'Conditions d'utilisation da '.SITE_NAME.'.';
$subpage_lead = 'By using this site and the form, you accept these terms.';
$subpage_body = '<h2>1. Serviço</h2><p>A '.e(SITE_NAME).' apresenta ferramentas de acompanhamento de mercados. Não é um banco, não presta aconselhamento de investimento personalizado e não promete rendimento.</p><h2>2. Risco</h2><p>Trading can lose capital. Use only an amount that fits your situation.</p><h2>3. Conta</h2><p>Os dados do formulário devem ser verdadeiros. Podemos recusar o acesso se os critérios não forem cumpridos.</p><h2>4. Depósitos e levantamentos</h2><p>Métodos, prazos e custos são apresentados antes da confirmação. Depósitos bloqueados ou cobranças extra para libertar um levantamento são sinais de alerta — confirme sempre no domínio oficial.</p><h2>5. Alterações</h2><p>We may update these terms. The version on this page prevails.</p><h2>6. Contacto</h2><p><a href="'.e(page_url('contacts.php')).'">Nous contacter</a>.</p>';
require __DIR__.'/includes/subpage.php';
