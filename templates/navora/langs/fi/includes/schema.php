<?php
$orgId = rtrim(SITE_URL, '/').'/#organization';
$appId = rtrim(SITE_URL, '/').'/#app';
$rating = [
    '@type' => 'AggregateRating',
    'ratingValue' => offer_rating_value(),
    'bestRating' => '5',
    'worstRating' => '1',
    'ratingCount' => offer_rating_count(),
];
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $orgId,
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
            'publisher' => ['@id' => $orgId],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => $appId,
            'name' => SITE_NAME,
            'url' => rtrim(SITE_URL, '/').'/',
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => site_locale(),
            'offers' => [
                '@type' => 'Offer',
                'price' => defined('MIN_DEPOSIT') ? (string) MIN_DEPOSIT : '250',
                'priceCurrency' => defined('CURRENCY') ? (string) CURRENCY : 'EUR',
            ],
            'aggregateRating' => $rating,
            'publisher' => ['@id' => $orgId],
        ],
    ],
];
?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
