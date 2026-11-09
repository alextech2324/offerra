<?php
require_once __DIR__ . '/includes/config.php';
$page_title = 'Ačiū ᐉ ' . SITE_NAME;
$page_description = 'Jūsų užklausą gavo ' . SITE_NAME . ' komanda.';
$page_canonical = page_url("Thanks.php");
$active_page = "Thanks";
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>
<main itemscope itemtype="https://schema.org/WebPage" id="yfln8q">
  <div class="ggh3sm" style="padding:80px 0">
    <div class="jfjcf">
      <div class="ibf0s54" aria-hidden="true">✓</div>
      <span class="vd7z9k">Gautas pranešimas</span>
      <h1>Ačiū - susisieksime</h1>
      <p>Jūsų užklausą gavo <?= e(SITE_NAME) ?> komanda. Netrukus susisieks specialistas, kuris padės jums pradėti. Tuo tarpu nedvejodami tyrinėkite platformą.</p>
      <div class="jv09m">
        <a class="qou73xg fi3abjs" href="<?= page_url() ?>">Grįžti į namus</a>
        <a class="qou73xg ec2hno" href="<?= page_url() ?>#sor9s">Ištirkite platformą</a>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
