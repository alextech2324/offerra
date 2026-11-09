<?php

namespace Tests\Unit;

use App\Models\Offer;
use App\Models\User;
use App\Services\OfferGenerator;
use App\Services\OfferZipExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class OfferZipExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_packs_assembled_lander_and_skips_dev_files(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offerra-zip-unit-'.bin2hex(random_bytes(4));
        $folder = 'brand-example-com';
        $local = $root.DIRECTORY_SEPARATOR.$folder;
        mkdir($local.DIRECTORY_SEPARATOR.'includes', 0777, true);
        file_put_contents($local.DIRECTORY_SEPARATOR.'index.php', "<?php echo 'ok';\n");
        file_put_contents($local.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'config.php', "<?php return [];\n");
        file_put_contents($local.DIRECTORY_SEPARATOR.'router.php', "<?php // skip\n");
        file_put_contents($local.DIRECTORY_SEPARATOR.'.gitignore', "*\n");

        $user = User::factory()->create();
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $folder,
            'brand' => 'Brand',
            'domain' => 'example.com',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
        ]);

        $generator = $this->createMock(OfferGenerator::class);
        $generator->expects($this->once())->method('refreshConfig')->with($offer);

        $exporter = new OfferZipExporter($generator, $root);
        $built = $exporter->build($offer);

        $this->assertSame('example.com.zip', $built['filename']);
        $this->assertFileExists($built['path']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($built['path']));
        $this->assertNotFalse($zip->locateName('index.php'));
        $this->assertNotFalse($zip->locateName('includes/config.php'));
        $this->assertFalse($zip->locateName('router.php'));
        $this->assertFalse($zip->locateName('.gitignore'));
        $zip->close();

        @unlink($built['path']);
        $this->rmDir($root);
    }

    private function rmDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->rmDir($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
