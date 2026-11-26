<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferStat;
use App\Models\User;
use App\Services\StaleDeadOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaleDeadOfferServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaleDeadOfferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 15:00:00');
        Storage::fake('local');
        $this->service = $this->app->make(StaleDeadOfferService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_zero_brand_is_a_candidate_after_gsc_window(): void
    {
        $user = User::factory()->create();
        $dead = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));

        $this->assertCandidateIds([$dead->id]);
    }

    public function test_recent_lead_on_sibling_protects_zero_domain(): void
    {
        $user = User::factory()->create();
        $zero = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $live = $this->makeOffer($user, 'Acme', 'acme.com', now()->subDays(40));
        $this->setStats($live, lastLeadAt: now()->subDays(10), leads: 2);

        $this->assertCandidateIds([]);
        $this->assertFalse($this->candidateIds()->contains($zero->id));
    }

    public function test_brand_match_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        $this->makeOffer($user, 'Mzancexai', 'mzancexai.online', now()->subDays(40));
        $live = $this->makeOffer($user, 'MzancexAI', 'mzancex-ai.com', now()->subDays(40));
        $this->setStats($live, lastLeadAt: now()->subDays(3), leads: 1);

        $this->assertCandidateIds([]);
    }

    public function test_old_lead_does_not_protect_brand(): void
    {
        $user = User::factory()->create();
        $zero = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $stale = $this->makeOffer($user, 'Acme', 'acme.com', now()->subDays(40));
        $this->setStats($stale, lastLeadAt: now()->subDays(40), leads: 5);

        $this->assertCandidateIds([$zero->id, $stale->id]);
    }

    public function test_recent_deposit_without_lead_does_not_protect_brand(): void
    {
        $user = User::factory()->create();
        $zero = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $paid = $this->makeOffer($user, 'Acme', 'acme.com', now()->subDays(40));
        $this->setStats($paid, lastDepositAt: now()->subDays(5), deposits: 1);

        $this->assertCandidateIds([$zero->id, $paid->id]);
    }

    public function test_newer_gsc_sibling_shields_the_whole_brand(): void
    {
        $user = User::factory()->create();
        $old = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $fresh = $this->makeOffer($user, 'Acme', 'acme.com', now()->subDays(10));

        $this->assertCandidateIds([]);
        $this->assertFalse($this->candidateIds()->contains($old->id));
        $this->assertFalse($this->candidateIds()->contains($fresh->id));
    }

    public function test_lead_older_than_window_does_not_protect(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $this->setStats($offer, lastLeadAt: now()->subDays(15), leads: 3);

        $this->assertCandidateIds([$offer->id]);
    }

    public function test_fourteen_day_old_gsc_is_a_candidate(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(15));

        $this->assertCandidateIds([$offer->id]);
    }

    public function test_other_buyers_brand_does_not_protect(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $dead = $this->makeOffer($buyer, 'Acme', 'acme.online', now()->subDays(40));
        $theirs = $this->makeOffer($other, 'Acme', 'acme-other.com', now()->subDays(40));
        $this->setStats($theirs, lastLeadAt: now()->subDays(2), leads: 1);

        $this->assertCandidateIds([$dead->id]);
    }

    public function test_archived_sibling_with_recent_lead_does_not_protect(): void
    {
        $user = User::factory()->create();
        $dead = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $archived = $this->makeOffer($user, 'Acme', 'acme.com', now()->subDays(40), status: 'archived');
        $this->setStats($archived, lastLeadAt: now()->subDays(2), leads: 1);

        $this->assertCandidateIds([$dead->id]);
    }

    public function test_fresh_gsc_submit_is_not_a_candidate(): void
    {
        $user = User::factory()->create();
        $fresh = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(10));

        $this->assertCandidateIds([]);
        $this->assertFalse($this->candidateIds()->contains($fresh->id));
    }

    public function test_new_unsubmitted_domain_shields_stale_brand(): void
    {
        $user = User::factory()->create();
        $old = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $this->makeOffer($user, 'Acme', 'acme.com', null);

        $this->assertCandidateIds([]);
        $this->assertFalse($this->candidateIds()->contains($old->id));
    }

    public function test_restored_domain_shields_stale_siblings_like_a_new_offer(): void
    {
        $user = User::factory()->create();
        $old = $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $restored = $this->makeOffer($user, 'Acme', 'acme.com', now());
        $restored->forceFill([
            'created_at' => now()->subDays(40),
            'submitted_for_indexing' => false,
        ])->save();

        $this->assertCandidateIds([]);
        $this->assertFalse($this->candidateIds()->contains($old->id));
        $this->assertFalse($this->candidateIds()->contains($restored->id));
    }

    public function test_never_submitted_brand_is_not_a_candidate(): void
    {
        $user = User::factory()->create();
        $this->makeOffer($user, 'Acme', 'acme.online', null);

        $this->assertCandidateIds([]);
    }

    public function test_scan_groups_candidates_by_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->makeOffer($first, 'Acme', 'acme.online', now()->subDays(40));
        $this->makeOffer($second, 'Beta', 'beta.online', now()->subDays(40));
        $this->makeOffer($second, 'Beta', 'beta.com', now()->subDays(40));

        $result = $this->service->scan();

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['by_user'][$first->id]);
        $this->assertSame(2, $result['by_user'][$second->id]);
        $this->assertSame(1, $this->service->hintFor($first)['count']);
        $this->assertSame(2, $this->service->hintFor($second)['count']);
    }

    public function test_banner_stays_empty_until_the_daily_scan(): void
    {
        $user = User::factory()->create();
        $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));

        $hint = $this->service->hintFor($user);

        $this->assertSame(0, $hint['count']);
        $this->assertNull($hint['last_scan_at']);
    }

    public function test_banner_does_not_pick_up_offers_that_age_after_scan(): void
    {
        $user = User::factory()->create();
        $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $this->service->scan();

        $this->makeOffer($user, 'Beta', 'beta.online', now()->subDays(40));

        $this->assertSame(1, $this->service->hintFor($user)['count']);
    }

    public function test_archiving_the_snapshot_hides_the_banner_until_next_scan(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $this->service->scan();
        $this->assertSame(1, $this->service->hintFor($user)['count']);

        $enqueued = $this->service->archiveAllFor($user);

        $this->assertSame(1, $enqueued);
        $this->assertSame(Offer::ARCHIVE_REASON_STALE_DEAD, Offer::query()->where('brand', 'Acme')->value('archive_reason'));
        $this->assertSame(0, $this->service->hintFor($user)['count']);
        $this->assertNotNull($this->service->hintFor($user)['last_scan_at']);

        $this->makeOffer($user, 'Beta', 'beta.online', now()->subDays(40));
        $this->assertSame(0, $this->service->hintFor($user)['count']);
    }

    public function test_regular_user_can_archive_own_snapshot(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeOffer($user, 'Acme', 'acme.online', now()->subDays(40));
        $this->service->scan();

        $enqueued = $this->service->archiveAllFor($user);

        $this->assertSame(1, $enqueued);
        $this->assertSame(0, $this->service->hintFor($user)['count']);
    }

    /**
     * @param  list<int>  $expected
     */
    private function assertCandidateIds(array $expected): void
    {
        $this->assertSame(
            collect($expected)->sort()->values()->all(),
            $this->candidateIds()->sort()->values()->all(),
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function candidateIds()
    {
        return $this->service->baseCandidatesQuery()->orderBy('id')->pluck('id');
    }

    private function makeOffer(
        User $user,
        string $brand,
        string $domain,
        $indexedAt,
        string $status = 'generated',
    ): Offer {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => $brand,
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => $status,
            'indexed_at' => $indexedAt,
            'from_search_team' => false,
        ]);
    }

    private function setStats(
        Offer $offer,
        $lastLeadAt = null,
        $lastDepositAt = null,
        int $leads = 0,
        int $deposits = 0,
    ): OfferStat {
        $stats = $offer->ensureStats();
        $stats->forceFill([
            'leads_count' => $leads,
            'deposits_count' => $deposits,
            'last_lead_at' => $lastLeadAt,
            'last_deposit_at' => $lastDepositAt,
        ])->save();

        return $stats;
    }
}
