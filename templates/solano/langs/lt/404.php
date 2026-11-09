<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Puslapis nerastas ᐉ ' . SITE_NAME;
$page_description = 'Puslapis nerastas – ' . SITE_NAME;
$page_canonical = page_url("404.php");
$active_page = "404";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main id="top">
<section class="kj9w4x">
  <div class="ggh3sm">
    <span class="qwce6q">404 klaida</span>
    <h1>Puslapis nerastas</h1>
    <p class="kpnq92g">Tos nuorodos nėra.<a href="<?= page_url() ?>">Grįžti į namus</a>.</p>
    <div class="tw9z4by">
      <a class="qou73xg fi3abjs" href="<?= page_url() ?>">Pagrindinis</a>
      <a class="qou73xg ec2hno" href="<?= page_url('sign.php') ?>">Atidarykite paskyrą</a>
    </div>
  </div>
</section>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
