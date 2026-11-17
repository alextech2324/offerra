<?php
require __DIR__ . '/../includes/header.php';
$o = $langData['offer'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($o['title']) ?></h1>
    <p class="ft-lede"><?= e($o['lead']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap">
    <ol class="ft-path">
      <?php
        $offerPhotos = ['signup', 'vault', 'session'];
        foreach ($o['items'] as $i => $item):
      ?>
      <li>
        <?php fortis_figure($langData, $offerPhotos[$i] ?? 'desk'); ?>
        <h3><?= e($item['title']) ?></h3>
        <p><?= e($item['text']) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
    <p class="ft-center">
      <a class="ft-cta" href="<?= e(lang_path($lang, 'home')) ?>#lead-form"><?= e($o['cta_btn']) ?></a>
    </p>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
