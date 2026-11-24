<?php

namespace Tests\Unit;

use App\Models\Offer;
use App\Models\User;
use App\Models\WhiteLanding;
use App\Support\CloakOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CloakOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_input_defaults_to_off(): void
    {
        $cloak = CloakOptions::fromInput([]);

        $this->assertFalse($cloak['enabled']);
        $this->assertNull($cloak['white_landing_id']);
    }

    public function test_from_input_requires_white_when_enabled(): void
    {
        $cloak = CloakOptions::fromInput([
            'cloak_enabled' => true,
            'white_landing_id' => '',
        ]);

        $this->assertFalse($cloak['enabled']);
        $this->assertNull($cloak['white_landing_id']);
    }

    public function test_merge_writes_shared_googlebot_and_geo_filters(): void
    {
        $meta = CloakOptions::mergeIntoInfraMeta(['options' => ['edge' => 'proxy']], [
            'enabled' => true,
            'white_landing_id' => 7,
        ]);

        $this->assertSame('proxy', $meta['options']['edge']);
        $this->assertSame([
            'enabled' => true,
            'white_landing_id' => 7,
            'filters' => ['googlebot', 'geo'],
        ], $meta['cloak']);
    }

    public function test_merge_off_drops_cloak_key(): void
    {
        $meta = CloakOptions::mergeIntoInfraMeta([
            'options' => ['edge' => 'cloudflare'],
            'cloak' => ['enabled' => true, 'white_landing_id' => 3, 'filters' => []],
        ], [
            'enabled' => false,
            'white_landing_id' => null,
        ]);

        $this->assertArrayNotHasKey('cloak', $meta);
        $this->assertSame('cloudflare', $meta['options']['edge']);
    }

    public function test_resolve_for_user_rejects_foreign_white(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $white = WhiteLanding::query()->create([
            'user_id' => $owner->id,
            'name' => 'News',
            'entry_file' => 'index.html',
        ]);

        $this->expectException(InvalidArgumentException::class);

        CloakOptions::resolveForUser($other, [
            'cloak_enabled' => true,
            'white_landing_id' => $white->id,
        ]);
    }

    public function test_from_offer_follows_columns(): void
    {
        $user = User::factory()->create();
        $white = WhiteLanding::query()->create([
            'user_id' => $user->id,
            'name' => 'News',
            'entry_file' => 'index.html',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cloak.test',
            'brand' => 'Cloak',
            'domain' => 'cloak.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'cloak_enabled' => true,
            'white_landing_id' => $white->id,
        ]);

        $this->assertSame([
            'enabled' => true,
            'white_landing_id' => $white->id,
        ], CloakOptions::fromOffer($offer));
    }
}
