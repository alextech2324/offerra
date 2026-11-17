<?php
require __DIR__ . '/../includes/header.php';
$err = $langData['404'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap ft-center">
    <h1><?= e($err['title']) ?></h1>
    <p class="ft-lede"><?= e($err['text']) ?></p>
    <a class="ft-cta" href="<?= e(lang_path($lang, 'home')) ?>"><?= e($err['cta']) ?></a>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
