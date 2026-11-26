<?php

namespace Tests\Feature;

use App\Jobs\ArchiveCfAbuseOfferJob;
use App\Jobs\ArchiveOfferJob;
use App\Models\CloudflareAbuseReport;
use App\Models\CloudflareAccount;
use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CloudflareAbusePollService;
use App\Services\CloudflareClient;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudflareAbusePollTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_abuse_reports_parses_nested_result(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/acct1/abuse-reports*' => Http::response([
                'success' => true,
                'result' => [
                    'reports' => [
                        [
                            'id' => 'rep-1',
                            'domain' => 'example.com',
                            'type' => 'PHISH',
                            'status' => 'accepted',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new CloudflareClient)->listAbuseReports('cf-token', 'acct1');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['forbidden']);
        $this->assertSame('rep-1', $result['reports'][0]['id'] ?? null);
    }

    public function test_list_abuse_reports_marks_forbidden_on_403(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/acct1/abuse-reports*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Authentication error']],
            ], 403),
        ]);

        $result = (new CloudflareClient)->listAbuseReports('cf-token', 'acct1');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['forbidden']);
        $this->assertSame([], $result['reports']);
    }

    public function test_new_phish_report_notifies_live_offer_owner_once(): void
    {
        Queue::fake();
        [$offer] = $this->liveOfferWithTelegram('phish-me.live');

        Http::fake($this->cfReportsFake('acct1', [
            ['id' => 'rep-phish', 'domain' => 'phish-me.live', 'type' => 'PHISH', 'status' => 'accepted'],
        ]));

        $this->mock(TelegramNotifier::class, function ($mock) use ($offer): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function ($settings, string $text) use ($offer) {
                    $user = $offer->user;
                    $created = $offer->created_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i');

                    return str_contains($text, 'Cloudflare abuse')
                        && str_contains($text, $offer->domain)
                        && str_contains($text, 'PHISH')
                        && str_contains($text, (string) $user?->name)
                        && str_contains($text, (string) $user?->email)
                        && str_contains($text, 'GEO: IT / it')
                        && str_contains($text, 'Шаблон: default')
                        && str_contains($text, 'Автоматично заархівується через 3 хв')
                        && $created !== null
                        && str_contains($text, 'Створено: '.$created);
                })
                ->andReturn(true);
        });

        $first = app(CloudflareAbusePollService::class)->poll();
        $this->assertSame(1, $first['new']);
        $this->assertSame(1, $first['notified']);

        $second = app(CloudflareAbusePollService::class)->poll();
        $this->assertSame(0, $second['new']);
        $this->assertSame(0, $second['notified']);

        $this->assertDatabaseHas('cloudflare_abuse_reports', [
            'cf_report_id' => 'rep-phish',
            'domain' => 'phish-me.live',
            'offer_id' => $offer->id,
        ]);
        $this->assertNotNull(CloudflareAbuseReport::query()->where('cf_report_id', 'rep-phish')->value('notified_at'));
        $this->assertNotNull($offer->fresh()->cf_abuse_at);
        $this->assertSame('deployed', $offer->fresh()->status);
        Queue::assertPushed(ArchiveCfAbuseOfferJob::class, function (ArchiveCfAbuseOfferJob $job) use ($offer) {
            if ($job->offerId !== $offer->id || $job->delay === null) {
                return false;
            }

            $seconds = $job->delay->getTimestamp() - now()->getTimestamp();

            return $seconds >= 170 && $seconds <= 190;
        });
        Queue::assertNotPushed(ArchiveOfferJob::class);
    }

    public function test_auto_archive_job_archives_live_abused_offer(): void
    {
        Queue::fake();

        [$offer] = $this->liveOfferWithTelegram('auto-archive.live');
        $offer->forceFill(['cf_abuse_at' => now()->subMinutes(3)])->save();

        (new ArchiveCfAbuseOfferJob($offer->id))->handle(app(CloudflareAbusePollService::class));

        $this->assertSame('archiving', $offer->fresh()->status);
        $this->assertSame(Offer::ARCHIVE_REASON_CF_ABUSE, $offer->fresh()->archive_reason);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_auto_archive_job_skips_already_archived_offer(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->settingsWithCf($user, telegram: true);
        $offer = $this->makeOffer($user, 'already-archived.live', 'archived', Offer::ARCHIVE_REASON_CF_ABUSE);
        $offer->forceFill(['cf_abuse_at' => now()->subMinutes(5)])->save();

        (new ArchiveCfAbuseOfferJob($offer->id))->handle(app(CloudflareAbusePollService::class));

        $this->assertSame('archived', $offer->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_poll_archives_offers_whose_abuse_is_older_than_three_minutes(): void
    {
        Queue::fake();

        [$offer] = $this->liveOfferWithTelegram('due-archive.live');
        $offer->forceFill(['cf_abuse_at' => now()->subMinutes(4)])->save();

        Http::fake($this->cfReportsFake('acct1', []));

        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        $stats = app(CloudflareAbusePollService::class)->poll();

        $this->assertSame(1, $stats['archived']);
        $this->assertSame('archiving', $offer->fresh()->status);
        $this->assertSame(Offer::ARCHIVE_REASON_CF_ABUSE, $offer->fresh()->archive_reason);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_archived_offer_is_stored_without_telegram(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->settingsWithCf($user);
        $this->makeOffer($user, 'already-gone.live', 'archived');

        Http::fake($this->cfReportsFake('acct1', [
            ['id' => 'rep-old', 'domain' => 'already-gone.live', 'type' => 'PHISH', 'status' => 'accepted'],
        ]));

        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        $stats = app(CloudflareAbusePollService::class)->poll();

        $this->assertSame(1, $stats['new']);
        $this->assertSame(0, $stats['notified']);
        $this->assertDatabaseHas('cloudflare_abuse_reports', [
            'cf_report_id' => 'rep-old',
            'domain' => 'already-gone.live',
        ]);
    }

    public function test_dmca_is_stored_without_telegram(): void
    {
        Queue::fake();
        $this->liveOfferWithTelegram('copyright.live');

        Http::fake($this->cfReportsFake('acct1', [
            ['id' => 'rep-dmca', 'domain' => 'copyright.live', 'type' => 'DMCA', 'status' => 'accepted'],
        ]));

        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        $stats = app(CloudflareAbusePollService::class)->poll();

        $this->assertSame(1, $stats['new']);
        $this->assertSame(0, $stats['notified']);
        Queue::assertNotPushed(ArchiveCfAbuseOfferJob::class);
    }

    public function test_duplicate_cloudflare_slots_make_one_http_request(): void
    {
        $first = User::factory()->create();
        $this->settingsWithCf($first);
        $second = User::factory()->create();
        $this->settingsWithCf($second);

        CloudflareAccount::query()->create([
            'name' => 'Spare same',
            'api_token' => 'cf-token',
            'account_id' => 'acct1',
            'is_active' => true,
        ]);

        Http::fake($this->cfReportsFake('acct1', []));

        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        $stats = app(CloudflareAbusePollService::class)->poll();

        $this->assertSame(1, $stats['accounts']);
        Http::assertSentCount(1);
    }

    public function test_forbidden_does_not_throw(): void
    {
        $user = User::factory()->create();
        $this->settingsWithCf($user);

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/acct1/abuse-reports*' => Http::response([
                'success' => false,
            ], 403),
        ]);

        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->never();
        });

        $first = app(CloudflareAbusePollService::class)->poll();
        $second = app(CloudflareAbusePollService::class)->poll();

        $this->assertSame(1, $first['forbidden']);
        $this->assertSame(1, $second['forbidden']);
        $this->assertSame(0, $first['errors']);
    }

    public function test_manual_archive_after_report_marks_cf_abuse(): void
    {
        Queue::fake();

        [$offer, $user] = $this->liveOfferWithTelegram('reported.live');
        $admin = User::factory()->admin()->create();

        CloudflareAbuseReport::query()->create([
            'cloudflare_account_id' => 'acct1',
            'cf_report_id' => 'rep-now',
            'domain' => 'reported.live',
            'type' => 'PHISH',
            'offer_id' => $offer->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.archive', $offer))
            ->assertRedirect();

        $this->assertSame('archiving', $offer->fresh()->status);
        $this->assertSame(Offer::ARCHIVE_REASON_CF_ABUSE, $offer->fresh()->archive_reason);
        Queue::assertPushed(ArchiveOfferJob::class);
    }

    public function test_admin_sees_abuse_banner_count_and_can_archive_all(): void
    {
        Queue::fake();

        [$offer] = $this->liveOfferWithTelegram('banner-phish.live');
        $offer->forceFill(['cf_abuse_at' => now(), 'status' => 'deployed'])->save();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('offers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Index')
                ->where('abuseCount', 1)
                ->where('offers.data.0.cf_abuse', true));

        $this->actingAs($admin)
            ->from(route('offers.index'))
            ->post(route('offers.cf-abuse.archive'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('archiving', $offer->fresh()->status);
        $this->assertSame(Offer::ARCHIVE_REASON_CF_ABUSE, $offer->fresh()->archive_reason);
        Queue::assertPushed(ArchiveOfferJob::class, fn (ArchiveOfferJob $job) => $job->offerId === $offer->id);
    }

    public function test_regular_user_cannot_bulk_archive_cf_abuse(): void
    {
        Queue::fake();

        [$offer] = $this->liveOfferWithTelegram('no-bulk.live');
        $offer->forceFill(['cf_abuse_at' => now()])->save();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('offers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('abuseCount', 0));

        $this->actingAs($user)
            ->post(route('offers.cf-abuse.archive'))
            ->assertForbidden();

        $this->assertSame('deployed', $offer->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_orange_filter_lists_only_cf_abuse_offers(): void
    {
        $user = User::factory()->admin()->create();
        $abused = $this->makeOffer($user, 'orange-dot.live', 'deployed');
        $abused->forceFill(['cf_abuse_at' => now(), 'availability_status' => 'ok'])->save();
        $this->makeOffer($user, 'still-green.live', 'deployed');

        $this->actingAs($user)
            ->get(route('offers.index', ['availability' => 'abuse']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('offers.total', 1)
                ->where('offers.data.0.domain', 'orange-dot.live')
                ->where('offers.data.0.cf_abuse', true));
    }

    public function test_archive_page_filters_by_reason(): void
    {
        $user = User::factory()->create();
        $this->makeOffer($user, 'dead-reason.live', 'archived', Offer::ARCHIVE_REASON_STALE_DEAD);
        $this->makeOffer($user, 'abuse-reason.live', 'archived', Offer::ARCHIVE_REASON_CF_ABUSE);

        $this->actingAs($user)
            ->get(route('offers.archive.index', ['archive_reason' => 'cf_abuse']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Archive')
                ->where('offers.data.0.domain', 'abuse-reason.live')
                ->where('offers.data.0.archive_reason', 'cf_abuse')
                ->where('offers.total', 1));
    }

    public function test_artisan_poll_command_runs(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*/abuse-reports*' => Http::response([
                'success' => true,
                'result' => ['reports' => []],
            ]),
        ]);

        $this->artisan('cloudflare:poll-abuse')
            ->expectsOutputToContain('cf_abuse accounts=0')
            ->assertSuccessful();
    }

    /**
     * @return array{0: Offer, 1: User}
     */
    private function liveOfferWithTelegram(string $domain): array
    {
        $user = User::factory()->create();
        $this->settingsWithCf($user, telegram: true);
        $offer = $this->makeOffer($user, $domain, 'deployed');

        return [$offer, $user];
    }

    private function settingsWithCf(User $user, bool $telegram = false): UserSetting
    {
        return UserSetting::query()->create([
            'user_id' => $user->id,
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct1',
            'tg_bot_token' => $telegram ? 'bot-token' : null,
            'tg_chat_id' => $telegram ? '123' : null,
        ]);
    }

    private function makeOffer(User $user, string $domain, string $status, ?string $reason = null): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'AbuseBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => $status,
            'archived_at' => $status === 'archived' ? now() : null,
            'archive_reason' => $reason,
            'from_search_team' => false,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return array<string, mixed>
     */
    private function cfReportsFake(string $accountId, array $reports): array
    {
        return [
            'https://api.cloudflare.com/client/v4/accounts/'.$accountId.'/abuse-reports*' => Http::response([
                'success' => true,
                'result' => ['reports' => $reports],
            ]),
        ];
    }
}
