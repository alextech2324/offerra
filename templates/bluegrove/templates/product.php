<?php
require __DIR__ . '/../includes/header.php';
$p = $langData['product'];
$btc = bg_quotes()[0];
?>
<section class="bg-band">
  <div class="bg-wrap bg-split">
    <div>
      <p class="bg-kicker"><?= e($p['eyebrow']) ?></p>
      <h1><?= e($p['title']) ?></h1>
      <p class="bg-lede"><?= e($p['lead']) ?></p>
      <a class="bg-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($p['cta']) ?></a>
    </div>
    <figure class="bg-panel bg-panel--round bg-rates">
      <div class="bg-rates__head">
        <div>
          <p class="bg-kicker bg-kicker--light"><?= e($btc['pair']) ?></p>
          <p class="bg-signal__price"><?= e($btc['price']) ?></p>
        </div>
        <p class="bg-pill"><?= e($btc['change']) ?></p>
      </div>
      <?= bg_candle_chart('bg-chart bg-chart--md') ?>
      <div class="bg-rates__list">
        <?php bg_quote_rows(4, true); ?>
      </div>
      <figcaption><?= e($p['chart_cap']) ?></figcaption>
    </figure>
  </div>
</section>
<section class="bg-band">
  <div class="bg-wrap">
    <h2><?= e($p['parts_title']) ?></h2>
    <ul class="bg-grid-3">
      <?php foreach ($p['parts'] as $part): ?>
      <li class="bg-card bg-card--pad">
        <h3><?= e($part['title']) ?></h3>
        <p><?= e($part['text']) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
