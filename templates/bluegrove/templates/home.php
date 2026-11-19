<?php
require __DIR__ . '/../includes/header.php';
$h = $langData['hero'];
$howto = $langData['howto'];
$signal = $langData['signal'];
$levels = $langData['levels'];
$voices = $langData['voices'];
$faq = $langData['faq'];
$join = $langData['join'];
?>
<section>
  <div class="bg-panel">
    <svg class="bg-panel__grid" viewBox="0 0 1200 640" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <path d="M0 46H280 M860 28H1200 M0 610H220 M980 600H1200 M0 250H140 M1060 210H1200 M0 400H90 M1110 390H1200" stroke="#8fd4a8" stroke-opacity="0.45" stroke-width="1" />
      <path d="M40 0V150 M1160 80V250 M180 490V640 M70 280V430 M1130 300V470" stroke="#ffffff" stroke-opacity="0.22" stroke-width="1" />
      <path d="M24 24H88 M24 24V88 M1176 24H1112 M1176 24V88 M24 616H88 M24 616V552 M1176 616H1112 M1176 616V552" stroke="#8fd4a8" stroke-opacity="0.7" stroke-width="1.25" fill="none" />
    </svg>
    <div class="bg-wrap bg-hero">
      <div>
        <h1><?= e($h['title']) ?></h1>
        <p class="bg-hero__lead"><?= e($h['lead']) ?></p>
        <?php bg_figure($langData, 'hero', 'bg-shot--wide', true); ?>
      </div>
      <?php
        $formId = 'lead-form';
        $wrapId = 'lead-form-wrap';
        $formHeading = $h['form_title'] ?? 'Request an account';
        $formCompact = true;
        require __DIR__ . '/../includes/lead-form.php';
      ?>
    </div>
  </div>
</section>

<section id="how" class="bg-band">
  <div class="bg-wrap">
    <p class="bg-kicker"><?= e($howto['eyebrow']) ?></p>
    <h2><?= e($howto['title']) ?></h2>
    <p class="bg-lede"><?= e($howto['lead']) ?></p>
    <ol class="bg-steps">
      <?php foreach ($howto['steps'] as $i => $step): ?>
      <li class="bg-card">
        <div class="bg-step-art">
          <?php if ($i === 0): ?>
            <p class="bg-kicker bg-kicker--light"><?= e($howto['watchlist'] ?? 'Watchlist') ?></p>
            <?php bg_quote_rows(4, true); ?>
          <?php elseif ($i === 1): ?>
            <p class="bg-kicker bg-kicker--light"><?= e($howto['fund'] ?? 'Minimum') ?></p>
            <p class="bg-step-art__big"><?= e($howto['fund_amount'] ?? 'By region') ?></p>
            <p class="bg-step-art__chip"><?= e($howto['fund_chip'] ?? 'Manager explains') ?></p>
          <?php else: ?>
            <div class="bg-step-art__row">
              <p class="bg-kicker bg-kicker--light">BTC/USD</p>
              <p class="bg-quotes__chg is-up"><?= e($signal['hold'] ?? 'Hold') ?></p>
            </div>
            <?= bg_candle_chart('bg-chart bg-chart--sm') ?>
          <?php endif; ?>
        </div>
        <div class="bg-card__body">
          <p class="bg-kicker"><?= e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></p>
          <h3><?= e($step['title']) ?></h3>
          <p><?= e($step['text']) ?></p>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section id="signals" class="bg-band">
  <div class="bg-wrap bg-split">
    <div>
      <p class="bg-kicker"><?= e($signal['eyebrow']) ?></p>
      <h2><?= e($signal['title']) ?></h2>
      <p class="bg-lede"><?= e($signal['lead']) ?></p>
      <ul class="bg-ticks">
        <?php foreach ($signal['items'] as $item): ?>
        <li class="bg-card bg-card--pad"><?= e($item) ?></li>
        <?php endforeach; ?>
      </ul>
      <a class="bg-cta" href="<?= e(lang_path($lang, 'sign-up')) ?>"><?= e($signal['cta']) ?></a>
    </div>
    <aside class="bg-panel bg-panel--round" aria-label="<?= e($signal['sample']) ?>">
      <div class="bg-signal">
        <div class="bg-signal__top">
          <div>
            <p class="bg-muted">BTC/USD</p>
            <p class="bg-signal__price">67,420</p>
          </div>
          <p class="bg-quotes__chg is-up">+0.30%</p>
        </div>
        <?= bg_candle_chart('bg-chart') ?>
      </div>
      <div class="bg-signal__note">
        <p class="bg-kicker bg-kicker--light"><?= e($signal['note_kicker']) ?></p>
        <p class="bg-signal__headline"><?= e($signal['note_title']) ?></p>
        <p><?= e($signal['note_text']) ?></p>
      </div>
    </aside>
  </div>
</section>

<section class="bg-panel">
  <div class="bg-wrap bg-split">
    <div>
      <p class="bg-kicker bg-kicker--light"><?= e($levels['eyebrow']) ?></p>
      <h2><?= e($levels['title']) ?></h2>
      <p class="bg-lede bg-lede--on-dark"><?= e($levels['lead']) ?></p>
      <ul class="bg-levels">
        <?php foreach ($levels['items'] as $item): ?>
        <li>
          <div>
            <p class="bg-levels__title"><?= e($item['title']) ?></p>
            <p><?= e($item['text']) ?></p>
          </div>
          <p class="bg-levels__mark"><?= e($item['mark']) ?></p>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="bg-fine"><?= e($levels['note']) ?></p>
    </div>
    <figure class="bg-overlay">
      <img src="<?= e(public_url(bg_photo_rel('desk'))) ?>" alt="<?= e(bg_photo_alt($langData, 'desk')) ?>" width="900" height="900" loading="lazy" decoding="async">
      <figcaption class="bg-overlay__tl">
        <span><?= e($levels['cap_a']) ?></span>
        <span><?= e($levels['cap_a2']) ?></span>
      </figcaption>
      <p class="bg-overlay__br">
        <span><?= e($levels['cap_b']) ?></span>
        <span><?= e($levels['cap_b2']) ?></span>
      </p>
    </figure>
  </div>
</section>

<section class="bg-band">
  <div class="bg-wrap">
    <p class="bg-kicker"><?= e($voices['eyebrow']) ?></p>
    <h2><?= e($voices['title']) ?></h2>
    <ul class="bg-voices">
      <?php foreach ($voices['items'] as $voice): ?>
      <li class="bg-card bg-card--pad">
        <p class="bg-stars" aria-label="5 out of 5 stars">★★★★★</p>
        <p class="bg-voices__q">“<?= e($voice['quote']) ?>”</p>
        <p class="bg-voices__name"><?= e($voice['name']) ?></p>
        <p class="bg-muted"><?= e($voice['place']) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section id="join" class="bg-band">
  <div class="bg-wrap bg-panel bg-panel--round bg-split bg-split--form">
    <div>
      <p class="bg-kicker bg-kicker--light"><?= e($join['eyebrow']) ?></p>
      <h2><?= e($join['title']) ?></h2>
      <p class="bg-lede bg-lede--on-dark"><?= e($join['lead']) ?></p>
      <div class="bg-quotes-box">
        <?php bg_quote_rows(6, true); ?>
      </div>
    </div>
    <?php
      $formId = 'lead-form-join';
      $wrapId = 'lead-form-join-wrap';
      $formHeading = $join['form_title'] ?? ($h['form_title'] ?? 'Open in under 2 minutes');
      $formCompact = false;
      require __DIR__ . '/../includes/lead-form.php';
    ?>
  </div>
</section>

<section class="bg-band" aria-label="FAQ">
  <div class="bg-wrap bg-wrap--narrow">
    <p class="bg-kicker"><?= e($faq['eyebrow']) ?></p>
    <h2><?= e($faq['title']) ?></h2>
    <div class="bg-faq">
      <?php foreach (array_slice($faq['items'], 0, 3) as $item): ?>
      <div class="bg-faq__item">
        <button type="button"><?= e($item['q']) ?></button>
        <div class="bg-faq__a"><?= e($item['a']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="bg-more"><a href="<?= e(lang_path($lang, 'faq')) ?>"><?= e($faq['more']) ?></a></p>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
