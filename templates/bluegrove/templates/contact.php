<?php
require __DIR__ . '/../includes/header.php';
$c = $langData['contact'];
$email = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? (string) SITE_EMAIL : 'support@example.com';
?>
<section class="bg-band">
  <div class="bg-wrap">
    <p class="bg-kicker"><?= e($c['eyebrow']) ?></p>
    <h1 class="bg-title-max"><?= e($c['title']) ?></h1>
    <p class="bg-lede"><?= str_replace(e($email), '<a class="bg-mail" href="mailto:'.e($email).'">'.e($email).'</a>', e($c['lead'])) ?></p>
    <div class="bg-contact">
      <ul class="bg-grid-2">
        <?php foreach ($c['topics'] as $topic): ?>
        <li class="bg-card bg-card--pad">
          <h2><?= e($topic['title']) ?></h2>
          <p><?= e($topic['text']) ?></p>
        </li>
        <?php endforeach; ?>
      </ul>
      <aside class="bg-panel bg-panel--round bg-contact__aside">
        <h2><?= e($c['include_title']) ?></h2>
        <ul>
          <?php foreach ($c['include'] as $line): ?>
          <li><?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
        <h2><?= e($c['omit_title']) ?></h2>
        <ul>
          <?php foreach ($c['omit'] as $line): ?>
          <li><?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="bg-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($c['cta']) ?></a>
      </aside>
    </div>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
