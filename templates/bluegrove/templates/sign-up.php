<?php
require __DIR__ . '/../includes/header.php';
$s = $langData['signup'];
?>
<section class="bg-band">
  <div class="bg-wrap bg-panel bg-panel--round bg-split">
    <div>
      <p class="bg-kicker bg-kicker--light"><?= e($s['eyebrow']) ?></p>
      <h1><?= e($s['title']) ?></h1>
      <p class="bg-lede bg-lede--on-dark"><?= e($s['lead']) ?></p>
    </div>
    <?php
      $formId = 'lead-form';
      $wrapId = 'lead-form-wrap';
      $formHeading = $s['form_title'];
      require __DIR__ . '/../includes/lead-form.php';
    ?>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
