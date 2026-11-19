<?php
require __DIR__ . '/../includes/header.php';
$err = $langData['404'];
?>
<section class="bg-pagehead">
  <div class="bg-wrap bg-center">
    <h1><?= e($err['title']) ?></h1>
    <p class="bg-lede"><?= e($err['text']) ?></p>
    <a class="bg-cta" href="<?= e(lang_path($lang, 'home')) ?>"><?= e($err['cta']) ?></a>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
