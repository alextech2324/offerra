<?php
require __DIR__ . '/../includes/header.php';
$a = $langData['about'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($a['title']) ?></h1>
    <p class="ft-lede"><?= e($a['lead']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap">
    <?php fortis_figure($langData, 'atelier', 'ft-shot--wide'); ?>
  </div>
  <div class="ft-wrap ft-split">
    <div>
      <h2><?= e($a['story_title']) ?></h2>
      <p><?= e($a['story_p1']) ?></p>
      <p><?= e($a['story_p2']) ?></p>
      <p><?= e($a['story_p3']) ?></p>
    </div>
    <dl class="ft-stats">
      <?php foreach ($a['stats'] as $s): ?>
      <div>
        <dt><?= e($s['label']) ?></dt>
        <dd><?= e($s['value']) ?></dd>
      </div>
      <?php endforeach; ?>
    </dl>
  </div>
</section>
<section class="ft-band ft-band--ink">
  <div class="ft-wrap ft-tri">
    <article>
      <h2><?= e($a['mission_title']) ?></h2>
      <p><?= e($a['mission']) ?></p>
    </article>
    <article>
      <h2><?= e($a['vision_title']) ?></h2>
      <p><?= e($a['vision']) ?></p>
    </article>
    <article>
      <h2><?= e($a['values_title']) ?></h2>
      <p><?= e($a['values']) ?></p>
    </article>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap">
    <?php fortis_figure($langData, 'street', 'ft-shot--wide'); ?>
  </div>
  <div class="ft-wrap ft-center">
    <h2><?= e($a['cta_title']) ?></h2>
    <p class="ft-lede"><?= e($a['cta_text']) ?></p>
    <a class="ft-cta" href="<?= e(lang_path($lang, 'home')) ?>#lead-form"><?= e($a['cta_btn']) ?></a>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
