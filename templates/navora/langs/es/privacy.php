<?php
require_once __DIR__.'/includes/config.php';
$subpage_heading = 'Aviso de privacidad';
$page_canonical = page_url('privacy.php');
$active_page = 'privacy';
$page_description = 'Como a '.SITE_NAME.' trata dados pessoais.';
$subpage_lead = 'A '.e(SITE_NAME).' trata os dados do formulário para contactar o pedido e cumprir obrigações legais.';
$subpage_body = '<h2>1. Dados que recolhemos</h2><p>Nombre, e-mail, telefone, idioma, país do formulário, endereço IP e dados técnicos do navegador.</p><h2>2. Utilização</h2><p>To process the request, verify the contact, prevent abuse and talk to you about the account.</p><h2>3. Partilha</h2><p>Podemos partilhar dados com o processador de leads, o apoio e autoridades quando a lei o exigir. We do not sell personal data.</p><h2>4. Conservação</h2><p>We keep data as long as needed for the request and legal duties.</p><h2>5. Os seus direitos</h2><p>Pode pedir acesso, correção ou eliminação através de <a href="mailto:'.e(SUPPORT_EMAIL).'">'.e(SUPPORT_EMAIL).'</a>.</p><h2>6. Contacto</h2><p><a href="'.e(page_url('contacts.php')).'">Contact page</a>.</p>';
require __DIR__.'/includes/subpage.php';
