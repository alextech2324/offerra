<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Services\OfferZipExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class OfferZipDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_offer_zip(): void
    {
        $admin = User::factory()->admin()->create();
        $offer = $this->makeOffer($admin, 'zip-brand.com');
        $zipPath = $this->writeTempZip();

        $this->mock(OfferZipExporter::class, function ($mock) use ($offer, $zipPath) {
            $mock->shouldReceive('build')
                ->once()
                ->with(\Mockery::on(fn (Offer $row) => (int) $row->id === (int) $offer->id))
                ->andReturn([
                    'path' => $zipPath,
                    'filename' => 'zip-brand.com.zip',
                ]);
        });

        $this->actingAs($admin)
            ->get(route('offers.download-zip', $offer))
            ->assertOk()
            ->assertDownload('zip-brand.com.zip');
    }

    public function test_regular_user_cannot_download_offer_zip(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'no-zip.com');

        $this->mock(OfferZipExporter::class, function ($mock) {
            $mock->shouldNotReceive('build');
        });

        $this->actingAs($user)
            ->get(route('offers.download-zip', $offer))
            ->assertForbidden();
    }

    public function test_manage_all_user_cannot_download_offer_zip(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->manageAllOffers()->create();
        $offer = $this->makeOffer($owner, 'manager-zip.com');

        $this->actingAs($manager)
            ->get(route('offers.download-zip', $offer))
            ->assertForbidden();
    }

    private function makeOffer(User $user, string $domain): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'ZipBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
        ]);
    }

    private function writeTempZip(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offerra-zip-feat-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('index.php', "<?php echo 'ok';\n");
        $zip->close();

        return $path;
    }
}
