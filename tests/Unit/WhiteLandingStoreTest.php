<?php

namespace Tests\Unit;

use App\Models\Offer;
use App\Models\User;
use App\Services\WhiteLandingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class WhiteLandingStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private WhiteLandingStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offerra-whites-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root);
        $this->store = new WhiteLandingStore($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_extracts_flat_html_white(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'index.html' => '<html><body>White</body></html>',
            'css/app.css' => 'body{color:#111}',
        ]);

        $landing = $this->store->store($user, $zip, 'News IE');

        $this->assertSame('News IE', $landing->name);
        $this->assertSame('index.html', $landing->entry_file);
        $this->assertSame(2, $landing->file_count);
        $this->assertFileExists($this->store->filesPath($landing).DIRECTORY_SEPARATOR.'index.html');
        $this->assertFileExists($this->store->filesPath($landing).DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'app.css');
    }

    public function test_flattens_single_root_folder(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'my-white/index.html' => '<html>ok</html>',
            'my-white/img/logo.png' => 'png',
        ]);

        $landing = $this->store->store($user, $zip, 'Folder');

        $this->assertFileExists($this->store->filesPath($landing).DIRECTORY_SEPARATOR.'index.html');
        $this->assertFileExists($this->store->filesPath($landing).DIRECTORY_SEPARATOR.'img'.DIRECTORY_SEPARATOR.'logo.png');
    }

    public function test_accepts_php_index(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'index.php' => '<?php echo "White";',
            'css/app.css' => 'body{color:#111}',
        ]);

        $landing = $this->store->store($user, $zip, 'Php White');

        $this->assertSame('index.php', $landing->entry_file);
        $this->assertFileExists($this->store->filesPath($landing).DIRECTORY_SEPARATOR.'index.php');
    }

    public function test_rejects_shell_scripts(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'index.html' => '<html>ok</html>',
            'run.sh' => '#!/bin/sh',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('не підходить');

        $this->store->store($user, $zip, 'Bad');
    }

    public function test_rejects_zip_without_index(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'home.html' => '<html>nope</html>',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('index.html або index.php');

        $this->store->store($user, $zip, 'NoIndex');
    }

    public function test_ensure_demo_copies_test_white_once(): void
    {
        $user = User::factory()->create();
        $first = $this->store->ensureDemo($user);
        $second = $this->store->ensureDemo($user);

        $this->assertTrue($first->is_demo);
        $this->assertSame(WhiteLandingStore::DEMO_NAME, $first->name);
        $this->assertSame($first->id, $second->id);
        $this->assertFileExists($this->store->filesPath($first).DIRECTORY_SEPARATOR.'index.html');
        $this->assertStringContainsString('Northshore Journal', File::get(
            $this->store->filesPath($first).DIRECTORY_SEPARATOR.'index.html',
        ));
    }

    public function test_cannot_delete_white_bound_to_live_offer(): void
    {
        $user = User::factory()->create();
        $landing = $this->store->store($user, $this->zipUpload([
            'index.html' => '<html>ok</html>',
        ]), 'Live');

        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'live-white.test',
            'brand' => 'Live',
            'domain' => 'live-white.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'cloak_enabled' => true,
            'white_landing_id' => $landing->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->store->delete($landing);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zipUpload(array $files): UploadedFile
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'src-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'white.zip', 'application/zip', null, true);
    }
}
