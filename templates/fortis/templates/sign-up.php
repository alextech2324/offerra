<?php
require __DIR__ . '/../includes/header.php';
$s = $langData['signup'];
$howto = $langData['howto'];
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <h1><?= e($s['title']) ?></h1>
    <p class="ft-lede"><?= e($s['lead']) ?></p>
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
      <?php fortis_figure($langData, 'signup'); ?>
      <h2><?= e($s['steps_title']) ?></h2>
      <ol class="ft-path">
        <?php foreach ($howto['steps'] as $step): ?>
        <li>
          <h3><?= e($step['title']) ?></h3>
          <p><?= e($step['text']) ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
