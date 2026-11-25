<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferBrandFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_brand_filter_ignores_letter_case(): void
    {
        $user = User::factory()->create();

        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'mzancexai.online',
            'brand' => 'Mzancexai',
            'domain' => 'mzancexai.online',
            'geo' => 'XX',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'archived',
            'archived_at' => now(),
            'from_search_team' => false,
        ]);

        $this->actingAs($user)
            ->get(route('offers.archive.index', ['brand' => 'MzancexAI']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Archive')
                ->has('offers.data', 1)
                ->where('offers.data.0.domain', 'mzancexai.online'));
    }

    public function test_brand_lookup_counts_archived_rows_case_insensitively(): void
    {
        $user = User::factory()->create();

        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'mzancexai.click',
            'brand' => 'Mzancexai',
            'domain' => 'mzancexai.click',
            'geo' => 'XX',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'archived',
            'archived_at' => now(),
            'from_search_team' => false,
        ]);

        $this->actingAs($user)
            ->getJson(route('offers.brand-lookup', ['brand' => 'MzancexAI']))
            ->assertOk()
            ->assertJsonPath('active', 0)
            ->assertJsonPath('archived', 1);
    }

    public function test_brand_lookup_counts_every_owners_funnel_when_user_cannot_see_all_offers(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create([
            'can_see_all_offers' => false,
            'can_manage_all_offers' => false,
        ]);

        Offer::query()->create([
            'user_id' => $owner->id,
            'folder' => 'spire-live',
            'brand' => 'Spire Bondtron',
            'domain' => 'spire-live.com',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'aurel',
            'status' => 'deployed',
            'from_search_team' => false,
        ]);
        Offer::query()->create([
            'user_id' => $owner->id,
            'folder' => 'spire-old',
            'brand' => 'Spire Bondtron',
            'domain' => 'spire-old.com',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'solano',
            'status' => 'archived',
            'archived_at' => now(),
            'from_search_team' => false,
        ]);

        $this->actingAs($viewer)
            ->getJson(route('offers.brand-lookup', ['brand' => 'spire bondtron']))
            ->assertOk()
            ->assertJsonPath('active', 1)
            ->assertJsonPath('archived', 1)
            ->assertJsonPath('templates.aurel', 1);
    }
}
