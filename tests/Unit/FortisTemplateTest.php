<?php

namespace Tests\Unit;

use App\Services\OfferGenerator;
use App\Services\TemplateCatalog;
use Tests\TestCase;

class FortisTemplateTest extends TestCase
{
    public function test_fortis_is_a_dictionary_ml_template(): void
    {
        $this->assertTrue(TemplateCatalog::isDictionaryMl('fortis'));
        $this->assertTrue(TemplateCatalog::copiesWholeTree('fortis'));
        $this->assertTrue(TemplateCatalog::usesMlGeo('fortis'));
        $this->assertFalse(TemplateCatalog::isOverflowHub('fortis'));
    }

    public function test_fortis_market_pins_to_ml_en(): void
    {
        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => 'fortis',
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

    public function test_fortis_required_files_exist(): void
    {
        $generator = $this->app->make(OfferGenerator::class);
        $root = base_path('templates'.DIRECTORY_SEPARATOR.'fortis');

        foreach ($generator->requiredRelativePaths('fortis') as $relative) {
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.$relative, $relative);
        }
    }

    public function test_fortis_cookie_and_english_pack(): void
    {
        $routes = file_get_contents(base_path('templates/fortis/includes/routes.php'));
        $this->assertIsString($routes);
        $this->assertStringContainsString("define('LANG_COOKIE', 'fortis_lang')", $routes);

        $lang = require base_path('templates/fortis/lang/en.php');
        $this->assertIsArray($lang);
        foreach (['meta', 'nav', 'hero', 'yield', 'howto', 'faq', 'legal', '404'] as $key) {
            $this->assertArrayHasKey($key, $lang);
        }
        $this->assertSame('Join now', $lang['nav']['cta']);
        $this->assertArrayHasKey('privacy', $lang['legal']);
        $this->assertArrayHasKey('terms', $lang['legal']);
        $this->assertArrayHasKey('photos', $lang);
        foreach (['desk', 'scan', 'ledger', 'commute', 'signup', 'vault', 'session', 'skyline', 'atelier', 'street'] as $photo) {
            $this->assertArrayHasKey($photo, $lang['photos']);
            $this->assertFileExists(base_path('templates/fortis/assets/img/ft-'.$photo.'.jpg'));
        }
    }

    public function test_fortis_preview_uses_template_root(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        $root = $catalog->previewRootPath('fortis');
        $css = $catalog->resolvePreviewAsset('fortis', 'assets/css/fortis.css');

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'fortis', $root);
        $this->assertNotNull($css);
        $this->assertFileExists($css);
    }
}
