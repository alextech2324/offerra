<?php

namespace Tests\Feature;

use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CloudflareClient;
use App\Services\DynadotClient;
use App\Services\EdgeProxyService;
use App\Services\OriginHostService;
use App\Support\OriginSsh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SwitchOfferEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_proxy_and_user_cannot(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('proxy-servers.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('proxy-servers.index'))
            ->post(route('proxy-servers.store'), [
                'host' => '172.86.71.141',
                'port' => 22,
                'username' => 'root',
                'password' => 'secret',
                'label' => 'Edge proxy',
            ])
            ->assertRedirect(route('proxy-servers.index'));

        $row = ProxyServer::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('172.86.71.141', $row->host);
        $this->assertSame('secret', $row->password);
        $this->assertTrue($row->is_active);
    }

    public function test_admin_bulk_switch_queues_proxy_edge_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $owner->settings()->create([
            'cloudflare_api_token' => 'owner-token',
            'cloudflare_account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $owner->id,
            'folder' => 'edge-me.live',
            'brand' => 'Edge',
            'domain' => 'edge-me.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'cloudflare_zone_id' => 'zone-1',
                'deploy_host' => '10.0.0.8',
            ],
        ]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'label' => 'Edge proxy',
            'is_active' => true,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'switch_edge',
                'edge_target' => 'proxy:'.$proxy->id,
            ])
            ->assertRedirect();

        Queue::assertPushedOn('deploy', SwitchOfferEdgeJob::class);
        Queue::assertPushed(SwitchOfferEdgeJob::class, function (SwitchOfferEdgeJob $job) use ($offer, $proxy) {
            return $job->offerId === $offer->id && $job->target === 'proxy:'.$proxy->id;
        });
    }

    public function test_non_admin_cannot_switch_edge(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'nope.live',
            'brand' => 'Nope',
            'domain' => 'nope.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
        ]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'switch_edge',
                'edge_target' => 'proxy:'.$proxy->id,
            ])
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_offers_index_can_filter_by_proxy_edge(): void
    {
        $user = User::factory()->admin()->create();
        $user->settings()->create([]);

        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'on-proxy.live',
            'brand' => 'OnProxy',
            'domain' => 'on-proxy.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_ip' => '172.86.71.141',
            ],
        ]);
        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'on-cf.live',
            'brand' => 'OnCf',
            'domain' => 'on-cf.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'infra_meta' => [
                'edge' => 'cloudflare',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('offers.index', ['edge' => 'proxy']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Index')
                ->where('filters.edge', 'proxy')
                ->has('offers.data', 1)
                ->where('offers.data.0.domain', 'on-proxy.live'));
    }

    public function test_attach_creates_cloudflare_zone_when_offer_has_none(): void
    {
        $owner = User::factory()->create();
        $owner->settings()->create([
            'cloudflare_api_token' => 'owner-token',
            'cloudflare_account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'dynadot_api_key' => 'dyn-key',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $owner->id,
            'folder' => 'vertexinviolex.com',
            'brand' => 'Vertex Inviolex',
            'domain' => 'vertexinviolex.com',
            'geo' => 'AU',
            'lang' => 'en',
            'template' => 'lumen',
            'status' => 'deployed',
            'from_search_team' => false,
            'cloudflare_api_token' => 'offer-token',
            'cloudflare_account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'cloudflare_account_name' => 'Fuegodorn',
            'infra_meta' => [
                'deploy_host' => '81.180.92.59',
            ],
        ]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'label' => 'Edge proxy',
            'is_active' => true,
        ]);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('originIpForOffer')->once()->andReturn('81.180.92.59');

        $ssh = Mockery::mock(OriginSsh::class);
        $helperHash = hash('sha256', EdgeProxyService::helperScript());
        $ssh->shouldReceive('upload')->once();
        $ssh->shouldReceive('exec')->andReturnUsing(function () use ($helperHash) {
            $cmd = func_get_arg(4);
            if (is_string($cmd) && str_contains($cmd, 'sha256sum')) {
                return $helperHash;
            }

            return 'BOUND vertexinviolex.com';
        });

        $cloudflare = Mockery::mock(CloudflareClient::class);
        $cloudflare->shouldReceive('ensureZone')
            ->once()
            ->andReturnUsing(function (UserSetting $settings) {
                $this->assertSame('offer-token', $settings->cloudflare_api_token);
                $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $settings->cloudflare_account_id);

                return [
                    'zone_id' => 'zone-vertex',
                    'nameservers' => ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'],
                    'status' => 'pending',
                ];
            });
        $cloudflare->shouldReceive('setRootARecords')
            ->once()
            ->withArgs(function (UserSetting $settings, string $zoneId, string $domain, string $ip, bool $proxied) {
                return $zoneId === 'zone-vertex'
                    && $domain === 'vertexinviolex.com'
                    && $ip === '172.86.71.141'
                    && $proxied === false;
            });

        $dynadot = Mockery::mock(DynadotClient::class);
        $dynadot->shouldReceive('setNameservers')
            ->once()
            ->withArgs(function (UserSetting $settings, string $domain, array $ns) {
                return $domain === 'vertexinviolex.com'
                    && $ns === ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'];
            });

        $this->app->instance(OriginHostService::class, $origin);
        $this->app->instance(OriginSsh::class, $ssh);
        $this->app->instance(CloudflareClient::class, $cloudflare);
        $this->app->instance(DynadotClient::class, $dynadot);

        app(EdgeProxyService::class)->attach($offer->fresh(), $proxy);

        $offer->refresh();
        $this->assertSame('proxy', $offer->infra_meta['edge'] ?? null);
        $this->assertSame('zone-vertex', $offer->infra_meta['cloudflare_zone_id'] ?? null);
        $this->assertSame($proxy->id, $offer->infra_meta['edge_proxy_id'] ?? null);
        $this->assertNull($offer->infra_error);
    }
}
