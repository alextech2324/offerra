<?php

namespace Tests\Unit;

use App\Jobs\ProbeOfferAvailabilityJob;
use App\Models\Offer;
use App\Models\User;
use App\Services\InfrastructureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettleLiveDnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_deployed_offer_is_settled_without_waiting_on_dns(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'live-dns.test',
            'brand' => 'LiveDns',
            'domain' => 'live-dns.test',
            'geo' => 'BE',
            'lang' => 'fr',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'ok',
            'infra_meta' => [
                'dns' => 'pending',
                'dynadot_ns' => 'done',
            ],
        ]);

        $this->assertSame('pending', $offer->dnsStatus());
        $this->assertTrue(app(InfrastructureProvisioner::class)->settleIfAlreadyLive($offer));

        $offer->refresh();
        $this->assertSame('ready', $offer->dnsStatus());
        $this->assertSame('done', $offer->infra_meta['dns'] ?? null);
        $this->assertSame('https', $offer->infra_meta['dns_via'] ?? null);
    }

    public function test_settling_live_dns_queues_auto_gsc_probe(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'live-gsc.test',
            'brand' => 'LiveGsc',
            'domain' => 'live-gsc.test',
            'geo' => 'ZA',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'ok',
            'infra_meta' => [
                'dns' => 'pending',
                'dynadot_ns' => 'done',
            ],
        ]);

        $this->assertTrue(app(InfrastructureProvisioner::class)->settleIfAlreadyLive($offer));

        Queue::assertPushedOn('gsc', ProbeOfferAvailabilityJob::class);
    }

    public function test_ns_cutover_is_not_settled_just_because_https_is_ok(): void
    {
        $user = User::factory()->create();
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cutover.test',
            'brand' => 'Cutover',
            'domain' => 'cutover.test',
            'geo' => 'BE',
            'lang' => 'fr',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'ok',
            'infra_meta' => [
                'dns' => 'pending',
                'dynadot_ns' => 'pending',
            ],
        ]);

        $this->assertFalse(app(InfrastructureProvisioner::class)->settleIfAlreadyLive($offer));
        $this->assertSame('pending', $offer->fresh()->dnsStatus());
    }
}
