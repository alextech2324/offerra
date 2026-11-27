<?php

namespace Tests\Feature;

use App\Jobs\ArchiveOfferJob;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OfferPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_trash_enqueues_purge_not_archive(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'dead-brand.com');

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->delete(route('offers.destroy', $offer))
            ->assertRedirect(route('offers.index'));

        $offer->refresh();
        $this->assertSame('purging', $offer->status);
        $this->assertTrue((bool) ($offer->teardown_meta['purge'] ?? false));

        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_archive_trash_deletes_row_from_database(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'gone-from-archive.com', 'archived');

        $this->actingAs($user)
            ->from(route('offers.archive.index'))
            ->delete(route('offers.destroy', $offer))
            ->assertRedirect(route('offers.archive.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('offers', ['id' => $offer->id]);
        Queue::assertNothingPushed();
    }

    public function test_archive_trash_deletes_teardown_failed_row(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'failed-teardown.com', 'teardown_failed');

        $this->actingAs($user)
            ->from(route('offers.archive.index'))
            ->delete(route('offers.destroy', $offer))
            ->assertRedirect(route('offers.archive.index'));

        $this->assertDatabaseMissing('offers', ['id' => $offer->id]);
        Queue::assertNothingPushed();
    }

    public function test_cannot_delete_someone_elses_archived_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->makeOffer($owner, 'not-your-archive.com', 'archived');

        $this->actingAs($other)
            ->delete(route('offers.destroy', $offer))
            ->assertForbidden();

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'archived']);
        Queue::assertNothingPushed();
    }

    public function test_cannot_purge_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->makeOffer($owner, 'other-brand.com');

        $this->actingAs($other)
            ->delete(route('offers.destroy', $offer))
            ->assertForbidden();

        $this->assertSame('generated', $offer->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_stale_dead_banner_still_archives(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $offer = $this->makeOffer($admin, 'keep-in-archive.com');

        $this->actingAs($admin)
            ->post(route('offers.archive', $offer))
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('archiving', $offer->status);
        $this->assertFalse((bool) ($offer->teardown_meta['purge'] ?? false));
    }

    private function makeOffer(User $user, string $domain, string $status = 'generated'): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'DeadBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => $status,
            'from_search_team' => false,
        ]);
    }
}
