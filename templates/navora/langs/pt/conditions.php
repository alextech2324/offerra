<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Termos de utilização';
$page_canonical = page_url('conditions.php');
$active_page = 'conditions';
$page_description = 'Termos de utilização da '.SITE_NAME.'.';
$subpage_lead = 'Ao utilizar este site e o formulário, aceita estes termos.';
$subpage_body = '<h2>1. Serviço</h2><p>A '.e(SITE_NAME).' apresenta ferramentas de acompanhamento de mercados. Não é um banco, não presta aconselhamento de investimento personalizado e não promete rendimento.</p><h2>2. Risco</h2><p>Trading envolve perda de capital. Utilize apenas um montante adequado à sua situação.</p><h2>3. Conta</h2><p>Os dados do formulário devem ser verdadeiros. Podemos recusar o acesso se os critérios não forem cumpridos.</p><h2>4. Depósitos e levantamentos</h2><p>Métodos, prazos e custos são apresentados antes da confirmação. Depósitos bloqueados ou cobranças extra para libertar um levantamento são sinais de alerta — confirme sempre no domínio oficial.</p><h2>5. Alterações</h2><p>Podemos atualizar estes termos. A versão nesta página prevalece.</p><h2>6. Contacto</h2><p><a href="'.e(page_url('contacts.php')).'">Contacte-nos</a>.</p>';
require __DIR__.'/includes/subpage.php';
