<?php
require __DIR__ . '/../includes/header.php';
$p = $langData['product'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($p['title']) ?></h1>
    <p class="ft-lede"><?= e($p['lead']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap ft-plates">
    <?php foreach ($p['items'] as $item): ?>
    <article class="ft-plate">
      <p class="ft-kicker"><?= e($item['tag']) ?></p>
      <h2><?= e($item['title']) ?></h2>
      <p><?= e($item['text']) ?></p>
      <ul class="ft-bullets">
        <?php foreach ($item['bullets'] as $b): ?>
        <li><?= e($b) ?></li>
        <?php endforeach; ?>
      </ul>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="ft-center">
    <a class="ft-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($p['cta_btn']) ?></a>
  </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
