<?php
require __DIR__ . '/../includes/header.php';
$o = $langData['offer'];
?>
<section class="bg-band">
  <div class="bg-wrap bg-split bg-split--form">
    <div>
      <p class="bg-kicker"><?= e($o['eyebrow']) ?></p>
      <h1><?= e($o['title']) ?></h1>
      <p class="bg-lede"><?= e($o['lead']) ?></p>
      <div class="bg-panel bg-panel--round bg-price">
        <div class="bg-price__chart">
          <p class="bg-kicker bg-kicker--light">BTC/USD</p>
          <?= bg_candle_chart('bg-chart') ?>
        </div>
        <div class="bg-price__copy">
          <p class="bg-kicker bg-kicker--light"><?= e($o['access']) ?></p>
          <p class="bg-muted"><?= e($o['min_label']) ?></p>
          <p class="bg-price__amount"><?= e(money_min()) ?></p>
          <p><?= e($o['unlock']) ?></p>
        </div>
      </div>
    </div>
    <div>
      <h2 class="bg-form-title"><?= e($o['form_heading']) ?></h2>
      <?php
        $formId = 'lead-form';
        $wrapId = 'lead-form-wrap';
        $formHeading = $o['form_title'];
        require __DIR__ . '/../includes/lead-form.php';
      ?>
    </div>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
