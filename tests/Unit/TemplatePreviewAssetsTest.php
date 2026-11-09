<?php

namespace Tests\Unit;

use App\Services\TemplateCatalog;
use Tests\TestCase;

class TemplatePreviewAssetsTest extends TestCase
{
    public function test_audax_preview_defaults_to_english_source(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        $root = $catalog->previewRootPath('audax');

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'langs'.DIRECTORY_SEPARATOR.'en', $root);
    }

    public function test_audax_preview_serves_static_from_english_pack(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);
        $css = $catalog->resolvePreviewAsset('audax', 'static/css/seo.css');
        $logo = $catalog->resolvePreviewAsset('audax', 'static/images/logo.svg');
        $integration = $catalog->resolvePreviewAsset('audax', 'integration/default-integration.css');

        $this->assertNotNull($css);
        $this->assertNotNull($logo);
        $this->assertNotNull($integration);
        $this->assertFileExists($css);
        $this->assertStringContainsString('langs'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'static', $css);
    }

    public function test_preview_asset_rejects_path_escape(): void
    {
        $catalog = $this->app->make(TemplateCatalog::class);

        $this->assertNull($catalog->resolvePreviewAsset('audax', '../Offer.php'));
        $this->assertNull($catalog->resolvePreviewAsset('audax', 'static/../../.env'));
    }
}
