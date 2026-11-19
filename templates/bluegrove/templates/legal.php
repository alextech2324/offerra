<?php
require __DIR__ . '/../includes/header.php';
$legalKey = $page;
$doc = $langData['legal'][$legalKey] ?? $langData['legal']['privacy'];
$email = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? (string) SITE_EMAIL : 'support@example.com';

if (! function_exists('bg_legal_inline')) {
    function bg_legal_inline(string $text, string $email): string
    {
        $escaped = e($text);
        $escaped = str_replace(e($email), '<a class="bg-mail" href="mailto:'.e($email).'">'.e($email).'</a>', $escaped);

        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    }
}
?>
<section class="bg-band">
  <div class="bg-wrap bg-wrap--narrow bg-legal">
    <h1><?= e($doc['title']) ?></h1>
    <p class="bg-muted"><?= e($doc['updated']) ?></p>
    <?php if (!empty($doc['intro'])): ?>
    <p><?= bg_legal_inline((string) $doc['intro'], $email) ?></p>
    <?php endif; ?>
    <?php foreach ($doc['sections'] as $section): ?>
    <div class="bg-legal__block">
      <?php if (($section['h'] ?? '') !== ''): ?>
      <h2><?= e($section['h']) ?></h2>
      <?php endif; ?>
      <?php if (isset($section['p']) && !isset($section['blocks'])): ?>
        <p><?= bg_legal_inline((string) $section['p'], $email) ?></p>
      <?php endif; ?>
      <?php foreach (($section['blocks'] ?? []) as $block):
          $type = $block['type'] ?? 'p';
          if ($type === 'ul'): ?>
        <ul>
          <?php foreach (($block['items'] ?? []) as $item): ?>
          <li><?= bg_legal_inline((string) $item, $email) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p><?= bg_legal_inline((string) ($block['text'] ?? $block['p'] ?? ''), $email) ?></p>
      <?php endif; endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
