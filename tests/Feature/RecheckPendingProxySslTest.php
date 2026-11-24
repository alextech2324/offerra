<?php

namespace Tests\Feature;

use App\Jobs\RecheckInfrastructureDnsJob;
use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\DeployService;
use App\Services\EdgeProxyService;
use App\Services\InfrastructureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RecheckPendingProxySslTest extends TestCase
{
    use RefreshDatabase;

    public function test_edge_helper_skips_lets_encrypt_until_public_dns_points_at_proxy(): void
    {
        $script = EdgeProxyService::helperScript();
        $this->assertStringContainsString('WAIT_DNS', $script);
        $this->assertStringContainsString('SKIP_WWW', $script);
        $this->assertStringContainsString('points_here', $script);
    }

    public function test_recheck_retries_attach_when_proxy_ssl_is_pending(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'ssl-pending.pro',
            'brand' => 'SslPending',
            'domain' => 'ssl-pending.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_proxy_id' => $proxy->id,
                'ssl' => 'pending',
                'dns' => 'done',
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->once()->withArgs(function (Offer $o, ProxyServer $p) use ($offer, $proxy) {
            return $o->id === $offer->id && $p->id === $proxy->id;
        });

        (new RecheckInfrastructureDnsJob($offer->id))->handle(
            app(InfrastructureProvisioner::class),
            app(DeployService::class),
            $edge,
        );
    }

    public function test_recheck_skips_attach_when_proxy_ssl_is_done(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'ssl-ok.pro',
            'brand' => 'SslOk',
            'domain' => 'ssl-ok.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_proxy_id' => $proxy->id,
                'ssl' => 'done',
                'dns' => 'done',
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->never();

        (new RecheckInfrastructureDnsJob($offer->id))->handle(
            app(InfrastructureProvisioner::class),
            app(DeployService::class),
            $edge,
        );
    }

    public function test_recheck_retries_attach_when_proxy_was_requested_but_ssl_never_saved(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'timbrelibranca.pro',
            'brand' => 'Timbre',
            'domain' => 'timbrelibranca.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'options' => [
                    'edge' => 'proxy',
                    'edge_proxy_id' => $proxy->id,
                ],
                'dns' => 'done',
                'edge_error' => 'Let’s Encrypt не видав сертифікат: cert=0',
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->once()->withArgs(function (Offer $o, ProxyServer $p) use ($offer, $proxy) {
            return $o->id === $offer->id && $p->id === $proxy->id;
        });

        (new RecheckInfrastructureDnsJob($offer->id))->handle(
            app(InfrastructureProvisioner::class),
            app(DeployService::class),
            $edge,
        );
    }

    public function test_recheck_skips_attach_before_lets_encrypt_retry_after(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'le-rate.pro',
            'brand' => 'LeRate',
            'domain' => 'le-rate.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_proxy_id' => $proxy->id,
                'ssl' => 'pending',
                'dns' => 'done',
                'ssl_error' => 'too many failed authorizations (5) for "le-rate.pro" in the last 1h0m0s, retry after 2099-01-01 00:00:00 UTC: see https://letsencrypt.org/docs/rate-limits/',
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->never();

        (new RecheckInfrastructureDnsJob($offer->id))->handle(
            app(InfrastructureProvisioner::class),
            app(DeployService::class),
            $edge,
        );
    }

    public function test_recheck_skips_attach_during_ssl_backoff(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'ssl-backoff.pro',
            'brand' => 'Backoff',
            'domain' => 'ssl-backoff.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'edge' => 'proxy',
                'edge_proxy_id' => $proxy->id,
                'ssl' => 'pending',
                'dns' => 'done',
                'ssl_retry_at' => time() - 30,
                'ssl_error' => 'Let’s Encrypt не видав сертифікат',
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->never();

        (new RecheckInfrastructureDnsJob($offer->id))->handle(
            app(InfrastructureProvisioner::class),
            app(DeployService::class),
            $edge,
        );
    }

    public function test_switch_edge_queues_recheck_when_proxy_ssl_stays_pending(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->settings()->create([]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'edge-ssl.pro',
            'brand' => 'EdgeSsl',
            'domain' => 'edge-ssl.pro',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'dns' => 'done',
                'options' => [
                    'edge' => 'proxy',
                    'edge_proxy_id' => $proxy->id,
                ],
            ],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->once()->andReturnUsing(function (Offer $o) use ($proxy): void {
            $meta = is_array($o->infra_meta) ? $o->infra_meta : [];
            $meta['edge'] = 'proxy';
            $meta['edge_proxy_id'] = $proxy->id;
            $meta['ssl'] = 'pending';
            $meta['dns'] = 'done';
            $o->update(['infra_meta' => $meta]);
        });

        (new SwitchOfferEdgeJob($offer->id, 'proxy:'.$proxy->id))->handle($edge);

        Queue::assertPushed(RecheckInfrastructureDnsJob::class, function (RecheckInfrastructureDnsJob $job) use ($offer) {
            return $job->offerId === $offer->id;
        });
    }
}
