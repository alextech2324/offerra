<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Services\OfferAvailabilityProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CheckOfferAvailabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_red_dots_use_the_same_stale_window_as_green(): void
    {
        $user = User::factory()->create();
        $freshDown = $this->makeOffer($user, 'fresh-down.live', 'down', now()->subMinutes(20));
        $freshOk = $this->makeOffer($user, 'fresh-ok.live', 'ok', now()->subMinutes(20));
        $staleDown = $this->makeOffer($user, 'stale-down.live', 'down', now()->subMinutes(60));
        $staleOk = $this->makeOffer($user, 'stale-ok.live', 'ok', now()->subMinutes(60));

        $probed = [];
        $probe = Mockery::mock(OfferAvailabilityProbe::class);
        $probe->shouldReceive('checkAndUpdateMany')
            ->once()
            ->andReturnUsing(function (array $offers) use (&$probed): array {
                foreach ($offers as $offer) {
                    $probed[] = $offer->id;
                }

                return ['ok' => 0, 'down' => 0, 'soft_fail' => 0];
            });
        $this->app->instance(OfferAvailabilityProbe::class, $probe);

        $this->artisan('offers:check-availability')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$staleDown->id, $staleOk->id], $probed);
        $this->assertNotContains($freshDown->id, $probed);
        $this->assertNotContains($freshOk->id, $probed);
    }

    private function makeOffer(User $user, string $domain, string $availability, $checkedAt): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'AvailBrand',
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'vespera',
            'status' => 'deployed',
            'from_search_team' => false,
            'availability_status' => $availability,
            'availability_checked_at' => $checkedAt,
        ]);
    }
}
