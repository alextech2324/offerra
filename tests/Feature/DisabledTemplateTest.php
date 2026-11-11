<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisabledTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_marks_disabled_template(): void
    {
        $user = User::factory()->create();
        app(TemplateCatalog::class)->setEnabled('default', false);

        $this->actingAs($user)
            ->get(route('templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Templates/Index')
                ->where('templates', function ($templates) {
                    $row = collect($templates)->firstWhere('id', 'default');

                    return is_array($row) && $row['active'] === false;
                }));
    }

    public function test_disabled_template_is_hidden_from_create_wizard(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        app(TemplateCatalog::class)->setEnabled('default', false);

        $this->actingAs($user)
            ->get(route('offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Offers/Create')
                ->where('templates', fn ($templates) => collect($templates)->every(
                    fn ($template) => ($template['id'] ?? '') !== 'default',
                )));
    }

    public function test_cannot_create_offer_with_disabled_template(): void
    {
        $user = User::factory()->create();
        app(TemplateCatalog::class)->setEnabled('default', false);

        $this->actingAs($user)
            ->from(route('offers.create'))
            ->post(route('offers.store'), [
                'brand' => 'Acme',
                'domain' => 'acme-disabled.test',
                'min_deposit' => '250',
                'currency' => 'EUR',
                'geo' => 'IT',
                'lang' => 'it',
                'phone' => 'it',
                'phone_countries' => ['IT'],
                'template' => 'default',
            ])
            ->assertRedirect(route('offers.create'))
            ->assertSessionHasErrors('template');
    }

    public function test_toggle_disables_and_enables_template(): void
    {
        $user = User::factory()->create();
        $catalog = app(TemplateCatalog::class);

        $this->actingAs($user)
            ->patch(route('templates.availability', 'default'), ['enabled' => false])
            ->assertRedirect(route('templates.index'));

        $this->assertFalse($catalog->isEnabled('default'));

        $this->actingAs($user)
            ->patch(route('templates.availability', 'default'), ['enabled' => true])
            ->assertRedirect(route('templates.index'));

        $this->assertTrue($catalog->isEnabled('default'));
    }

    public function test_existing_offer_can_keep_disabled_template(): void
    {
        $user = User::factory()->create();
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'keep-disabled.test',
            'brand' => 'KeepDisabled',
            'domain' => 'keep-disabled.test',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'draft',
            'from_search_team' => false,
        ]);

        app(TemplateCatalog::class)->setEnabled('default', false);

        $ids = app(TemplateCatalog::class)->selectableIds($offer->template);

        $this->assertContains('default', $ids);
    }

    public function test_unknown_template_toggle_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('templates.availability', 'no-such-template'), ['enabled' => false])
            ->assertNotFound();
    }
}
