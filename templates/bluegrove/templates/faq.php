<?php
require __DIR__ . '/../includes/header.php';
$faq = $langData['faq'];
$email = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? (string) SITE_EMAIL : 'support@example.com';
$groups = $faq['groups'] ?? [];
?>
<section class="bg-band">
  <div class="bg-wrap bg-wrap--narrow">
    <p class="bg-kicker"><?= e($faq['eyebrow']) ?></p>
    <h1><?= e($faq['page_title']) ?></h1>
    <p class="bg-lede"><?= str_replace('{email}', e($email), e($faq['page_lead'])) ?></p>
    <?php if ($groups): ?>
      <?php foreach ($groups as $group): ?>
      <section class="bg-faq-group" id="<?= e($group['id'] ?? '') ?>">
        <h2><?= e($group['title']) ?></h2>
        <div class="bg-faq">
          <?php foreach ($group['items'] as $item): ?>
          <div class="bg-faq__item">
            <button type="button"><?= e($item['q']) ?></button>
            <div class="bg-faq__a"><?= e($item['a']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="bg-faq">
        <?php foreach ($faq['items'] as $item): ?>
        <div class="bg-faq__item">
          <button type="button"><?= e($item['q']) ?></button>
          <div class="bg-faq__a"><?= e($item['a']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
