<?php

namespace Tests\Feature;

use App\Jobs\ProvisionInfrastructureJob;
use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\DeployService;
use App\Services\InfrastructureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProvisionProxyEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_wizard_lists_proxies_for_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->settings()->create([]);
        $user = User::factory()->create();
        $user->settings()->create([]);
        ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'label' => 'Edge proxy',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Create')
                ->has('proxyServers', 1)
                ->where('proxyServers.0.host', '172.86.71.141'));

        $this->actingAs($user)
            ->get(route('offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Create')
                ->where('proxyServers', []));
    }

    public function test_provision_job_queues_proxy_edge_after_deploy(): void
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
            'folder' => 'edge-at-create.live',
            'brand' => 'EdgeCreate',
            'domain' => 'edge-at-create.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'pending',
            'infra_meta' => [
                'options' => [
                    'edge' => 'proxy',
                    'edge_proxy_id' => $proxy->id,
                    'cloudflare_zone' => true,
                    'cloudflare_dns' => false,
                    'dynadot_ns' => true,
                    'cloudflare_ssl' => false,
                    'cloudflare_https' => false,
                    'cloudflare_www_redirect' => false,
                ],
            ],
        ]);

        $realProvisioner = app(InfrastructureProvisioner::class);
        $provisioner = Mockery::mock(InfrastructureProvisioner::class);
        $provisioner->shouldReceive('provision')->once()->andReturnUsing(function (Offer $o): void {
            $meta = is_array($o->infra_meta) ? $o->infra_meta : [];
            $meta['origin'] = 'done';
            $o->update([
                'infra_status' => 'ready',
                'infra_meta' => $meta,
            ]);
        });
        $provisioner->shouldReceive('enqueueProxyEdgeIfRequested')->once()->andReturnUsing(
            function (Offer $o) use ($realProvisioner): void {
                $realProvisioner->enqueueProxyEdgeIfRequested($o);
            },
        );

        $deploy = Mockery::mock(DeployService::class);
        $deploy->shouldReceive('enqueueDeployAfterInfra')->once()->andReturn(true);

        (new ProvisionInfrastructureJob($offer->id))->handle($provisioner, $deploy);

        Queue::assertPushedOn('deploy', SwitchOfferEdgeJob::class);
        Queue::assertPushed(SwitchOfferEdgeJob::class, function (SwitchOfferEdgeJob $job) use ($offer, $proxy) {
            return $job->offerId === $offer->id && $job->target === 'proxy:'.$proxy->id;
        });
    }
}
