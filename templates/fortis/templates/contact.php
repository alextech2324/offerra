<?php
require __DIR__ . '/../includes/header.php';
$c = $langData['contact'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($c['title']) ?></h1>
    <p class="ft-lede"><?= e($c['lead']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap ft-open__grid">
    <?php
      $formId = 'lead-form';
      $wrapId = 'lead-form-wrap';
      require __DIR__ . '/../includes/lead-form.php';
    ?>
    <div>
      <?php fortis_figure($langData, 'street'); ?>
      <div class="ft-channels">
        <?php foreach ($c['channels'] as $ch):
          $href = $ch['href'];
          if ($href === 'faq') {
              $href = lang_path($lang, 'faq');
          }
        ?>
        <article class="ft-plate">
          <h3><?= e($ch['title']) ?></h3>
          <p><?= e($ch['text']) ?></p>
          <a href="<?= e($href) ?>"<?= str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><?= e($ch['link']) ?></a>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
