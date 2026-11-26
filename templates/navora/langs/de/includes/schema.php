<?php
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => rtrim(SITE_URL, '/').'/#organization',
            'name' => SITE_NAME,
            'url' => rtrim(SITE_URL, '/').'/',
            'email' => defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : ('support@'.site_domain()),
        ],
        [
            '@type' => 'WebSite',
            '@id' => rtrim(SITE_URL, '/').'/#website',
            'name' => SITE_NAME,
            'url' => rtrim(SITE_URL, '/').'/',
            'inLanguage' => site_locale(),
            'publisher' => ['@id' => rtrim(SITE_URL, '/').'/#organization'],
        ],
    ],
];
?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
