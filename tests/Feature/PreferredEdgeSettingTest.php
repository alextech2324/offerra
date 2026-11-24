<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\InfrastructureProvisioner;
use App\Services\OfferGenerator;
use App\Services\OfferRestoreService;
use App\Support\InfrastructureOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PreferredEdgeSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_cloudflare_as_preferred_edge(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);

        $this->actingAs($user)
            ->patch(route('settings.update'), [
                'preferred_edge' => 'cloudflare',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertSame('cloudflare', $user->settings()->first()->preferred_edge);
        $this->assertFalse($user->settings()->first()->prefersProxyEdge());
    }

    public function test_create_wizard_follows_cloudflare_preference(): void
    {
        $user = User::factory()->create();
        $user->settings()->create(['preferred_edge' => 'cloudflare']);
        ProxyServer::query()->create([
            'host' => '10.0.0.8',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Create')
                ->where('preferredEdge', 'cloudflare'));
    }

    public function test_restore_follows_owner_cloudflare_preference(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'cloudflare_api_token' => 'token',
            'cloudflare_account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'dynadot_api_key' => 'dyn-key',
            'preferred_edge' => 'cloudflare',
        ]);
        OriginServer::query()->create([
            'host' => '10.0.0.9',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);
        ProxyServer::query()->create([
            'host' => '10.0.0.8',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'restored-cf.live',
            'brand' => 'Restored',
            'domain' => 'restored-cf.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'archived',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_proxy_id' => 1,
            ],
        ]);

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('rebuildLocalFolder')->once();
        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('enqueue')->once();

        $restored = (new OfferRestoreService($generator, $provisioner))->restore($offer, $user);

        $this->assertFalse(InfrastructureOptions::wantsProxy(
            InfrastructureOptions::forOffer($restored),
        ));
        $this->assertSame('cloudflare', $restored->infra_meta['options']['edge'] ?? null);
    }
}
