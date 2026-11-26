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
                    <li><a class="navbar_link" href="<?= page_url() ?>#why-invest" style="background-color:transparent"><span class="footer-link">なぜ投資するか</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#how-it-works" style="background-color:transparent"><span class="footer-link">始め方</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#minimization" style="background-color:transparent"><span class="footer-link">投資リスク</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url() ?>#Beneficios" style="background-color:transparent"><span class="footer-link">メリット</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('faq.php') ?>" style="background-color:transparent"><span class="footer-link">よくある質問</span></a></li>
                  </ul>
                  <ul class="footer-list-first">
                    <li><a class="navbar_link" href="<?= page_url('about.php') ?>" style="background-color:transparent"><span class="footer-link">私たちについて</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('contacts.php') ?>" style="background-color:transparent"><span class="footer-link">お問い合わせ</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('privacy.php') ?>" style="background-color:transparent"><span class="footer-link">プライバシー</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('conditions.php') ?>" style="background-color:transparent"><span class="footer-link">利用規約</span></a></li>
                    <li><a class="navbar_link" href="<?= page_url('sign.php') ?>" style="background-color:transparent"><span class="footer-link">口座開設</span></a></li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="footer_left-wrapper footer-flex">
              <div class="footer-list-right">
                <span class="heading-style-h4">連絡先</span>
                <span class="footer-link">E-mail: <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></span>
              </div>
            </div>
          </div>
          <p style="margin-top:40px"><?= e(SITE_NAME) ?> provides an online trading platform with a clear signup and tools to analyse the markets. Whether you are starting or already have experience, you can follow the information more simply, with no promised results. Data protection and understanding the risks are priorities. When you use <?= e(SITE_NAME) ?>, you find tracking resources — not automatic returns.</p>
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
  valPhoneInvalid: <?= json_encode('有効な電話番号を入力', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneCountry: <?= json_encode('国番号が無効です', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneShort: <?= json_encode('番号が短すぎます', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneLong: <?= json_encode('番号が長すぎます', JSON_UNESCAPED_UNICODE) ?>,
  valPhoneRequired: <?= json_encode('電話番号を入力', JSON_UNESCAPED_UNICODE) ?>,
  valSessionExpired: <?= json_encode('セッション切れです。再読み込みしてください。', JSON_UNESCAPED_UNICODE) ?>,
  valGenericError: <?= json_encode('問題が発生しました。後でもう一度お試しください。', JSON_UNESCAPED_UNICODE) ?>,
  valConnectionError: <?= json_encode('接続エラーです。インターネットを確認して再試行してください。', JSON_UNESCAPED_UNICODE) ?>
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
