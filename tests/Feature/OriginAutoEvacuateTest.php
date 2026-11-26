<?php

namespace Tests\Feature;

use App\Jobs\DeployOfferJob;
use App\Jobs\RebindOfferDnsJob;
use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\OriginAutoEvacuateService;
use App\Services\OriginHealthProbe;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OriginAutoEvacuateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 04:12:00', 'Europe/Kyiv'));
        $this->mock(TelegramNotifier::class, function ($mock): void {
            $mock->shouldReceive('send')->andReturn(true);
        });
    }

    public function test_waits_ten_minutes_before_confirm_probes(): void
    {
        $this->mockProbe('down');
        $this->livePoolHost();
        $dead = $this->deadHost(minutesDown: 9);
        $this->offerOn($dead);

        $stats = app(OriginAutoEvacuateService::class)->tick();

        $this->assertSame(1, $stats['waiting']);
        $this->assertSame(0, $stats['evacuated']);
        $this->assertSame(OriginServer::ROLE_POOL, $dead->fresh()->role());
    }

    public function test_three_failed_confirms_one_minute_apart_evacuate(): void
    {
        Bus::fake();
        $this->mockProbe('down');
        $live = $this->livePoolHost();
        $dead = $this->deadHost(minutesDown: 11);
        $offer = $this->offerOn($dead);

        $service = app(OriginAutoEvacuateService::class);

        $this->assertSame(1, $service->tick()['confirming']);
        $this->assertSame(1, (int) ($dead->fresh()->health['auto_evac_confirm_fails'] ?? 0));

        $this->assertSame(1, $service->tick()['waiting']);

        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(1, $service->tick()['confirming']);
        $this->assertSame(2, (int) ($dead->fresh()->health['auto_evac_confirm_fails'] ?? 0));

        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(1, $service->tick()['evacuated']);

        $dead->refresh();
        $this->assertSame(OriginServer::ROLE_DRAIN, $dead->role());
        $this->assertNotEmpty($dead->health['auto_evac_at'] ?? null);
        $this->assertSame($live->host, $offer->fresh()->infra_meta['deploy_host'] ?? null);

        Bus::assertChained([
            DeployOfferJob::class,
            RebindOfferDnsJob::class,
        ]);
    }

    public function test_confirm_recovery_does_not_evacuate(): void
    {
        Bus::fake();
        $this->mockProbe('ok');
        $this->livePoolHost();
        $dead = $this->deadHost(minutesDown: 11);
        $this->offerOn($dead);

        $stats = app(OriginAutoEvacuateService::class)->tick();

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(OriginServer::ROLE_POOL, $dead->fresh()->role());
        $this->assertSame('ok', $dead->fresh()->healthStatus());
        Bus::assertNothingDispatched();
    }

    public function test_skips_when_already_evacuated_or_no_offers(): void
    {
        $this->mockProbe('down');
        $this->livePoolHost();
        $empty = $this->deadHost(minutesDown: 20, host: '10.8.0.1');
        $done = $this->deadHost(minutesDown: 20, host: '10.8.0.2');
        $health = is_array($done->health) ? $done->health : [];
        $health['auto_evac_at'] = now()->toIso8601String();
        $done->forceFill(['health' => $health])->save();
        $this->offerOn($done);

        $stats = app(OriginAutoEvacuateService::class)->tick();

        $this->assertGreaterThanOrEqual(2, $stats['skipped']);
        $this->assertSame(0, $stats['evacuated']);
        $this->assertSame(0, $stats['confirming']);
    }

    public function test_blocked_when_no_other_live_pool_host(): void
    {
        $this->mockProbe('down');
        $dead = $this->deadHost(minutesDown: 11);
        $this->offerOn($dead);

        $service = app(OriginAutoEvacuateService::class);
        $service->tick();
        Carbon::setTestNow(now()->addMinute());
        $service->tick();
        Carbon::setTestNow(now()->addMinute());
        $stats = $service->tick();

        $this->assertSame(1, $stats['blocked']);
        $this->assertSame(OriginServer::ROLE_POOL, $dead->fresh()->role());
        $this->assertSame('no_targets', $dead->fresh()->health['auto_evac_error'] ?? null);
    }

    private function mockProbe(string $status): void
    {
        $this->mock(OriginHealthProbe::class, function ($mock) use ($status): void {
            $ok = $status === 'ok';
            $mock->shouldReceive('probeServer')->andReturn([
                'ok' => $ok,
                'status' => $status,
                'message' => $ok ? 'Сервер відповідає' : 'SSH timeout',
                'issues' => $ok ? [] : ['SSH timeout'],
                'ssh' => [
                    'ok' => $ok,
                    'latency_ms' => $ok ? 40 : 15000,
                    'error' => $ok ? null : 'SSH timeout',
                ],
                'http' => [
                    'ok' => $ok,
                    'status' => $ok ? 200 : null,
                    'latency_ms' => $ok ? 20 : null,
                    'error' => $ok ? null : 'skipped',
                ],
                'metrics' => [],
            ]);
        });
    }

    private function livePoolHost(string $host = '10.9.0.2'): OriginServer
    {
        return OriginServer::query()->create([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);
    }

    private function deadHost(int $minutesDown, string $host = '10.9.0.1'): OriginServer
    {
        return OriginServer::query()->create([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'hoster' => 'AlexHost',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => [
                'status' => 'down',
                'down_since' => now()->subMinutes($minutesDown)->toIso8601String(),
                'checked_at' => now()->subMinutes($minutesDown)->toIso8601String(),
                'fail_streak' => 4,
                'last_alert_kind' => 'down',
            ],
        ]);
    }

    private function offerOn(OriginServer $server): Offer
    {
        $user = User::factory()->create();
        UserSetting::query()->create([
            'user_id' => $user->id,
            'tg_bot_token' => 'bot',
            'tg_chat_id' => '1',
        ]);

        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'dead-'.$server->id.'.live',
            'brand' => 'EvacBrand',
            'domain' => 'dead-'.$server->id.'.live',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'deploy_panel_name' => $server->host,
            'infra_meta' => ['deploy_host' => $server->host],
        ]);
    }
}
