<?php
declare(strict_types=1);

/**
 * Route table + language cookie. Survives OfferConfigBuilder overwrite of config.php.
 */
if (! defined('LANG_COOKIE')) {
    define('LANG_COOKIE', 'fortis_lang');
}
if (! defined('LANG_COOKIE_DAYS')) {
    define('LANG_COOKIE_DAYS', 365);
}

if (! defined('PAGES')) {
    define('PAGES', [
        'home' => '',
        'about' => 'about',
        'product' => 'product',
        'offer' => 'offer',
        'sign-up' => 'sign-up',
        'contact' => 'contact',
        'faq' => 'faq',
        'privacy' => 'privacy',
        'terms' => 'terms',
        'cookie-policy' => 'cookie-policy',
        'abuse' => 'abuse',
    ]);
}
