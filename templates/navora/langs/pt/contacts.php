<?php
require_once __DIR__.'/includes/config.php';
$page_title = page_title('Contacte a equipa de suporte');
$page_description = 'Contacto '.SITE_NAME.'.';
$page_canonical = page_url('contacts.php');
$active_page = 'contacts';
require __DIR__.'/includes/head.php';
require __DIR__.'/includes/header.php';
?>
<main class="main-wrapper">
  <section class="section_content">
    <div class="padding-global">
      <div class="container-large">
        <div class="padding-vertical padding-xxlarge">
          <h1 id="heading-style-h1" class="heading-style-h1" style="margin-bottom: 16px;">Contacte a nossa equipa</h1>
          <p class="text-size-large" style="margin-bottom: 24px;">A equipa de suporte da <?= e(SITE_NAME) ?> está pronta para responder às suas questões e acompanhar cada pedido com cuidado e agilidade. Se procura esclarecimentos, tem uma conta registada ou já utiliza a plataforma, avaliamos o seu caso individualmente para lhe dar a orientação mais adequada.</p>
          <div class="inner-contact-grid">
            <div class="inner-panel">
              <h2 class="heading-style-h4">Contacte-nos</h2>
              <p class="text-size-medium">A nossa equipa presta apoio útil e célere em temas relacionados com a conta e com a utilização da <?= e(SITE_NAME) ?>. O serviço está disponível de segunda a sexta-feira, em horário laboral (CET), e os pedidos são tratados de acordo com o respetivo grau de prioridade. Os assuntos mais habituais incluem:</p>
              <ul>
                <li class="text-size-medium">Abertura, verificação e gestão da conta, incluindo definições do perfil e controlos de risco</li>
                <li class="text-size-medium">Suporte técnico para a plataforma ou aplicação, desde erros até dificuldades na utilização</li>
                <li class="text-size-medium">Informação sobre depósitos, levantamentos, prazos de processamento e métodos de pagamento disponíveis</li>
                <li class="text-size-medium">Orientação sobre funcionalidades, estratégias, soluções com IA e instrumentos de negociação acessíveis</li>
                <li class="text-size-medium">Comentários, ideias e recomendações para contribuir para a evolução da plataforma</li>
                <li class="text-size-medium">Apoio em outros assuntos, sejam questões gerais ou pedidos mais específicos</li>
              </ul>
              <h2 class="heading-style-h4" style="margin-top: 24px;">Informações de contacto</h2>
              <p class="text-size-medium">Queremos que a experiência na <?= e(SITE_NAME) ?> seja objetiva, acessível e segura. Damos prioridade a uma comunicação clara, a informação transparente e a um suporte orientado para soluções. Pode contactar-nos através do formulário, por e-mail e, quando disponível, por telefone. A segurança dos seus dados e a qualidade do serviço estão no centro do nosso atendimento.</p>
              <p class="text-size-medium">E-mail: <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></p>
            </div>
            <div class="inner-panel">
              <h2 class="heading-style-h4">Submeter pedido</h2>
              <p class="text-size-medium" style="margin-bottom: 16px;">Utilize o formulário seguro abaixo e preencha todos os campos pedidos, para que possamos analisar a situação e responder de forma precisa. Nos dias úteis, procuramos responder no prazo máximo de 24 horas, muitas vezes mais cedo.</p>
              <?php
                $form_id = 'contact-form';
                $form_class = 'leadform register-form bottom form-reg c-form registrationForm flex-column js-lead-form';
                $form_submit = 'Enviar';
                require __DIR__.'/includes/form.php';
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__.'/includes/footer.php'; ?>
