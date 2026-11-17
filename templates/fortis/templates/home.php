<?php
require __DIR__ . '/../includes/header.php';
$h = $langData['hero'];
$trust = $langData['trust'];
$plates = $langData['plates'];
$yield = $langData['yield'];
$howto = $langData['howto'];
$allies = $langData['allies'];
$faq = $langData['faq'];
$seo = $langData['seo'];
$calcMin = (int) (defined('MIN_DEPOSIT') ? MIN_DEPOSIT : 250);
$calcMax = max(10000, $calcMin * 40);
$symbol = currency_symbol();
$market = geo_country_name();
?>
<section class="ft-open">
  <div class="ft-wrap ft-open__grid">
    <div class="ft-open__copy">
      <p class="ft-kicker"><?= e($h['kicker']) ?></p>
      <h1 class="ft-open__title"><?= e($h['title']) ?></h1>
      <p class="ft-open__lead"><?= str_replace('{market}', e($market), $h['lead']) ?></p>
      <div class="ft-trust">
        <span class="ft-trust__score"><?= e($trust['score']) ?></span>
        <div>
          <p class="ft-trust__stars" aria-hidden="true">★★★★★</p>
          <p class="ft-trust__meta"><?= e($trust['based']) ?></p>
        </div>
      </div>
    </div>
    <?php
      $formId = 'lead-form';
      $wrapId = 'lead-form-wrap';
      require __DIR__ . '/../includes/lead-form.php';
    ?>
  </div>
</section>

<section class="ft-yield" id="yield" aria-label="<?= e($yield['title']) ?>"
  data-yield
  data-min="<?= $calcMin ?>"
  data-max="<?= $calcMax ?>"
  data-symbol="<?= e($symbol) ?>">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($yield['kicker']) ?></p>
    <h2><?= e($yield['title']) ?></h2>
    <p class="ft-lede"><?= e($yield['lead']) ?></p>
    <div class="ft-yield__board">
      <div class="ft-yield__controls">
        <label class="ft-yield__row" for="ft-stake">
          <span><?= e($yield['stake']) ?></span>
          <output data-yield-stake-out><?= e(money_min()) ?></output>
        </label>
        <input id="ft-stake" type="range" data-yield-stake min="<?= $calcMin ?>" max="<?= $calcMax ?>" step="50" value="<?= $calcMin ?>">
        <p class="ft-yield__minmax">
          <span><?= e(money_min()) ?></span>
          <span><?= e($symbol.number_format($calcMax)) ?></span>
        </p>
        <label class="ft-yield__row" for="ft-span">
          <span><?= e($yield['span']) ?></span>
          <output><span data-yield-span-out>45</span> <?= e($yield['days']) ?></output>
        </label>
        <input id="ft-span" type="range" data-yield-span min="7" max="90" step="1" value="45">
        <p class="ft-yield__minmax">
          <span><?= e($yield['span_min']) ?></span>
          <span><?= e($yield['span_max']) ?></span>
        </p>
      </div>
      <div class="ft-yield__sum">
        <p class="ft-yield__sum-label"><?= e($yield['illustrated']) ?></p>
        <p class="ft-yield__sum-value" data-yield-total><?= e(money_min()) ?></p>
        <dl class="ft-yield__facts">
          <div>
            <dt><?= e($yield['rate']) ?></dt>
            <dd><span data-yield-rate>18</span>%</dd>
          </div>
          <div>
            <dt><?= e($yield['gain']) ?></dt>
            <dd data-yield-gain><?= e($symbol) ?>0</dd>
          </div>
        </dl>
        <a class="ft-cta" href="#lead-form"><?= e($yield['cta']) ?></a>
        <p class="ft-yield__note"><?= e($yield['note']) ?></p>
      </div>
    </div>
  </div>
</section>

<section class="ft-band">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($plates['kicker']) ?></p>
    <h2><?= e(str_replace('{market}', $market, $plates['title'])) ?></h2>
    <div class="ft-plates">
      <?php foreach ($plates['items'] as $i => $item): ?>
      <article class="ft-plate">
        <span class="ft-plate__num"><?= e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></span>
        <h3><?= e($item['title']) ?></h3>
        <p><?= e($item['text']) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ft-band ft-band--ink">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($howto['eyebrow']) ?></p>
    <h2><?= e($howto['title']) ?></h2>
    <ol class="ft-path">
      <?php foreach ($howto['steps'] as $step): ?>
      <li>
        <h3><?= e($step['title']) ?></h3>
        <p><?= e($step['text']) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="ft-band">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($allies['kicker']) ?></p>
    <h2><?= e($allies['title']) ?></h2>
    <ul class="ft-allies">
      <?php foreach ($allies['names'] as $name): ?>
      <li><?= e($name) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="ft-band" aria-label="FAQ">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($faq['eyebrow']) ?></p>
    <h2><?= e($faq['title']) ?></h2>
    <p class="ft-lede"><?= e($faq['subtitle']) ?></p>
    <div class="ft-faq">
      <?php foreach ($faq['items'] as $item): ?>
      <div class="ft-faq__item">
        <button type="button"><?= e($item['q']) ?></button>
        <div class="ft-faq__a"><?= e($item['a']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ft-band ft-band--paper">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($seo['eyebrow']) ?></p>
    <h2><?= e($seo['title']) ?></h2>
    <p><?= e($seo['p1']) ?></p>
    <p><?= e($seo['p2']) ?></p>
    <p><?= e($seo['p3']) ?></p>
    <p class="ft-risk"><?= e($seo['risk']) ?></p>
    <p class="ft-center">
      <a class="ft-cta" href="#lead-form"><?= e($h['submit']) ?></a>
    </p>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
