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

    public function test_archive_skips_unreachable_origins_and_still_archives(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $bound = $this->origin('144.172.64.248');
        $alive = $this->origin('10.8.0.9');

        $offer = $this->archivingOffer($user, 'dividonic.pro', [
            'deploy_panel_name' => $bound->host,
            'infra_meta' => [
                'deploy_host' => $bound->host,
                'origin' => 'done',
            ],
        ]);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->andReturnUsing(function (UserSetting $settings) use ($bound): void {
                if ((string) $settings->deploy_host === $bound->host) {
                    throw new \RuntimeException(
                        'SSH команда не вдалась: ssh: connect to host 144.172.64.248 port 22: No route to host',
                    );
                }
            });
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertStringContainsString($bound->host.':skipped', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
        $this->assertStringContainsString($alive->host.':deleted', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
        $this->assertArrayNotHasKey('errors', $offer->teardown_meta ?? []);
    }

    public function test_archive_succeeds_when_every_origin_is_unreachable(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $bound = $this->origin('172.86.84.78');

        $offer = $this->archivingOffer($user, 'coinzaivou.info', [
            'deploy_panel_name' => $bound->host,
            'infra_meta' => [
                'deploy_host' => $bound->host,
            ],
        ]);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->andThrow(new \RuntimeException(
                'SSH команда не вдалась: ssh: connect to host 172.86.84.78 port 22: Connection timed out',
            ));
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertStringContainsString($bound->host.':skipped', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
    }

    public function test_archive_skips_origins_already_marked_down_without_ssh(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $down = $this->origin('45.59.126.183', ['health' => ['status' => 'down']]);
        $alive = $this->origin('10.8.0.11');

        $offer = $this->archivingOffer($user, 'skip-down.click', [
            'deploy_panel_name' => $down->host,
            'infra_meta' => [
                'deploy_host' => $down->host,
            ],
        ]);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->once()
            ->andReturnUsing(function (UserSetting $settings) use ($alive): void {
                $this->assertSame($alive->host, (string) $settings->deploy_host);
            });
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertStringContainsString($down->host.':skipped', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
        $this->assertStringContainsString($alive->host.':deleted', (string) ($offer->teardown_meta['steps']['origin'] ?? ''));
    }

    public function test_archive_still_fails_when_bound_origin_rejects_login(): void
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $bound = $this->origin('10.8.0.21');
        $this->origin('10.8.0.22');

        $offer = $this->archivingOffer($user, 'auth-fail.click', [
            'deploy_panel_name' => $bound->host,
            'infra_meta' => [
                'deploy_host' => $bound->host,
            ],
        ]);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->andReturnUsing(function (UserSetting $settings) use ($bound): void {
                if ((string) $settings->deploy_host === $bound->host) {
                    throw new \RuntimeException('SSH: логін відхилено. Перевір користувача і пароль.');
                }
            });
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('teardown_failed', $offer->status);
        $this->assertNotEmpty($offer->teardown_meta['errors'] ?? []);
    }

    public function test_archive_skips_dynadot_when_panel_ip_is_unauthorized(): void
    {
        $user = User::factory()->create();
        $user->settings()->create(['dynadot_api_key' => 'dynadot-key']);
        $this->origin('10.8.0.31');

        $offer = $this->archivingOffer($user, 'dividonic.pro');

        $dynadot = Mockery::mock(DynadotClient::class);
        $dynadot->shouldReceive('parkNameservers')
            ->once()
            ->andThrow(new \RuntimeException('unauthorized ip address: 38.180.121.50'));
        $this->app->instance(DynadotClient::class, $dynadot);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')->andReturnNull();
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertSame('skipped_unauthorized_ip', $offer->teardown_meta['steps']['dynadot'] ?? null);
    }

    public function test_archive_skips_dynadot_when_central_registry_is_busy(): void
    {
        $user = User::factory()->create();
        $user->settings()->create(['dynadot_api_key' => 'dynadot-key']);
        $this->origin('10.8.0.32');

        $offer = $this->archivingOffer($user, 'vrticemedravanza.biz');

        $dynadot = Mockery::mock(DynadotClient::class);
        $dynadot->shouldReceive('parkNameservers')
            ->once()
            ->andThrow(new \RuntimeException(
                'Cannot set nameservers to these domains ( vrticemedravanza.biz : The connection to the central registry is busy. Please try again later).',
            ));
        $this->app->instance(DynadotClient::class, $dynadot);

        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')->andReturnNull();
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertSame('skipped_registry_busy', $offer->teardown_meta['steps']['dynadot'] ?? null);
    }

    public function test_archive_does_not_ssh_to_deleted_origin_ips(): void
    {
        $user = User::factory()->create();
        $user->settings()->create(['deploy_host' => '144.172.64.213']);
        $alive = $this->origin('10.8.0.41');

        $offer = $this->archivingOffer($user, 'ghost-host.click', [
            'deploy_panel_name' => '144.172.64.213',
            'infra_meta' => [
                'deploy_host' => '144.172.64.213',
                'migrated_from' => '81.180.92.59',
            ],
        ]);

        $deleted = [];
        $origin = Mockery::mock(OriginHostService::class);
        $origin->shouldReceive('deleteWebRoot')
            ->once()
            ->andReturnUsing(function (UserSetting $settings, string $domain) use (&$deleted): void {
                $this->assertSame('ghost-host.click', $domain);
                $deleted[] = (string) $settings->deploy_host;
            });
        $this->app->instance(OriginHostService::class, $origin);
        $this->app->forgetInstance(OfferTeardownService::class);

        app(OfferTeardownService::class)->run($offer->fresh());

        $offer->refresh();
        $this->assertSame('archived', $offer->status);
        $this->assertSame([$alive->host], $deleted);
        $this->assertArrayNotHasKey('deploy_host', $offer->infra_meta ?? []);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function archivingOffer(User $user, string $domain, array $overrides = []): Offer
    {
        return Offer::query()->create(array_merge([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => 'Brand',
            'domain' => $domain,
            'geo' => 'AU',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'archiving',
            'from_search_team' => false,
            'teardown_meta' => [
                'started_at' => now()->toIso8601String(),
                'steps' => [],
                'purge' => false,
            ],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function origin(string $host, array $overrides = []): OriginServer
    {
        return OriginServer::query()->create(array_merge([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
        ], $overrides));
    }
}
