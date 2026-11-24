<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhiteLanding;
use App\Services\WhiteLandingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class WhiteLandingTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offerra-wl-feat-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root);
        $this->app->instance(WhiteLandingStore::class, new WhiteLandingStore($this->root));
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_guest_cannot_open_landings(): void
    {
        $this->get(route('landings.index'))->assertRedirect();
    }

    public function test_index_seeds_demo_white(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('landings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Landings/Index')
                ->where('landings', fn ($rows) => collect($rows)->contains(
                    fn ($row) => ($row['is_demo'] ?? false) && ($row['name'] ?? '') === WhiteLandingStore::DEMO_NAME,
                )));
    }

    public function test_user_can_upload_and_preview_white(): void
    {
        $user = User::factory()->create();
        $zip = $this->zipUpload([
            'index.html' => '<html><body>White page</body></html>',
            'style.css' => 'body{margin:0}',
        ]);

        $this->actingAs($user)
            ->post(route('landings.store'), [
                'name' => 'News IE',
                'zip' => $zip,
            ])
            ->assertRedirect(route('landings.index'))
            ->assertSessionHas('success');

        $landing = WhiteLanding::query()->where('name', 'News IE')->first();
        $this->assertNotNull($landing);
        $this->assertSame($user->id, $landing->user_id);

        $this->actingAs($user)
            ->get(route('landings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Landings/Index')
                ->where('landings', fn ($rows) => collect($rows)->contains('name', 'News IE')));

        $preview = $this->actingAs($user)
            ->get('/landings/'.$landing->id.'/preview/index.html')
            ->assertOk();

        $this->assertStringContainsString(
            'White page',
            $preview->streamedContent() ?: $preview->getContent(),
        );
    }

    public function test_user_cannot_preview_someone_elses_white(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = app(WhiteLandingStore::class);
        $landing = $store->store($owner, $this->zipUpload([
            'index.html' => '<html>secret</html>',
        ]), 'Secret');

        $this->actingAs($other)
            ->get('/landings/'.$landing->id.'/preview/')
            ->assertNotFound();
    }

    public function test_upload_accepts_php_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('landings.store'), [
                'name' => 'Php White',
                'zip' => $this->zipUpload([
                    'index.php' => '<?php echo "ok";',
                ]),
            ])
            ->assertRedirect(route('landings.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('white_landings', [
            'user_id' => $user->id,
            'name' => 'Php White',
            'entry_file' => 'index.php',
        ]);
    }

    public function test_upload_rejects_shell_scripts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('landings.index'))
            ->post(route('landings.store'), [
                'name' => 'Bad',
                'zip' => $this->zipUpload([
                    'index.html' => '<html>ok</html>',
                    'run.sh' => '#!/bin/sh',
                ]),
            ])
            ->assertRedirect(route('landings.index'))
            ->assertSessionHasErrors('zip');

        $this->assertDatabaseMissing('white_landings', ['name' => 'Bad']);
    }

    public function test_user_can_delete_own_white(): void
    {
        $user = User::factory()->create();
        $store = app(WhiteLandingStore::class);
        $landing = $store->store($user, $this->zipUpload([
            'index.html' => '<html>bye</html>',
        ]), 'Bye');

        $this->actingAs($user)
            ->delete(route('landings.destroy', $landing))
            ->assertRedirect(route('landings.index'));

        $this->assertDatabaseMissing('white_landings', ['id' => $landing->id]);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zipUpload(array $files): UploadedFile
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'up-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'white.zip', 'application/zip', null, true);
    }
}
