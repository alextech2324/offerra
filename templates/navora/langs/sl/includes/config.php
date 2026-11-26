<?php
/**
 * Local preview stub. OfferConfigBuilder overwrites this on generation.
 */
define('SITE_NAME', 'Navora');
define('SITE_URL', 'https://navora.local');
define('SITE_LANG', 'sl');
define('MIN_DEPOSIT', '250');
define('CURRENCY', 'EUR');

define('CRM_API_URL', 'https://yourleads.org/api/affiliates/v2/leads');
define('CRM_API_KEY', '');
define('CRM_AFFILIATE_ID', '');
define('CRM_FUNNEL', 'navora');
define('CRM_COUNTRY', 'SI');

define('CRM_AFF_SUB', '');
define('CRM_AFF_SUB2', '');
define('CRM_AFF_SUB3', '');
define('CRM_AFF_SUB4', '');
define('CRM_AFF_SUB5', '');
define('CRM_AFF_SUB6', '');
define('CRM_AFF_SUB7', '');
define('CRM_AFF_SUB8', '');
define('CRM_AFF_SUB9', '');
define('CRM_AFF_SUB10', '');
define('CRM_AFF_SUB11', '');
define('CRM_AFF_SUB12', '');
define('CRM_AFF_SUB13', '');

define('TG_BOT_TOKEN', '');
define('TG_CHAT_ID', '');
define('TG_GROUP_CHAT_ID', '');

define('FORM_PHONE_COUNTRY', 'si');
define('FORM_ALLOWED_COUNTRIES', 'si');
define('FORM_THANK_YOU', 'Thanks.php');
define('FORM_LEAD_COOKIE_DAYS', 30);
define('FORM_TOKEN_SECRET', 'local-preview-secret-not-used-in-production');
define('FORM_TOKEN_TTL', 600);
define('FORM_TOKEN_MIN_AGE', 3);
define('FORM_TOKEN_ISSUE_LIMIT', 8);
define('FORM_TOKEN_SUBMIT_LIMIT', 3);
define('FORM_TOKEN_RATE_WINDOW', 600);
define('FORM_TOKEN_DEBUG', false);

define('VITALS_ENABLED', false);
define('VITALS_CDN', '');
define('VITALS_TOKEN', '');
define('VITALS_ENDPOINT', '');

define('OFFERRA_GEO_CLICK_URL', '');
define('OFFERRA_GEO_CLICK_DOMAIN', '');
define('OFFERRA_CLICK_ID_URL', '');

require_once __DIR__.'/helpers.php';
offer_send_personalization_headers();
