<?php
require __DIR__ . '/../includes/header.php';
$t = $langData['team'];
$support = $langData['support'];
$email = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? (string) SITE_EMAIL : 'support@example.com';
$deskKeys = ['accounts', 'support', 'markets', 'product', 'notes', 'clients'];
?>
<section class="bg-band">
  <div class="bg-wrap">
    <div class="bg-team-lead">
      <div>
        <p class="bg-kicker"><?= e($t['eyebrow']) ?></p>
        <h1><?= e($t['title']) ?></h1>
        <p class="bg-lede"><?= e($t['lead']) ?></p>
        <ul class="bg-bullets">
          <?php foreach ($t['routes'] as $line): ?>
          <li><?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="bg-lede"><?= str_replace(e($email), '<a class="bg-mail" href="mailto:'.e($email).'">'.e($email).'</a>', e($t['mail'])) ?></p>
      </div>
      <figure class="bg-card bg-person bg-person--lead">
        <img src="<?= e(public_url(bg_photo_rel('director'))) ?>" alt="<?= e(bg_photo_alt($langData, 'director')) ?>" width="900" height="1200" decoding="async">
        <figcaption>
          <p class="bg-voices__name"><?= e($t['ceo']['name']) ?></p>
          <p class="bg-muted"><?= e($t['ceo']['role']) ?></p>
        </figcaption>
      </figure>
    </div>

    <h2 class="bg-team-rest"><?= e($t['rest_title']) ?></h2>
    <ul class="bg-grid-3">
      <?php foreach ($t['desk'] as $i => $person):
        $key = $deskKeys[$i] ?? 'clients';
      ?>
      <li class="bg-card bg-person">
        <img src="<?= e(public_url(bg_photo_rel($key))) ?>" alt="<?= e($person['alt'] ?? $person['name']) ?>" width="900" height="1200" loading="lazy" decoding="async">
        <div class="bg-card__body">
          <h3><?= e($person['name']) ?></h3>
          <p class="bg-muted"><?= e($person['role']) ?></p>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<section class="bg-band">
  <div class="bg-wrap">
    <h2><?= e($support['title']) ?></h2>
    <div class="bg-support">
      <div>
        <h3><?= e($support['service_title']) ?></h3>
        <p><?= e($support['service']) ?></p>
        <p><?= str_replace(e($email), '<a class="bg-mail" href="mailto:'.e($email).'">'.e($email).'</a>', e($support['write'])) ?></p>
      </div>
      <div>
        <h3><?= e($support['hours_title']) ?></h3>
        <p><?= e($support['hours']) ?></p>
        <p><?= e($support['never']) ?></p>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
