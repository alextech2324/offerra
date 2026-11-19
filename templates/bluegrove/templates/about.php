<?php
require __DIR__ . '/../includes/header.php';
$a = $langData['about'];
?>
<section class="bg-band">
  <div class="bg-wrap bg-split">
    <div>
      <p class="bg-kicker"><?= e($a['eyebrow']) ?></p>
      <h1><?= e($a['title']) ?></h1>
      <p class="bg-lede"><?= e($a['lead']) ?></p>
      <a class="bg-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($a['cta']) ?></a>
    </div>
    <?php bg_figure($langData, 'office', 'bg-shot--wide', true, $a['caption']); ?>
  </div>
</section>
<section class="bg-band bg-band--tight">
  <div class="bg-wrap">
    <h2><?= e($a['facts_title']) ?></h2>
    <ul class="bg-grid-2">
      <?php foreach ($a['facts'] as $fact): ?>
      <li class="bg-card bg-card--pad">
        <h3><?= e($fact['title']) ?></h3>
        <p><?= e($fact['text']) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<section id="markets" class="bg-band">
  <div class="bg-wrap">
    <h2><?= e($a['markets_title']) ?></h2>
    <p class="bg-lede"><?= e($a['markets_lead']) ?></p>
    <ul class="bg-markets">
      <?php foreach (bg_quotes() as $quote): ?>
      <li>
        <strong><?= e($quote['pair']) ?></strong>
        <span><?= e($quote['price']) ?></span>
        <span class="bg-quotes__chg <?= $quote['up'] ? 'is-up' : 'is-down' ?>"><?= e($quote['change']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
