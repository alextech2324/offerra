<?php

namespace Tests\Unit;

use App\Models\Offer;
use App\Models\User;
use App\Models\WhiteLanding;
use App\Services\OfferCloakSync;
use App\Services\WhiteLandingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OfferCloakSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $whiteRoot;

    private string $offerRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whiteRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloak-sync-w-'.bin2hex(random_bytes(3));
        $this->offerRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloak-sync-o-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($this->whiteRoot);
        File::ensureDirectoryExists($this->offerRoot);
        $this->app->instance(WhiteLandingStore::class, new WhiteLandingStore($this->whiteRoot));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->whiteRoot);
        File::deleteDirectory($this->offerRoot);
        parent::tearDown();
    }

    public function test_sync_copies_runtime_and_white_then_remove_clears_them(): void
    {
        $user = User::factory()->create();
        $white = WhiteLanding::query()->create([
            'user_id' => $user->id,
            'name' => 'News',
            'entry_file' => 'index.html',
        ]);
        $whiteDir = $this->whiteRoot.DIRECTORY_SEPARATOR.$user->id.DIRECTORY_SEPARATOR.$white->id;
        File::ensureDirectoryExists($whiteDir);
        File::put($whiteDir.DIRECTORY_SEPARATOR.'index.html', '<html><head></head><body>white</body></html>');

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cloak-sync.test',
            'brand' => 'Cloak',
            'domain' => 'cloak-sync.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'cloak_enabled' => true,
            'white_landing_id' => $white->id,
        ]);

        File::ensureDirectoryExists($this->offerRoot.DIRECTORY_SEPARATOR.'includes');
        File::put($this->offerRoot.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'config.php', "<?php\ndefine('SITE_NAME', 'x');\n");

        $sync = $this->app->make(OfferCloakSync::class);
        $sync->sync($offer, $this->offerRoot);

        $this->assertFileExists($this->offerRoot.DIRECTORY_SEPARATOR.'cloak.php');
        $this->assertFileExists($this->offerRoot.DIRECTORY_SEPARATOR.'googlebot.json');
        $this->assertFileExists($this->offerRoot.DIRECTORY_SEPARATOR.'white'.DIRECTORY_SEPARATOR.'index.html');

        $json = json_decode(File::get($this->offerRoot.DIRECTORY_SEPARATOR.'cloak.json'), true);
        $this->assertSame(['IT'], $json['countries']);
        $this->assertContains('38.180.121.50', $json['panel_ips']);
        $this->assertSame('index.html', $json['entry']);
        $this->assertStringContainsString('cloak.php', File::get(
            $this->offerRoot.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'config.php',
        ));

        $offer->update(['cloak_enabled' => false, 'white_landing_id' => null]);
        $sync->sync($offer->fresh(), $this->offerRoot);

        $this->assertFileDoesNotExist($this->offerRoot.DIRECTORY_SEPARATOR.'cloak.php');
        $this->assertFileDoesNotExist($this->offerRoot.DIRECTORY_SEPARATOR.'cloak.json');
        $this->assertDirectoryDoesNotExist($this->offerRoot.DIRECTORY_SEPARATOR.'white');
    }
}
