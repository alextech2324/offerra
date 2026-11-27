<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Services\InfrastructureProvisioner;
use App\Services\OfferGenerator;
use App\Services\OfferRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OfferRestoreChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_table_includes_template_label(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'cloudflare_account_name' => 'CF Night',
            'dynadot_account_name' => 'Dyn Main',
            'dynadot_api_key' => 'dyn-key',
        ]);
        $this->makeArchivedOffer($user, 'archive-tpl.live', 'noctra', 'IT', [
            'cloudflare_account_name' => 'CF Spare',
            'dynadot_account_name' => 'Dyn Restore',
        ]);

        $this->actingAs($user)
            ->get(route('offers.archive.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Archive')
                ->where('offers.data.0.template_id', 'noctra')
                ->where('offers.data.0.geo', 'IT')
                ->where('offers.data.0.archive_reason', 'cf_abuse')
                ->where('offers.data.0.archive_reason_label', 'CF abuse')
                ->where('offers.data.0.cloudflare_account', 'CF Spare')
                ->where('offers.data.0.dynadot_account', 'Dyn Restore')
                ->has('templates')
                ->has('geoPresets'));
    }

    public function test_archive_table_falls_back_to_owner_provider_names(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'cloudflare_account_name' => 'Owner CF',
            'dynadot_account_name' => 'Owner Dyn',
            'dynadot_api_key' => 'dyn-key',
        ]);
        $this->makeArchivedOffer($user, 'archive-fallback.live', 'default', 'IT');

        $this->actingAs($user)
            ->get(route('offers.archive.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Archive')
                ->where('offers.data.0.cloudflare_account', 'Owner CF')
                ->where('offers.data.0.dynadot_account', 'Owner Dyn'));
    }

    public function test_restore_applies_template_and_geo_before_rebuild(): void
    {
        [$user, $offer] = $this->restoreFixture('from-archive.live', 'default', 'IT');
        $offer->forceFill(['availability_fail_streak' => 10, 'availability_status' => 'down'])->save();

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('rebuildLocalFolder')->once()->withArgs(function (Offer $rebuilding) {
            return $rebuilding->template === 'noctra'
                && $rebuilding->geo === 'PL'
                && $rebuilding->status === 'archived';
        });
        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('enqueue')->once();

        $restored = (new OfferRestoreService($generator, $provisioner))->restore($offer, $user, [
            'template' => 'noctra',
            'geo' => 'PL',
        ]);

        $this->assertSame('noctra', $restored->template);
        $this->assertSame('PL', $restored->geo);
        $this->assertSame('pl', $restored->lang);
        $this->assertSame('generated', $restored->status);
        $this->assertNull($restored->archive_reason);
        $this->assertSame('unchecked', $restored->availability_status);
        $this->assertSame(0, (int) $restored->availability_fail_streak);
    }

    public function test_restore_to_bluegrove_pins_ml_geo(): void
    {
        [$user, $offer] = $this->restoreFixture('ml-restore.live', 'lumen', 'DE');

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('rebuildLocalFolder')->once();
        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('enqueue')->once();

        $restored = (new OfferRestoreService($generator, $provisioner))->restore($offer, $user, [
            'template' => 'bluegrove',
            'geo' => 'DE',
        ]);

        $this->assertSame('bluegrove', $restored->template);
        $this->assertSame('ML', $restored->geo);
        $this->assertSame('en', $restored->lang);
    }

    public function test_restore_http_accepts_template_and_geo(): void
    {
        [$user, $offer] = $this->restoreFixture('http-restore.live', 'default', 'IT');

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('rebuildLocalFolder')->once();
        $this->app->instance(OfferGenerator::class, $generator);

        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('enqueue')->once();
        $this->app->instance(InfrastructureProvisioner::class, $provisioner);

        $this->actingAs($user)
            ->from(route('offers.archive.index'))
            ->post(route('offers.restore', $offer), [
                'template' => 'noctra',
                'geo' => 'PL',
            ])
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('noctra', $offer->template);
        $this->assertSame('PL', $offer->geo);
        $this->assertSame('generated', $offer->status);
        $this->assertNull($offer->archive_reason);
    }

    /**
     * @return array{0: User, 1: Offer}
     */
    private function restoreFixture(string $domain, string $template, string $geo): array
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'cloudflare_api_token' => 'token',
            'cloudflare_account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'dynadot_api_key' => 'dyn-key',
        ]);
        OriginServer::query()->create([
            'host' => '10.9.0.8',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);

        $offer = $this->makeArchivedOffer($user, $domain, $template, $geo);

        return [$user, $offer];
    }

    private function makeArchivedOffer(User $user, string $domain, string $template, string $geo, array $extra = []): Offer
    {
        return Offer::query()->create(array_merge([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'RestoreBrand',
            'domain' => $domain,
            'geo' => $geo,
            'lang' => strtolower($geo) === 'it' ? 'it' : 'en',
            'phone' => strtolower($geo),
            'phone_countries' => strtolower($geo),
            'template' => $template,
            'status' => 'archived',
            'archived_at' => now(),
            'archive_reason' => Offer::ARCHIVE_REASON_CF_ABUSE,
            'from_search_team' => false,
            'provision_infrastructure' => true,
        ], $extra));
    }
}
