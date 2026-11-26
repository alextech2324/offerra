<?php

namespace Tests\Unit;

use App\Services\OfferGenerator;
use App\Services\TemplateCatalog;
use Tests\TestCase;

class NavoraTemplateTest extends TestCase
{
    public function test_navora_is_a_regular_geo_template(): void
    {
        $this->assertFalse(TemplateCatalog::isDictionaryMl('navora'));
        $this->assertFalse(TemplateCatalog::copiesWholeTree('navora'));
        $this->assertFalse(TemplateCatalog::usesMlGeo('navora'));
        $this->assertFalse(TemplateCatalog::isOverflowHub('navora'));
    }

    public function test_navora_does_not_pin_market_to_ml(): void
    {
        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => 'navora',
            'geo' => 'IT',
            'lang' => 'it',
            'phone' => 'it',
            'phone_countries' => ['it'],
        ]);

        $this->assertSame('IT', $pinned['geo']);
        $this->assertSame('it', $pinned['lang']);
    }

    public function test_navora_pt_pack_has_required_files(): void
    {
        $generator = $this->app->make(OfferGenerator::class);
        $root = base_path('templates'.DIRECTORY_SEPARATOR.'navora'.DIRECTORY_SEPARATOR.'langs'.DIRECTORY_SEPARATOR.'pt');

        foreach ($generator->requiredRelativePaths('navora') as $relative) {
            if (str_starts_with($relative, 'static/')) {
                $this->assertFileExists(base_path('templates'.DIRECTORY_SEPARATOR.'navora'.DIRECTORY_SEPARATOR.$relative), $relative);

                continue;
            }

            $this->assertFileExists($root.DIRECTORY_SEPARATOR.$relative, $relative);
        }
    }

    public function test_navora_languages_cover_geo_catalog(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        $codes = $catalog->languageCodesFor('navora');

        foreach (['pt', 'en', 'it', 'es', 'de', 'fr', 'pl'] as $code) {
            $this->assertContains($code, $codes, $code);
        }

        $this->assertSame('Navora', $catalog->label('navora'));
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'pt', $catalog->previewRootPath('navora', 'pt'));
    }

    public function test_navora_english_pack_is_not_mixed_portuguese(): void
    {
        $index = file_get_contents(base_path('templates/navora/langs/en/index.php'));
        $this->assertIsString($index);
        $this->assertStringNotContainsString('Porque conhecer', $index);
        $this->assertStringNotContainsString('Ferramentas da', $index);
        $this->assertStringNotContainsString('opacity: 0', $index);
        $this->assertStringContainsString('Why get to know', $index);
        $this->assertStringContainsString('tools to track and control risk', $index);
        $privacy = file_get_contents(base_path('templates/navora/langs/en/privacy.php'));
        $this->assertIsString($privacy);
        $this->assertStringNotContainsString('telefone, idioma', $privacy);
        $this->assertStringContainsString('Name, email, phone', $privacy);

        $head = file_get_contents(base_path('templates/navora/includes/head.php'));
        $this->assertIsString($head);
        $this->assertStringNotContainsString('w-mod-js', $head);
        $this->assertStringContainsString('[data-w-id]{opacity:1!important', $head);
        $this->assertStringContainsString('css2.css', $head);
        $this->assertStringContainsString('inner-pages.css', $head);

        $validation = file_get_contents(base_path('templates/navora/integration/validation.js'));
        $this->assertIsString($validation);
        $this->assertStringContainsString('separateDialCode: true', $validation);
        $this->assertStringContainsString('allowDropdown: false', $validation);
    }

    public function test_navora_form_posts_to_send_php(): void
    {
        $form = file_get_contents(base_path('templates/navora/includes/form.php'));
        $this->assertIsString($form);
        $this->assertStringContainsString("asset('integration/send.php')", $form);
        $this->assertStringContainsString('name="first_name"', $form);
        $this->assertStringContainsString('name="last_name"', $form);
        $this->assertStringContainsString('name="form_token"', $form);
        $this->assertStringNotContainsString('submit.php', $form);
    }
}
