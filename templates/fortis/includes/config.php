<?php
declare(strict_types=1);

const SITE_NAME = 'Fortis';
const SITE_DOMAIN = '';
const SITE_URL = '';
const SITE_LANG = 'en';
const SITE_EMAIL = 'support@example.com';
const DEFAULT_LANG = 'en';
const SUPPORTED_LANGS = ['en'];
const MIN_DEPOSIT = '250';
const CURRENCY = 'EUR';
const FORM_PHONE_COUNTRY = 'ip';
const FORM_ALLOWED_COUNTRIES = '';
const FORM_LEAD_COOKIE_DAYS = 30;
const FORM_THANK_YOU = 'Thanks.php';

/** Country ISO → site language (preview / stock). Generated offers rebuild from geo_presets. */
const GEO_LANG_MAP = [
    'US' => 'en',
    'GB' => 'en',
    'IE' => 'en',
    'AU' => 'en',
    'NZ' => 'en',
    'CA' => 'en',
    'SG' => 'en',
];

require_once __DIR__ . '/routes.php';
