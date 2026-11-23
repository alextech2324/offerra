<?php

namespace Tests\Feature;

use App\Jobs\DeployOfferJob;
use App\Jobs\PurgeOfferFromOriginJob;
use App\Jobs\RebindOfferDnsJob;
use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CloudflareClient;
use App\Services\OriginHostService;
use App\Services\OriginPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OfferMoveServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_move_retargets_and_queues_deploy_dns_and_purge(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $this->origin('84.200.193.136', 'ultahost');
        $to = $this->origin('84.200.89.234', 'ultahost');
        $offer = $this->offer($user, 'tutolana.tech', '84.200.193.136');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'move_server',
                'target_host' => $to->host,
            ])
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('84.200.89.234', $offer->deploy_panel_name);
        $this->assertSame('84.200.89.234', $offer->infra_meta['deploy_host'] ?? null);
        $this->assertSame('84.200.193.136', $offer->infra_meta['migrated_from'] ?? null);

        Bus::assertChained([
            DeployOfferJob::class,
            RebindOfferDnsJob::class,
        ]);
        Bus::assertDispatched(PurgeOfferFromOriginJob::class, function (PurgeOfferFromOriginJob $job) {
            return $job->queue === 'purge' && $job->host === '84.200.193.136';
        });
    }

    public function test_bulk_move_skips_offer_already_on_target(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $host = $this->origin('84.200.89.234');
        $offer = $this->offer($user, 'already-there.tech', $host->host);

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'move_server',
                'target_host' => $host->host,
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        Queue::assertNothingPushed();
    }

    public function test_regular_user_cannot_move_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->origin('10.0.0.1');
        $to = $this->origin('10.0.0.2');
        $offer = $this->offer($owner, 'owned.tech', '10.0.0.1');

        $this->actingAs($other)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'move_server',
                'target_host' => $to->host,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('bulk');

        $this->assertSame('10.0.0.1', $offer->fresh()->deploy_panel_name);
        Queue::assertNothingPushed();
    }

    public function test_rejects_drain_target(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->origin('10.1.0.1');
        $drain = $this->origin('10.1.0.9', role: OriginServer::ROLE_DRAIN);
        $offer = $this->offer($user, 'move-drain.tech', '10.1.0.1');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$offer->id],
                'action' => 'move_server',
                'target_host' => $drain->host,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('bulk');

        Queue::assertNothingPushed();
    }

    public function test_index_lists_move_targets_without_drain_or_down(): void
    {
        $user = User::factory()->create();
        $this->origin('10.2.0.1', 'cloudzy');
        $this->origin('10.2.0.2', 'ultahost', OriginServer::ROLE_SPARE);
        $this->origin('10.2.0.3', 'gone', OriginServer::ROLE_DRAIN);
        $this->origin('10.2.0.4', 'sick', health: 'down');

        $this->actingAs($user)
            ->get(route('offers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Index')
                ->has('originServers', 2)
                ->where('originServers.0.host', '10.2.0.1')
                ->where('originServers.1.host', '10.2.0.2'));
    }

    public function test_purge_job_skips_when_offer_is_still_on_that_host(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create(['user_id' => $user->id]);
        $this->origin('10.3.0.1');
        $offer = $this->offer($user, 'stay.tech', '10.3.0.1');

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldNotReceive('deleteWebRoot');

        (new PurgeOfferFromOriginJob($offer->id, '10.3.0.1'))
            ->handle(app(OriginPool::class), $origin, Mockery::mock(CloudflareClient::class));
    }

    public function test_purge_job_deletes_old_host_after_move(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create(['user_id' => $user->id]);
        $old = $this->origin('10.4.0.1');
        $this->origin('10.4.0.2');
        $offer = $this->offer($user, 'moved.tech', '10.4.0.2');
        $offer->forceFill([
            'infra_meta' => [
                'deploy_host' => '10.4.0.2',
                'cloudflare_zone_id' => 'zone-moved',
            ],
        ])->save();

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('hostHasOfferIndex')
            ->once()
            ->with('10.4.0.2', 'moved.tech')
            ->andReturn(true);
        $origin->shouldReceive('deleteWebRoot')
            ->once()
            ->withArgs(function ($settings, $domain) use ($old) {
                return $domain === 'moved.tech'
                    && (string) $settings->deploy_host === $old->host;
            });

        $cloudflare = Mockery::mock(CloudflareClient::class);
        $cloudflare->shouldReceive('listARecords')->andReturn([
            ['content' => '10.4.0.2'],
        ]);

        (new PurgeOfferFromOriginJob($offer->id, $old->host))
            ->handle(app(OriginPool::class), $origin, $cloudflare);
    }

    public function test_purge_job_skips_delete_when_new_origin_has_no_files(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create(['user_id' => $user->id]);
        $this->origin('10.5.0.1');
        $this->origin('10.5.0.2');
        $offer = $this->offer($user, 'empty-target.tech', '10.5.0.2');

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('hostHasOfferIndex')
            ->once()
            ->with('10.5.0.2', 'empty-target.tech')
            ->andReturn(false);
        $origin->shouldNotReceive('deleteWebRoot');

        (new PurgeOfferFromOriginJob($offer->id, '10.5.0.1'))
            ->handle(app(OriginPool::class), $origin, Mockery::mock(CloudflareClient::class));
    }

    public function test_purge_job_skips_delete_while_cloudflare_still_on_old_host(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'cloudflare_api_token' => 'tok',
        ]);
        $old = $this->origin('10.6.0.1');
        $this->origin('10.6.0.2');
        $offer = $this->offer($user, 'dns-lag.tech', '10.6.0.2');
        $offer->forceFill([
            'infra_meta' => [
                'deploy_host' => '10.6.0.2',
                'cloudflare_zone_id' => 'zone1',
            ],
        ])->save();

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('hostHasOfferIndex')->andReturn(true);
        $origin->shouldNotReceive('deleteWebRoot');

        $cloudflare = Mockery::mock(CloudflareClient::class);
        $cloudflare->shouldReceive('listARecords')->andReturn([
            ['content' => $old->host],
        ]);

        (new PurgeOfferFromOriginJob($offer->id, $old->host))
            ->handle(app(OriginPool::class), $origin, $cloudflare);
    }

    public function test_purge_job_gives_up_when_old_host_left_the_pool(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create(['user_id' => $user->id]);
        $this->origin('10.7.0.2');
        $offer = $this->offer($user, 'dead-origin.tech', '10.7.0.2');

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldNotReceive('hostHasOfferIndex');
        $origin->shouldNotReceive('deleteWebRoot');

        (new PurgeOfferFromOriginJob($offer->id, '81.180.92.59'))
            ->handle(app(OriginPool::class), $origin, Mockery::mock(CloudflareClient::class));
    }

    private function origin(
        string $host,
        string $hoster = '',
        string $role = OriginServer::ROLE_POOL,
        string $health = 'ok',
    ): OriginServer {
        return OriginServer::query()->create([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'hoster' => $hoster !== '' ? $hoster : null,
            'role' => $role,
            'is_active' => true,
            'health' => ['status' => $health],
        ]);
    }

    private function offer(User $user, string $domain, string $host): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'MoveBrand',
            'domain' => $domain,
            'geo' => 'HR',
            'lang' => 'hr',
            'template' => 'default',
            'status' => 'deployed',
            'deploy_panel_name' => $host,
            'infra_meta' => ['deploy_host' => $host],
            'from_search_team' => false,
        ]);
    }
}
