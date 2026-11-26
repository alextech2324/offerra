<?php

namespace Tests\Feature;

use App\Jobs\ArchiveOfferJob;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OfferManageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_archive_own_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $offer = $this->makeOffer($owner, 'owned-by-user.com');

        $this->actingAs($owner)
            ->from(route('offers.index'))
            ->post(route('offers.archive', $offer))
            ->assertRedirect();

        $this->assertSame('archiving', $offer->fresh()->status);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_regular_user_cannot_archive_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->makeOffer($owner, 'not-mine.com');

        $this->actingAs($other)
            ->post(route('offers.archive', $offer))
            ->assertForbidden();

        $this->assertSame('generated', $offer->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_see_all_without_manage_cannot_archive_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $viewer = User::factory()->seeAllOffers()->create();
        $offer = $this->makeOffer($owner, 'view-only.com');

        $this->actingAs($viewer)
            ->post(route('offers.archive', $offer))
            ->assertForbidden();

        $this->assertSame('generated', $offer->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_manage_all_user_can_archive_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $manager = User::factory()->manageAllOffers()->create();
        $offer = $this->makeOffer($owner, 'managed-by-teammate.com');

        $this->actingAs($manager)
            ->from(route('offers.index'))
            ->post(route('offers.archive', $offer))
            ->assertRedirect();

        $this->assertSame('archiving', $offer->fresh()->status);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_admin_can_archive_any_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $offer = $this->makeOffer($owner, 'archived-by-admin.com');

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.archive', $offer))
            ->assertRedirect();

        $this->assertSame('archiving', $offer->fresh()->status);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_regular_user_can_bulk_archive_own_stale_dead(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('offers.archive.index'))
            ->post(route('offers.stale-dead.archive'))
            ->assertRedirect();
    }

    public function test_regular_user_cannot_restore_someone_elses_offer(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->makeOffer($owner, 'archived-other.com', 'archived');

        $this->actingAs($other)
            ->post(route('offers.restore', $offer))
            ->assertForbidden();

        $this->assertSame('archived', $offer->fresh()->status);
    }

    public function test_manage_all_user_is_allowed_to_restore_someone_elses_offer(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->manageAllOffers()->create();
        $offer = $this->makeOffer($owner, 'restore-other.com', 'archived');

        $this->actingAs($manager)
            ->from(route('offers.archive.index'))
            ->post(route('offers.restore', $offer))
            ->assertRedirect();
    }

    public function test_manage_all_user_can_purge_someone_elses_offer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $manager = User::factory()->manageAllOffers()->create();
        $offer = $this->makeOffer($owner, 'purge-other.com');

        $this->actingAs($manager)
            ->from(route('offers.index'))
            ->delete(route('offers.destroy', $offer))
            ->assertRedirect();

        $this->assertSame('purging', $offer->fresh()->status);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_admin_can_grant_manage_all_access(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('users.access.update', $user), [
                'can_see_all_offers' => true,
                'can_manage_all_offers' => true,
            ])
            ->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->can_see_all_offers);
        $this->assertTrue($user->can_manage_all_offers);
        $this->assertTrue($user->canManageAllOffers());
    }

    public function test_manage_all_implies_see_all(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('users.access.update', $user), [
                'can_see_all_offers' => false,
                'can_manage_all_offers' => true,
            ])
            ->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->can_see_all_offers);
        $this->assertTrue($user->can_manage_all_offers);
    }

    private function makeOffer(User $user, string $domain, string $status = 'generated'): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'AccessBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => $status,
            'from_search_team' => false,
        ]);
    }
}
