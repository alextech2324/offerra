<?php
$nav_home = page_url();
?>
<div class="page-wrapper">
<header class="navbar_component w-nav" data-animation="default" data-collapse="medium" data-duration="400" data-easing="ease" data-easing2="ease" role="banner">
  <div class="navbar_container">
    <div class="navbar_logo-wrapper">
      <a href="<?= e($nav_home) ?>">
        <img alt="<?= e(SITE_NAME) ?>" src="<?= asset('static/images/favicon.svg') ?>" width="25" height="25" style="width:25px;height:auto;margin:5px">
        <span style="color:black;font-weight:700;font-size:15px"><?= e(SITE_NAME) ?></span>
      </a>
    </div>
    <nav class="navbar_menu is-page-height-tablet w-nav-menu" role="navigation" aria-label="Menu">
      <div class="navbar_menu-links">
        <a class="navbar_link w-nav-link" href="<?= e($nav_home) ?>#why-invest">Mengapa berinvestasi</a>
        <a class="navbar_link w-nav-link" href="<?= e($nav_home) ?>#how-it-works">Cara mulai</a>
        <a class="navbar_link w-nav-link" href="<?= page_url('about.php') ?>">Tentang kami</a>
        <a class="navbar_link w-nav-link" href="<?= e($nav_home) ?>#minimization">Risiko</a>
        <a class="navbar_link w-nav-link" href="<?= e($nav_home) ?>#Beneficios">Manfaat</a>
        <a class="navbar_link w-nav-link" href="<?= page_url('faq.php') ?>">FAQ</a>
      </div>
    </nav>
    <div class="navbar_menu-buttons">
      <a class="button w-button" href="<?= page_url('sign.php') ?>" style="background:#111827;color:#ffffff;border:1px solid #374151;border-radius:10px;padding:12px 24px;font-weight:600">Masuk</a>
      <a class="button w-button" href="<?= page_url('sign.php') ?>" style="background:#6D28D9;color:#ffffff;border:1px solid #6D28D9;border-radius:10px;padding:12px 24px;font-weight:600">Buka akun</a>
    </div>
    <div aria-label="menu" class="navbar_menu-button w-nav-button" role="button" tabindex="0">
      <div class="menu-icon1">
        <div class="menu-icon1_line-top"></div>
        <div class="menu-icon1_line-middle"><div class="menu-icon1_line-middle-inner"></div></div>
        <div class="menu-icon1_line-bottom"></div>
      </div>
    </div>
  </div>
  <div class="w-nav-overlay" data-wf-ignore=""></div>
</header>
