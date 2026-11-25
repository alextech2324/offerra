<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CloudflareClient;
use App\Services\InfrastructureProvisioner;
use App\Support\PublicDnsLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RebindLiveCloudflareZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebind_updates_live_zone_when_stored_zone_is_still_pending(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'cloudflare_api_token' => 'new-account-token',
            'cloudflare_account_id' => 'acct-new',
            'cloudflare_backup_api_token' => 'old-account-token',
            'cloudflare_backup_account_id' => 'acct-old',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'contarizia.online',
            'brand' => 'Contarizia',
            'domain' => 'contarizia.online',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'aurel',
            'status' => 'deployed',
            'deploy_panel_name' => '10.0.0.2',
            'cloudflare_api_token' => 'new-account-token',
            'cloudflare_account_id' => 'acct-new',
            'from_search_team' => false,
            'infra_meta' => [
                'deploy_host' => '10.0.0.2',
                'cloudflare_zone_id' => 'zone-pending',
                'nameservers' => ['alex.ns.cloudflare.com', 'nola.ns.cloudflare.com'],
                'dns' => 'pending',
            ],
        ]);

        $dns = Mockery::mock(PublicDnsLookup::class);
        $dns->shouldReceive('nameservers')
            ->with('contarizia.online')
            ->andReturn(['bradley.ns.cloudflare.com', 'delilah.ns.cloudflare.com']);
        $this->app->instance(PublicDnsLookup::class, $dns);

        $cf = Mockery::mock(CloudflareClient::class);
        $cf->shouldReceive('findZone')->andReturnUsing(function (UserSetting $settings) {
            $token = CloudflareClient::normalizeApiToken($settings->cloudflare_api_token);
            if ($token === 'old-account-token') {
                return [
                    'zone_id' => 'zone-live',
                    'nameservers' => ['bradley.ns.cloudflare.com', 'delilah.ns.cloudflare.com'],
                    'status' => 'active',
                ];
            }

            return [
                'zone_id' => 'zone-pending',
                'nameservers' => ['alex.ns.cloudflare.com', 'nola.ns.cloudflare.com'],
                'status' => 'pending',
            ];
        });
        $cf->shouldReceive('isUnauthorizedError')->andReturn(false);
        $cf->shouldReceive('isZoneAbsentError')->andReturn(false);
        $written = [];
        $cf->shouldReceive('setRootARecords')->andReturnUsing(
            function (UserSetting $settings, string $zoneId, string $domain, string $ip) use (&$written): void {
                $written[] = $zoneId.':'.$ip;
            },
        );
        $this->app->instance(CloudflareClient::class, $cf);

        app(InfrastructureProvisioner::class)->rebindARecord($offer->fresh() ?? $offer, '10.0.0.2');

        $this->assertContains('zone-live:10.0.0.2', $written);
        $this->assertContains('zone-pending:10.0.0.2', $written);
        $offer->refresh();
        $this->assertSame('zone-pending', $offer->infra_meta['cloudflare_zone_id'] ?? null);
        $this->assertSame('10.0.0.2', $offer->infra_meta['deploy_host'] ?? null);
    }

    public function test_rebind_fails_if_public_ns_are_cloudflare_but_live_zone_token_is_missing(): void
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'cloudflare_api_token' => 'new-account-token',
            'cloudflare_account_id' => 'acct-new',
        ]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'orphan.live',
            'brand' => 'Orphan',
            'domain' => 'orphan.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'aurel',
            'status' => 'deployed',
            'deploy_panel_name' => '10.0.0.8',
            'cloudflare_api_token' => 'new-account-token',
            'from_search_team' => false,
            'infra_meta' => [
                'deploy_host' => '10.0.0.8',
                'cloudflare_zone_id' => 'zone-pending',
                'nameservers' => ['alex.ns.cloudflare.com', 'nola.ns.cloudflare.com'],
            ],
        ]);

        $dns = Mockery::mock(PublicDnsLookup::class);
        $dns->shouldReceive('nameservers')
            ->andReturn(['bradley.ns.cloudflare.com', 'delilah.ns.cloudflare.com']);
        $this->app->instance(PublicDnsLookup::class, $dns);

        $cf = Mockery::mock(CloudflareClient::class);
        $cf->shouldReceive('findZone')->andReturn([
            'zone_id' => 'zone-pending',
            'nameservers' => ['alex.ns.cloudflare.com', 'nola.ns.cloudflare.com'],
            'status' => 'pending',
        ]);
        $cf->shouldReceive('setRootARecords');
        $this->app->instance(CloudflareClient::class, $cf);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('живій зоні');

        app(InfrastructureProvisioner::class)->rebindARecord($offer->fresh() ?? $offer, '10.0.0.8');
    }
}
