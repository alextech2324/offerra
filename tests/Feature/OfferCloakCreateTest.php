<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Models\WhiteLanding;
use App\Services\OfferGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OfferCloakCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_wizard_lists_own_whites(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $mine = WhiteLanding::query()->create([
            'user_id' => $user->id,
            'name' => 'News IE',
            'entry_file' => 'index.html',
        ]);
        WhiteLanding::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign',
            'entry_file' => 'index.html',
        ]);

        $this->actingAs($user)
            ->get(route('offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Create')
                ->where('whiteLandings', fn ($rows) => collect($rows)->contains('id', $mine->id)));
    }

    public function test_cloak_without_white_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('offers.create'))
            ->post(route('offers.store'), $this->payload([
                'cloak_enabled' => true,
            ]))
            ->assertRedirect(route('offers.create'))
            ->assertSessionHasErrors('white_landing_id');
    }

    public function test_cloak_with_foreign_white_is_rejected(): void
    {
        $user = User::factory()->create();
        $foreign = WhiteLanding::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign',
            'entry_file' => 'index.html',
        ]);

        $this->actingAs($user)
            ->from(route('offers.create'))
            ->post(route('offers.store'), $this->payload([
                'cloak_enabled' => true,
                'white_landing_id' => $foreign->id,
            ]))
            ->assertRedirect(route('offers.create'))
            ->assertSessionHasErrors('white_landing_id');
    }

    public function test_store_passes_cloak_choice_to_generator(): void
    {
        $user = User::factory()->create();
        $white = WhiteLanding::query()->create([
            'user_id' => $user->id,
            'name' => 'News IE',
            'entry_file' => 'index.html',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cloak-store.test',
            'brand' => 'Acme',
            'domain' => 'cloak-store.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
            'cloak_enabled' => true,
            'white_landing_id' => $white->id,
        ]);

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->withArgs(function (User $passed, array $input) use ($user, $white): bool {
                return $passed->is($user)
                    && $input['cloak_enabled'] === true
                    && (int) $input['white_landing_id'] === (int) $white->id;
            })
            ->andReturn([
                'folder' => $offer->folder,
                'path' => sys_get_temp_dir(),
                'offer' => $offer,
                'already_existed' => false,
            ]);
        $this->app->instance(OfferGenerator::class, $generator);

        $this->actingAs($user)
            ->post(route('offers.store'), $this->payload([
                'domain' => 'cloak-store.test',
                'cloak_enabled' => true,
                'white_landing_id' => $white->id,
            ]))
            ->assertRedirect(route('offers.index'));
    }

    public function test_store_without_cloak_leaves_generate_path_unchanged(): void
    {
        $user = User::factory()->create();
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'no-cloak.test',
            'brand' => 'Acme',
            'domain' => 'no-cloak.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'generated',
        ]);

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->withArgs(function (User $passed, array $input): bool {
                return $input['cloak_enabled'] === false
                    && $input['white_landing_id'] === null;
            })
            ->andReturn([
                'folder' => $offer->folder,
                'path' => sys_get_temp_dir(),
                'offer' => $offer,
                'already_existed' => false,
            ]);
        $this->app->instance(OfferGenerator::class, $generator);

        $this->actingAs($user)
            ->post(route('offers.store'), $this->payload([
                'domain' => 'no-cloak.test',
                'cloak_enabled' => false,
            ]))
            ->assertRedirect(route('offers.index'));
    }

    public function test_update_passes_cloak_choice_to_generator(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([
            'crm_api_key' => 'k',
            'tg_bot_token' => 't',
        ]);
        $white = WhiteLanding::query()->create([
            'user_id' => $user->id,
            'name' => 'News IE',
            'entry_file' => 'index.html',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'cloak-edit.test',
            'brand' => 'Acme',
            'domain' => 'cloak-edit.test',
            'geo' => 'IT',
            'lang' => 'it',
            'min_deposit' => '250',
            'currency' => 'EUR',
            'phone' => 'it',
            'phone_countries' => 'IT',
            'template' => 'default',
            'status' => 'generated',
        ]);

        $generator = Mockery::mock(OfferGenerator::class);
        $generator->shouldReceive('updateSettings')
            ->once()
            ->withArgs(function (Offer $passed, array $input) use ($offer, $white): bool {
                return $passed->is($offer)
                    && $input['cloak_enabled'] === true
                    && (int) $input['white_landing_id'] === (int) $white->id;
            })
            ->andReturn($offer);
        $this->app->instance(OfferGenerator::class, $generator);

        $this->actingAs($user)
            ->from(route('offers.index'))
            ->patch(route('offers.update', $offer), [
                'brand' => 'Acme',
                'min_deposit' => '250',
                'currency' => 'EUR',
                'geo' => 'IT',
                'lang' => 'it',
                'phone' => 'it',
                'phone_countries' => ['IT'],
                'template' => 'default',
                'vitals_enabled' => false,
                'auto_redeploy' => false,
                'cloak_enabled' => true,
                'white_landing_id' => $white->id,
            ])
            ->assertRedirect(route('offers.index'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'brand' => 'Acme',
            'domain' => 'acme-cloak.test',
            'min_deposit' => '250',
            'currency' => 'EUR',
            'geo' => 'IT',
            'lang' => 'it',
            'phone' => 'it',
            'phone_countries' => ['IT'],
            'template' => 'default',
        ], $overrides);
    }
}
