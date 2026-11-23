<?php

namespace Tests\Feature;

use App\Jobs\ProxyThenMoveOfferJob;
use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\EdgeProxyService;
use App\Services\OriginEvacuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProxyThenMoveOfferJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_proxy_before_moving_origin(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'keep-me.live',
            'brand' => 'Keep',
            'domain' => 'keep-me.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'deploy_panel_name' => '10.0.0.8',
            'infra_meta' => ['deploy_host' => '10.0.0.8'],
        ]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        OriginServer::query()->create([
            'host' => '192.142.46.50',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')
            ->once()
            ->withArgs(function (Offer $o, ProxyServer $p) use ($offer, $proxy) {
                $o->forceFill([
                    'infra_meta' => array_merge($o->infra_meta ?? [], [
                        'edge' => 'proxy',
                        'edge_proxy_id' => $p->id,
                    ]),
                ])->save();

                return $o->id === $offer->id && $p->id === $proxy->id;
            });

        $evac = Mockery::mock(OriginEvacuationService::class);
        $evac->shouldReceive('moveOffersTo')
            ->once()
            ->withArgs(function (iterable $offers, OriginServer $target) use ($offer) {
                $first = collect($offers)->first();

                return $first instanceof Offer
                    && $first->id === $offer->id
                    && ($first->infra_meta['edge'] ?? '') === 'proxy'
                    && $target->host === '192.142.46.50';
            })
            ->andReturn(['moved' => 1, 'queued' => 1, 'skipped' => 0]);

        $this->app->instance(EdgeProxyService::class, $edge);
        $this->app->instance(OriginEvacuationService::class, $evac);

        (new ProxyThenMoveOfferJob($offer->id, '192.142.46.50', 'proxy:'.$proxy->id))
            ->handle($edge, $evac, $this->app->make(\App\Services\OriginPool::class));
    }

    public function test_does_not_move_if_attach_did_not_set_proxy(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'stuck.live',
            'brand' => 'Stuck',
            'domain' => 'stuck.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'infra_meta' => ['deploy_host' => '10.0.0.8'],
        ]);
        $proxy = ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        OriginServer::query()->create([
            'host' => '192.142.46.50',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);

        $edge = Mockery::mock(EdgeProxyService::class);
        $edge->shouldReceive('attach')->once();
        $evac = Mockery::mock(OriginEvacuationService::class);
        $evac->shouldNotReceive('moveOffersTo');

        $this->app->instance(EdgeProxyService::class, $edge);
        $this->app->instance(OriginEvacuationService::class, $evac);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не на проксі');

        (new ProxyThenMoveOfferJob($offer->id, '192.142.46.50', 'proxy:'.$proxy->id))
            ->handle($edge, $evac, $this->app->make(\App\Services\OriginPool::class));
    }
}
