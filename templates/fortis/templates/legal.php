<?php
require __DIR__ . '/../includes/header.php';
$legalKey = $page;
$doc = $langData['legal'][$legalKey] ?? $langData['legal']['privacy'];

if (! function_exists('fortis_legal_inline')) {
    function fortis_legal_inline(string $text): string
    {
        $escaped = e($text);
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    }
}
?>
<section class="ft-pagehead">
  <div class="ft-wrap">
    <p class="ft-kicker"><?= e($langData['legal']['eyebrow'] ?? 'Legal') ?></p>
    <h1><?= e($doc['title']) ?></h1>
    <p class="ft-lede"><?= e($doc['updated']) ?></p>
  </div>
</section>
<section class="ft-band">
  <div class="ft-wrap ft-legal">
    <?php if (!empty($doc['intro'])): ?>
    <p><?= e($doc['intro']) ?></p>
    <?php endif; ?>
    <?php foreach ($doc['sections'] as $section): ?>
    <div class="ft-legal__block">
      <?php if (($section['h'] ?? '') !== ''): ?>
      <h2><?= e($section['h']) ?></h2>
      <?php endif; ?>
      <?php if (isset($section['p']) && !isset($section['blocks'])): ?>
        <p><?= e($section['p']) ?></p>
      <?php endif; ?>
      <?php foreach (($section['blocks'] ?? []) as $block):
          $type = $block['type'] ?? 'p';
          if ($type === 'h3'): ?>
        <h3><?= e($block['text'] ?? '') ?></h3>
      <?php elseif ($type === 'ul'): ?>
        <ul>
          <?php foreach (($block['items'] ?? []) as $item): ?>
          <li><?= fortis_legal_inline((string) $item) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php elseif ($type === 'table'): ?>
        <div class="ft-table-wrap">
          <table class="ft-table">
            <?php if (!empty($block['headers'])): ?>
            <thead>
              <tr>
                <?php foreach ($block['headers'] as $th): ?>
                <th><?= e((string) $th) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <?php endif; ?>
            <tbody>
              <?php foreach (($block['rows'] ?? []) as $row): ?>
              <tr>
                <?php foreach ($row as $cell): ?>
                <td><?= e((string) $cell) ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p><?= e($block['text'] ?? '') ?></p>
      <?php endif; endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
