<?php

namespace Tests\Feature;

use App\Models\OriginServer;
use App\Services\OriginHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OriginHealthAlertTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_down_alert_includes_hoster_purchase_date_and_paid_until(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:40:00', 'Europe/Kyiv'));

        $server = OriginServer::query()->create([
            'host' => '78.40.117.162',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'hoster' => 'AlexHost',
            'price' => '12€',
            'paid_until' => '2026-12-12',
            'role' => OriginServer::ROLE_DRAIN,
            'is_active' => true,
        ]);
        $server->forceFill([
            'created_at' => Carbon::parse('2026-09-12 11:00:00', 'Europe/Kyiv'),
        ])->save();

        $text = app(OriginHealthMonitor::class)->alertTextForServer(
            $server,
            ['issues' => ['ssh: Connection timed out'], 'message' => 'SSH timeout'],
            'down',
        );

        $this->assertStringContainsString('Origin недоступний', $text);
        $this->assertStringContainsString('78.40.117.162', $text);
        $this->assertStringContainsString('Хостер: AlexHost', $text);
        $this->assertStringContainsString('Куплений: 12.09.2026', $text);
        $this->assertStringContainsString('Оплачено до: 12.12.2026', $text);
        $this->assertStringContainsString('Роль: звільняється', $text);
        $this->assertStringContainsString('Ціна: 12€', $text);
        $this->assertStringContainsString('ssh: Connection timed out', $text);
    }
}
