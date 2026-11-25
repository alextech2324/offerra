<?php

namespace Tests\Feature;

use App\Jobs\ScanDownOffersJob;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferAvailabilityProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanDownOffersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_queues_scan_of_every_red_dot(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->makeOffer($owner, 'red-own.live', 'down');
        $this->makeOffer($owner, 'green-own.live', 'ok');
        $other = User::factory()->create();
        $this->makeOffer($other, 'red-other.live', 'down');

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.scan-availability'))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(
            ScanDownOffersJob::class,
            fn (ScanDownOffersJob $job) => $job->userId === null && $job->offerIds === null,
        );
    }

    public function test_regular_user_scan_is_scoped_to_own_reds(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeOffer($user, 'mine-red.live', 'down');
        $other = User::factory()->create();
        $this->makeOffer($other, 'theirs-red.live', 'down');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.scan-availability'))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(
            ScanDownOffersJob::class,
            fn (ScanDownOffersJob $job) => $job->userId === $user->id && $job->offerIds === null,
        );
    }

    public function test_scan_with_no_reds_does_not_queue(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeOffer($user, 'still-green.live', 'ok');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.scan-availability'))
            ->assertRedirect()
            ->assertSessionHas('warning');

        Queue::assertNothingPushed();
    }

    public function test_bulk_check_availability_queues_selected_ids(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $down = $this->makeOffer($user, 'bulk-red.live', 'down');
        $ok = $this->makeOffer($user, 'bulk-green.live', 'ok');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.bulk-action'), [
                'ids' => [$down->id, $ok->id],
                'action' => 'check_availability',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ScanDownOffersJob::class, function (ScanDownOffersJob $job) use ($down, $ok) {
            return $job->offerIds === [$down->id, $ok->id] || $job->offerIds === [$ok->id, $down->id];
        });
    }

    public function test_job_probes_only_down_offers_for_scoped_user(): void
    {
        $user = User::factory()->create();
        $down = $this->makeOffer($user, 'probe-red.live', 'down');
        $ok = $this->makeOffer($user, 'probe-green.live', 'ok');
        $other = User::factory()->create();
        $foreign = $this->makeOffer($other, 'probe-foreign.live', 'down');

        $probe = $this->mock(OfferAvailabilityProbe::class);
        $probe->shouldReceive('checkAndUpdateMany')
            ->once()
            ->andReturnUsing(function (array $offers) use ($down) {
                $this->assertCount(1, $offers);
                $this->assertSame($down->id, $offers[0]->id);

                return ['ok' => 1, 'down' => 0, 'soft_fail' => 0];
            });

        (new ScanDownOffersJob($user->id))->handle($probe);

        $this->assertSame('ok', $ok->fresh()->availability_status);
        $this->assertSame('down', $foreign->fresh()->availability_status);
    }

    public function test_admin_queues_scan_of_every_deployed_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->makeOffer($owner, 'green-all.live', 'ok');
        $this->makeOffer($owner, 'red-all.live', 'down');

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.scan-availability'), ['all' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(
            ScanDownOffersJob::class,
            fn (ScanDownOffersJob $job) => $job->userId === null
                && $job->offerIds === null
                && $job->allDeployed === true,
        );
    }

    public function test_regular_user_cannot_queue_all_deployed_scan(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeOffer($user, 'mine-green.live', 'ok');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->post(route('offers.scan-availability'), ['all' => 1])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_job_probes_every_deployed_offer_when_all_flag_set(): void
    {
        $user = User::factory()->create();
        $down = $this->makeOffer($user, 'all-red.live', 'down');
        $ok = $this->makeOffer($user, 'all-green.live', 'ok');
        $other = User::factory()->create();
        $foreign = $this->makeOffer($other, 'all-foreign.live', 'ok');
        $draft = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'all-draft.live',
            'brand' => 'ScanBrand',
            'domain' => 'all-draft.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'vespera',
            'status' => 'draft',
            'from_search_team' => false,
            'availability_status' => 'ok',
        ]);

        $probe = $this->mock(OfferAvailabilityProbe::class);
        $probe->shouldReceive('checkAndUpdateMany')
            ->once()
            ->andReturnUsing(function (array $offers) use ($down, $ok, $foreign, $draft) {
                $ids = collect($offers)->pluck('id')->sort()->values()->all();
                $this->assertSame(
                    collect([$down->id, $ok->id, $foreign->id])->sort()->values()->all(),
                    $ids,
                );
                $this->assertNotContains($draft->id, $ids);

                return ['ok' => 2, 'down' => 1, 'soft_fail' => 0];
            });

        (new ScanDownOffersJob(null, null, true))->handle($probe);
    }

    public function test_job_sits_on_gsc_queue_and_is_unique_per_scope(): void
    {
        $all = new ScanDownOffersJob(null);
        $mine = new ScanDownOffersJob(7);
        $ids = new ScanDownOffersJob(null, [9, 3, 3, 1]);
        $allDeployed = new ScanDownOffersJob(null, null, true);

        $this->assertSame('gsc', $all->queue);
        $this->assertSame('scan-availability:down:all', $all->uniqueId());
        $this->assertSame('scan-availability:down:7', $mine->uniqueId());
        $this->assertSame([1, 3, 9], $ids->offerIds);
        $this->assertSame('scan-availability:ids:'.md5('1,3,9'), $ids->uniqueId());
        $this->assertSame('scan-availability:all:all', $allDeployed->uniqueId());
    }

    private function makeOffer(User $user, string $domain, string $availability): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'ScanBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'vespera',
            'status' => 'deployed',
            'from_search_team' => false,
            'availability_status' => $availability,
        ]);
    }
}
