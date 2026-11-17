<?php
require __DIR__ . '/../includes/header.php';
$faq = $langData['faq'];
$notes = $faq['page_notes'] ?? [];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($faq['title']) ?></h1>
    <p class="ft-lede"><?= e($faq['subtitle']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap">
    <?php if ($notes): ?>
    <div class="ft-notes">
      <?php foreach ($notes as $note): ?>
      <p><?= e($note) ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="ft-faq">
      <?php foreach ($faq['items'] as $item): ?>
      <div class="ft-faq__item">
        <button type="button"><?= e($item['q']) ?></button>
        <div class="ft-faq__a"><?= e($item['a']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="ft-center">
      <a class="ft-cta" href="<?= e(lang_path($lang, 'contact')) ?>"><?= e($langData['nav']['contact']) ?></a>
    </p>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
