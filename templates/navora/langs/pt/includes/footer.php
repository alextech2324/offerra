<footer class="footer_component">
  <div class="padding-global">
    <div class="container-large">
      <div class="padding-vertical padding-xxlarge" style="padding-bottom:2rem!important">
        <div class="padding-bottom padding-xxlarge">
          <div class="w-layout-grid footer_top-wrapper">
            <div class="w-layout-grid footer_menu-wrapper" style="display:flex;flex-direction:column;align-items:flex-start">
              <div class="footer_link-list" style="display:flex;flex-direction:column;gap:10px">
                <div>
                  <a class="navbar_link w-nav-link footer-logo-link" href="<?= page_url() ?>" style="background-color:transparent">
                    <img alt="<?= e(SITE_NAME) ?>" class="navbar1_logo" loading="lazy" src="<?= asset('static/images/favicon.svg') ?>" style="width:50px;margin:5px">
                    <span class="heading-style-h4" style="font-weight:700;color:white"><?= e(SITE_NAME) ?></span>
                  </a>
                </div>
                <div class="footer-list-wrapper">
                  <ul class="footer-list-first">
                    <li><a class="navbar_link" href="<?= page_url() ?>#why-invest" style="background-color:transparent"><span class="footer-link">Porquê investir</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#how-it-works" style="background-color:transparent"><span class="footer-link">Como começar</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#minimization" style="background-color:transparent"><span class="footer-link">Riscos do investimento</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#Beneficios" style="background-color:transparent"><span class="footer-link">Vantagens</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('faq.php') ?>" style="background-color:transparent"><span class="footer-link">Dúvidas frequentes</span></a></li>
                  </ul>
                  <ul class="footer-list-first">
                    <li><a class="navbar_link" href="<?= page_url('about.php') ?>" style="background-color:transparent"><span class="footer-link">Sobre nós</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('contacts.php') ?>" style="background-color:transparent"><span class="footer-link">Contacte-nos</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('privacy.php') ?>" style="background-color:transparent"><span class="footer-link">Aviso de privacidade</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('conditions.php') ?>" style="background-color:transparent"><span class="footer-link">Termos de utilização</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('sign.php') ?>" style="background-color:transparent"><span class="footer-link">Abrir conta</span></a></li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="footer_left-wrapper footer-flex">
              <div class="footer-list-right">
                <span class="heading-style-h4">Informações de contacto</span>
                <span class="footer-link">E-mail: <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></span>
              </div>
            </div>
          </div>
          <p style="margin-top:40px"><?= e(SITE_NAME) ?> disponibiliza uma plataforma de trading online com registo claro e ferramentas para analisar os mercados. Quer esteja a começar ou já tenha experiência, pode acompanhar a informação de forma mais simples, sem qualquer promessa de resultados. A proteção dos dados e a compreensão dos riscos são prioridades. Ao utilizar <?= e(SITE_NAME) ?>, encontra recursos de acompanhamento — não rendimentos automáticos.</p>
          <p class="footer-copyright">© <?= e(SITE_NAME) ?> <?= date('Y') ?></p>
        </div>
      </div>
    </div>
  </div>
</footer>
</div>
<?php if (function_exists('offer_vitals_pixel')) { offer_vitals_pixel(); } ?>
<script>
window.APP_LANG = {
  valPhoneInvalid: <?= json_encode('Insira um telefone válido', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneCountry: <?= json_encode('Código de país inválido', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneShort: <?= json_encode('O número é demasiado curto', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneLong: <?= json_encode('O número é demasiado longo', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneRequired: <?= json_encode('Insira o número de telefone', JSON_UNESCAPED_UNICODE) ?>,
  valSessionExpired: <?= json_encode('Sessão expirada. Recarregue a página e tente novamente.', JSON_UNESCAPED_UNICODE) ?>,
  valGenericError: <?= json_encode('Algo correu mal. Tente novamente mais tarde.', JSON_UNESCAPED_UNICODE) ?>,
  valConnectionError: <?= json_encode('Erro de ligação. Verifique a internet e tente novamente.', JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= asset('static/js/jquery-3.5.1.min.dc5e7f18c8.js') ?>" defer></script>
<script src="<?= asset('static/js/wf-runtime.js') ?>" defer></script>
<script src="<?= asset('static/js/script.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/intlTelInput.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/utils.js"></script>
<script src="<?= asset_version('integration/validation.js') ?>"></script>
<script>
document.addEventListener('click', function (e) {
  const question = e.target.closest('.faq_question');
  if (!question) return;
  const accordion = question.closest('.faq_accordion');
  document.querySelectorAll('.faq_accordion.active').forEach(function (item) {
    if (item !== accordion) item.classList.remove('active');
  });
  accordion.classList.toggle('active');
});
</script>
<?php if (function_exists('offer_vitals_script')) { offer_vitals_script(); } ?>
</body>
</html>
