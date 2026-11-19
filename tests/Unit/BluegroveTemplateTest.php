<?php

namespace Tests\Unit;

use App\Services\OfferGenerator;
use App\Services\TemplateCatalog;
use Tests\TestCase;

class BluegroveTemplateTest extends TestCase
{
    public function test_bluegrove_is_a_dictionary_ml_template(): void
    {
        $this->assertTrue(TemplateCatalog::isDictionaryMl('bluegrove'));
        $this->assertTrue(TemplateCatalog::copiesWholeTree('bluegrove'));
        $this->assertTrue(TemplateCatalog::usesMlGeo('bluegrove'));
        $this->assertFalse(TemplateCatalog::isOverflowHub('bluegrove'));
    }

    public function test_bluegrove_market_pins_to_ml_en(): void
    {
        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => 'bluegrove',
            'geo' => 'DE',
            'lang' => 'de',
            'phone' => 'de',
            'phone_countries' => ['de'],
        ]);

        $this->assertSame('ML', $pinned['geo']);
        $this->assertSame('en', $pinned['lang']);
        $this->assertSame('ip', $pinned['phone']);
        $this->assertNotEmpty($pinned['phone_countries']);
    }

    public function test_bluegrove_required_files_exist(): void
    {
        $generator = $this->app->make(OfferGenerator::class);
        $root = base_path('templates'.DIRECTORY_SEPARATOR.'bluegrove');

        foreach ($generator->requiredRelativePaths('bluegrove') as $relative) {
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.$relative, $relative);
        }
    }

    public function test_bluegrove_cookie_routes_and_english_pack(): void
    {
        $routes = file_get_contents(base_path('templates/bluegrove/includes/routes.php'));
        $this->assertIsString($routes);
        $this->assertStringContainsString("define('LANG_COOKIE', 'bluegrove_lang')", $routes);
        $this->assertStringContainsString("'product' => 'platform'", $routes);
        $this->assertStringContainsString("'sign-up' => 'open-account'", $routes);
        $this->assertStringContainsString("'team' => 'team'", $routes);

        $lang = require base_path('templates/bluegrove/lang/en.php');
        $this->assertIsArray($lang);
        foreach (['meta', 'nav', 'hero', 'howto', 'faq', 'legal', '404', 'team', 'photos'] as $key) {
            $this->assertArrayHasKey($key, $lang);
        }
        $this->assertSame('Open account', $lang['nav']['cta']);
        $this->assertSame('{brand}', $lang['hero']['title']);
        $packed = json_encode($lang);
        $this->assertIsString($packed);
        $this->assertStringNotContainsString('{min_deposit}', $packed);
        $this->assertStringNotContainsString('{currency_symbol}', $packed);
        $this->assertArrayHasKey('privacy', $lang['legal']);
        $this->assertArrayHasKey('terms', $lang['legal']);
        foreach (['hero', 'desk', 'office', 'director'] as $photo) {
            $this->assertArrayHasKey($photo, $lang['photos']);
        }
    }

    public function test_bluegrove_preview_uses_template_root(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        $root = $catalog->previewRootPath('bluegrove');
        $css = $catalog->resolvePreviewAsset('bluegrove', 'assets/css/bluegrove.css');

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'bluegrove', $root);
        $this->assertNotNull($css);
        $this->assertFileExists($css);
    }
}
