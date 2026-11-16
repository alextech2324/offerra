<?php

namespace Tests\Unit;

use Tests\TestCase;

class VesperaLangGeoSkipTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('SITE_LANG')) {
            define('SITE_LANG', 'en');
        }
        if (! defined('GEO_LANG_MAP')) {
            define('GEO_LANG_MAP', [
                'FR' => 'fr',
                'DE' => 'de',
                'GB' => 'en',
                'MX' => 'es',
                'DZ' => 'fr',
                'CH' => 'de',
                'BE' => 'fr',
                'CA' => 'en',
                'US' => 'en',
                'BR' => 'pt',
                'JP' => 'ja',
            ]);
        }

        require_once dirname(__DIR__, 2).'/templates/vespera/integration/LeadProcessor.php';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        parent::tearDown();
    }

    private function reason(string $ip, string $language): ?string
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = $ip;

        return \LeadProcessor::langGeoSpamReason(['language' => $language]);
    }

    public function test_matching_language_passes(): void
    {
        $this->assertNull($this->reason('FR', 'fr'));
        $this->assertNull($this->reason('DE', 'de'));
        $this->assertNull($this->reason('GB', 'en'));
        $this->assertNull($this->reason('MX', 'es'));
        $this->assertNull($this->reason('DZ', 'fr'));
        $this->assertNull($this->reason('BR', 'pt'));
        $this->assertNull($this->reason('JP', 'ja'));
    }

    public function test_mismatch_is_silent_skip(): void
    {
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('FR', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('DE', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('MX', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('DZ', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('GB', 'fr'));
    }

    public function test_us_is_always_skipped(): void
    {
        $this->assertSame('GEO_SKIP', $this->reason('US', 'en'));
        $this->assertSame('GEO_SKIP', $this->reason('US', 'es'));
        $this->assertSame('GEO_SKIP', $this->reason('US', 'fr'));
    }

    public function test_multilingual_allow_list(): void
    {
        $this->assertNull($this->reason('CH', 'de'));
        $this->assertNull($this->reason('CH', 'fr'));
        $this->assertNull($this->reason('CH', 'it'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('CH', 'en'));

        $this->assertNull($this->reason('BE', 'fr'));
        $this->assertNull($this->reason('BE', 'nl'));
        $this->assertNull($this->reason('BE', 'de'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('BE', 'en'));

        $this->assertNull($this->reason('CA', 'en'));
        $this->assertNull($this->reason('CA', 'fr'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('CA', 'de'));

        $this->assertNull($this->reason('LU', 'fr'));
        $this->assertNull($this->reason('LU', 'de'));
        $this->assertNull($this->reason('LU', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('LU', 'it'));
    }

    public function test_unknown_or_empty_ip_does_not_skip(): void
    {
        $this->assertNull($this->reason('XX', 'en'));
        $this->assertNull($this->reason('', 'en'));
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        $this->assertNull(\LeadProcessor::langGeoSpamReason(['language' => 'en']));
        $this->assertNull($this->reason('ZZ', 'en'));
    }

    public function test_uk_normalizes_to_gb(): void
    {
        $this->assertNull($this->reason('UK', 'en'));
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('UK', 'fr'));
    }

    public function test_empty_language_falls_back_to_site_lang(): void
    {
        $this->assertSame('LANG_GEO_MISMATCH', $this->reason('FR', ''));
        $this->assertNull($this->reason('GB', ''));
    }

    public function test_resolve_spam_reason_uses_lang_geo(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        $this->assertSame('GEO_SKIP', \LeadProcessor::resolveSpamReason(['language' => 'en']));
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
        $this->assertSame('LANG_GEO_MISMATCH', \LeadProcessor::resolveSpamReason(['language' => 'en']));
        $this->assertNull(\LeadProcessor::resolveSpamReason(['language' => 'fr']));
        $this->assertSame('BOT_UA', \LeadProcessor::resolveSpamReason(['language' => 'fr'], 'BOT_UA'));
    }
}
