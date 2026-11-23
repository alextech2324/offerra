<?php

namespace Tests\Feature;

use App\Jobs\RecheckInfrastructureDnsJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\DeployService;
use App\Services\EdgeProxyService;
use App\Services\InfrastructureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecheckPendingProxySslTest extends TestCase
{
    use RefreshDatabase;

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
}
