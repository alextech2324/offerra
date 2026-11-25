<?php
declare(strict_types=1);

const SITE_NAME = 'Fortis';
const SITE_DOMAIN = '';
const SITE_URL = '';
const SITE_LANG = 'en';
const SITE_EMAIL = 'support@example.com';
const DEFAULT_LANG = 'en';
const SUPPORTED_LANGS = ['cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'hr', 'hu', 'id', 'it', 'ja', 'ka', 'lt', 'lv', 'ms', 'nl', 'no', 'pl', 'pt', 'ro', 'sk', 'sl', 'sr', 'sv', 'tr'];
const MIN_DEPOSIT = '250';
const CURRENCY = 'EUR';
const FORM_PHONE_COUNTRY = 'ip';
const FORM_ALLOWED_COUNTRIES = '';
const FORM_LEAD_COOKIE_DAYS = 30;
const FORM_THANK_YOU = 'Thanks.php';

/** Country ISO → site language (preview / stock). Generated offers rebuild from geo_presets. */
const GEO_LANG_MAP = [
    'DE' => 'de',
    'AT' => 'de',
    'CH' => 'de',
    'IT' => 'it',
    'SM' => 'it',
    'ES' => 'es',
    'MX' => 'es',
    'AR' => 'es',
    'CO' => 'es',
    'CL' => 'es',
    'PE' => 'es',
    'FR' => 'fr',
    'BE' => 'fr',
    'LU' => 'fr',
    'PT' => 'pt',
    'BR' => 'pt',
    'TR' => 'tr',
    'NL' => 'nl',
    'NO' => 'no',
    'DK' => 'da',
    'SE' => 'sv',
    'FI' => 'fi',
    'PL' => 'pl',
    'CZ' => 'cs',
    'SK' => 'sk',
    'SI' => 'sl',
    'HU' => 'hu',
    'HR' => 'hr',
    'RS' => 'sr',
    'RO' => 'ro',
    'GR' => 'el',
    'CY' => 'el',
    'LV' => 'lv',
    'LT' => 'lt',
    'GE' => 'ka',
    'MY' => 'ms',
    'ID' => 'id',
    'JP' => 'ja',
];

require_once __DIR__ . '/routes.php';
