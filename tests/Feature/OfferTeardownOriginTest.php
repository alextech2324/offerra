<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\DynadotClient;
use App\Services\OfferTeardownService;
use App\Services\OriginHostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OfferTeardownOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_wipes_every_origin_and_clears_the_server_pin(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $current = $this->origin('10.8.0.1');
        $previous = $this->origin('10.8.0.2');
        $other = $this->origin('10.8.0.3');

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'unbind-me.click',
            'brand' => 'Unbind',
            'domain' => 'unbind-me.click',
            'geo' => 'ID',
            'lang' => 'id',
            'template' => 'default',
            'status' => 'archiving',
            'deploy_panel_name' => $current->host,
            'infra_meta' => [
                'deploy_host' => $current->host,
                'migrated_from' => $previous->host,
                'origin' => 'done',
                'options' => ['cloudflare_zone' => true],
            ],
            'teardown_meta' => [
                'started_at' => now()->toIso8601String(),
                'steps' => [],
                'purge' => false,
            ],
            'from_search_team' => false,
        ]);

        $deleted = [];
        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->times(3)
            ->andReturnUsing(function (UserSetting $settings, string $domain) use (&$deleted): void {
                $this->assertSame('unbind-me.click', $domain);
                $deleted[] = (string) $settings->deploy_host;
            });

        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertNull($offer->deploy_panel_name);
        $this->assertArrayNotHasKey('deploy_host', $offer->infra_meta ?? []);
        $this->assertArrayNotHasKey('migrated_from', $offer->infra_meta ?? []);
        $this->assertSame(
            ['cloudflare_zone' => true],
            $offer->infra_meta['options'] ?? null,
        );
        $this->assertEqualsCanonicalizing(
            [$current->host, $previous->host, $other->host],
            $deleted,
        );
        $this->assertStringContainsString($current->host.':deleted', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
        $this->assertStringContainsString($previous->host.':deleted', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
    }

    public function test_archive_does_not_park_online_nameservers(): void
    {
        $user = User::factory()->create();
        $user->settings()->create(['dynadot_api_key' => 'dynadot-key']);
        $this->origin('10.8.0.4');

        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'solaraaitrade.online',
            'brand' => 'Solara AI',
            'domain' => 'solaraaitrade.online',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'noctra',
            'status' => 'archiving',
            'from_search_team' => false,
            'teardown_meta' => [
                'started_at' => now()->toIso8601String(),
                'steps' => [],
                'purge' => false,
            ],
        ]);

        $dynadot = Mockery::mock(DynadotClient::class);
        $dynadot->shouldNotReceive('parkNameservers');
        $this->app->instance(DynadotClient::class, $dynadot);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')->andReturnNull();
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $this->assertSame(
            'skipped_tld',
            $offer->fresh()->teardown_meta['steps']['dynadot'] ?? null,
        );
    }

    private function origin(string $host): OriginServer
    {
        return OriginServer::query()->create([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
        ]);
    }
}
