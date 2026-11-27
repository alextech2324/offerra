<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Services\EgoNewBrandAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EgoNewBrandAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'offerra.ego_brand_alert.user' => 'EGO',
            'offerra.ego_brand_alert.bot_token' => 'test-token',
            'offerra.ego_brand_alert.chat_id' => '-1003839727894',
        ]);
    }

    public function test_notifies_when_ego_creates_a_brand_we_never_had(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $ego = User::factory()->create(['name' => 'EGO']);
        $offer = $this->makeOffer($ego, 'Tredify AI', 'ML', 'en');

        $this->assertTrue(app(EgoNewBrandAlertService::class)->maybeNotify($offer));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains((string) $request->url(), 'bottest-token/sendMessage')
                && ($data['chat_id'] ?? '') === '-1003839727894'
                && str_contains((string) ($data['text'] ?? ''), 'Tredify AI')
                && str_contains((string) ($data['text'] ?? ''), 'ML')
                && str_contains((string) ($data['text'] ?? ''), 'en');
        });
    }

    public function test_skips_when_brand_already_exists_for_anyone(): void
    {
        Http::fake();

        $other = User::factory()->create(['name' => 'VOL']);
        $this->makeOffer($other, 'Tredify AI', 'IT', 'it');

        $ego = User::factory()->create(['name' => 'EGO']);
        $offer = $this->makeOffer($ego, 'tredify ai', 'ML', 'en');

        $this->assertFalse(app(EgoNewBrandAlertService::class)->maybeNotify($offer));
        Http::assertNothingSent();
    }

    public function test_skips_non_ego_users(): void
    {
        Http::fake();

        $vol = User::factory()->create(['name' => 'VOL']);
        $offer = $this->makeOffer($vol, 'OnlyVolBrand', 'IT', 'it');

        $this->assertFalse(app(EgoNewBrandAlertService::class)->maybeNotify($offer));
        Http::assertNothingSent();
    }

    public function test_today_backfill_sends_each_new_brand_once_with_all_geos(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $ego = User::factory()->create(['name' => 'EGO']);
        $this->makeOffer($ego, 'Bitdegura', 'JP', 'ja')->forceFill([
            'created_at' => now()->subDays(3),
        ])->save();
        $this->makeOffer($ego, 'Quantezaglia', 'IT', 'it');
        $this->makeOffer($ego, 'Quantezaglia', 'ML', 'en');

        $result = app(EgoNewBrandAlertService::class)->notifyNewSince(now()->startOfDay());

        $this->assertSame(1, $result['sent']);
        $this->assertSame(['Quantezaglia'], $result['brands']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, 'Quantezaglia')
                && str_contains($text, 'IT')
                && str_contains($text, 'ML')
                && ! str_contains($text, 'Bitdegura');
        });
    }

    private function makeOffer(User $user, string $brand, string $geo, string $lang): Offer
    {
        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => strtolower($brand).'-'.$geo.'-'.$lang.'-'.uniqid(),
            'brand' => $brand,
            'domain' => strtolower($brand).'-'.$geo.'.test',
            'geo' => $geo,
            'lang' => $lang,
            'template' => 'bluegrove',
            'status' => 'generated',
            'from_search_team' => false,
        ]);
    }
}
