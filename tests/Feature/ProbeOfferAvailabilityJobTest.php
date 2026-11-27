<?php

namespace Tests\Feature;

use App\Jobs\ProbeOfferAvailabilityJob;
use App\Jobs\SubmitOfferToGscJob;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferAvailabilityProbe;
use App\Services\OfferGscSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProbeOfferAvailabilityJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_ready_probes_immediately_without_google(): void
    {
        Queue::fake();
        [$offer] = $this->makeOffer();

        app(OfferGscSubmitter::class)->queueAfterDns($offer);

        Queue::assertPushed(ProbeOfferAvailabilityJob::class, fn (ProbeOfferAvailabilityJob $job) => $job->offerId === $offer->id);
    }

    public function test_red_probe_marks_down_and_does_not_submit_gsc(): void
    {
        Queue::fake();
        [$offer] = $this->makeOffer();
        $this->fakeProbe($offer, ok: false, streak: 1);

        (new ProbeOfferAvailabilityJob($offer->id))->handle(
            app(OfferAvailabilityProbe::class),
            app(OfferGscSubmitter::class),
        );

        $offer->refresh();
        $this->assertSame('down', $offer->availability_status);
        $this->assertSame(1, (int) $offer->availability_fail_streak);
        Queue::assertNotPushed(SubmitOfferToGscJob::class);
    }

    public function test_tenth_red_probe_stops_retrying(): void
    {
        Queue::fake();
        [$offer] = $this->makeOffer();
        $offer->forceFill(['availability_fail_streak' => 9])->save();
        $this->fakeProbe($offer, ok: false, streak: 10);

        (new ProbeOfferAvailabilityJob($offer->id))->handle(
            app(OfferAvailabilityProbe::class),
            app(OfferGscSubmitter::class),
        );

        $this->assertSame('down', $offer->fresh()->availability_status);
        $this->assertSame(10, (int) $offer->fresh()->availability_fail_streak);
        Queue::assertNotPushed(SubmitOfferToGscJob::class);
    }

    public function test_green_probe_submits_indexing_immediately(): void
    {
        Queue::fake();
        config(['services.google.client_id' => 'test-client']);
        [$offer, $user] = $this->makeOffer();
        $user->settings->forceFill([
            'google_oauth_refresh_token' => 'refresh-token',
            'gsc_verification_filename' => 'googleabc.html',
        ])->save();
        $this->fakeProbe($offer, ok: true, streak: 0);

        (new ProbeOfferAvailabilityJob($offer->id))->handle(
            app(OfferAvailabilityProbe::class),
            app(OfferGscSubmitter::class),
        );

        Queue::assertPushed(SubmitOfferToGscJob::class, fn (SubmitOfferToGscJob $job) => $job->offerId === $offer->id);
    }

    /**
     * @return array{0: Offer, 1: User}
     */
    private function makeOffer(): array
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'probe-dns.live',
            'brand' => 'ProbeDns',
            'domain' => 'probe-dns.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'availability_status' => 'unchecked',
            'availability_fail_streak' => 0,
            'infra_meta' => ['dns' => 'done'],
        ]);

        return [$offer, $user];
    }

    private function fakeProbe(Offer $offer, bool $ok, int $streak): void
    {
        $probe = Mockery::mock(OfferAvailabilityProbe::class);
        $probe->shouldReceive('checkAndUpdate')
            ->once()
            ->withArgs(fn (Offer $passed, bool $immediate) => $passed->id === $offer->id && $immediate === true)
            ->andReturnUsing(function (Offer $passed) use ($ok, $streak): array {
                $passed->forceFill([
                    'availability_status' => $ok ? 'ok' : 'down',
                    'availability_fail_streak' => $streak,
                    'availability_error' => $ok ? null : 'HTTPS timeout',
                    'availability_checked_at' => now(),
                ])->save();

                return [
                    'status' => $ok ? 'ok' : 'down',
                    'error' => $ok ? null : 'HTTPS timeout',
                    'changed' => true,
                ];
            });
        $this->app->instance(OfferAvailabilityProbe::class, $probe);
    }
}
