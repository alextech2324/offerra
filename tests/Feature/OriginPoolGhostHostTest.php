<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\InfrastructureProvisioner;
use App\Services\OfferGenerator;
use App\Jobs\ProvisionInfrastructureJob;
use App\Services\OfferRestoreService;
use App\Services\OriginPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OriginPoolGhostHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_origin_is_not_usable_and_restore_allocates_a_pool_host(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $live = OriginServer::query()->create([
            'host' => '10.9.0.1',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'ghost-restore.live',
            'brand' => 'Ghost',
            'domain' => 'ghost-restore.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'deploy_panel_name' => '144.172.64.213',
            'infra_meta' => ['deploy_host' => '144.172.64.213'],
        ]);

        $pool = app(OriginPool::class);
        $this->assertNull($pool->usableBoundServer($offer));

        $settings = $pool->settingsForOffer($user->settings, $offer);
        $this->assertSame($live->host, (string) $settings->deploy_host);
        $this->assertSame($live->host, $offer->fresh()->infra_meta['deploy_host'] ?? null);
    }

    public function test_inactive_or_down_origin_is_not_kept(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        OriginServer::query()->create([
            'host' => '10.9.0.2',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);
        OriginServer::query()->create([
            'host' => '81.180.92.59',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => false,
            'health' => ['status' => 'ok'],
        ]);

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'inactive-origin.live',
            'brand' => 'Ghost',
            'domain' => 'inactive-origin.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'deploy_panel_name' => '81.180.92.59',
            'infra_meta' => ['deploy_host' => '81.180.92.59'],
        ]);

        $this->assertNull(app(OriginPool::class)->usableBoundServer($offer));
    }

    public function test_restore_drops_deleted_origin_pin(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'cloudflare_api_token' => 'token',
            'cloudflare_account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'dynadot_api_key' => 'dyn-key',
            'preferred_edge' => 'proxy',
        ]);
        OriginServer::query()->create([
            'host' => '10.9.0.3',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);
        ProxyServer::query()->create([
            'host' => '10.9.0.8',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'from-archive.live',
            'brand' => 'Ghost',
            'domain' => 'from-archive.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'archived',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'deploy_panel_name' => '144.172.64.213',
            'infra_meta' => ['deploy_host' => '144.172.64.213'],
        ]);

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('rebuildLocalFolder')->once();
        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('enqueue')->once();

        $restored = (new OfferRestoreService($generator, $provisioner))->restore($offer, $user);

        $this->assertNull($restored->deploy_panel_name);
        $this->assertArrayNotHasKey('deploy_host', $restored->infra_meta ?? []);
        $this->assertSame('unchecked', $restored->availability_status);
    }

    public function test_requeue_command_only_picks_unauthorized_ip_failures(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->settings()->create([]);

        $retry = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'bad-ns.live',
            'brand' => 'Ghost',
            'domain' => 'bad-ns.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'failed',
            'infra_error' => 'unauthorized ip address: 38.180.121.50',
        ]);
        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cf-blocked.live',
            'brand' => 'Ghost',
            'domain' => 'cf-blocked.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'failed',
            'infra_error' => 'Cloudflare blocked this domain on all accounts',
        ]);

        $this->artisan('offers:requeue-unauthorized-dynadot')
            ->expectsOutputToContain('queued: bad-ns.live')
            ->expectsOutputToContain('requeued 1')
            ->assertSuccessful();

        $this->assertSame('pending', $retry->fresh()->infra_status);
        Queue::assertPushedOn('deploy', ProvisionInfrastructureJob::class);
        Queue::assertPushed(ProvisionInfrastructureJob::class, 1);
        Queue::assertPushed(ProvisionInfrastructureJob::class, function (ProvisionInfrastructureJob $job) use ($retry) {
            return $job->offerId === $retry->id;
        });
    }
}
