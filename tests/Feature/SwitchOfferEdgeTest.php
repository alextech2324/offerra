<?php

namespace Tests\Feature;

use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SwitchOfferEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_proxy_and_user_cannot(): void
    {
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
}
