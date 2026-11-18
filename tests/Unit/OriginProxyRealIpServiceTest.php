<?php

namespace Tests\Unit;

use App\Jobs\SyncOriginProxyRealIpJob;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\OriginProxyRealIpService;
use App\Support\OriginSsh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OriginProxyRealIpServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_conf_lists_active_proxy_ips_only(): void
    {
        ProxyServer::query()->create([
            'host' => '172.86.71.141',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        ProxyServer::query()->create([
            'host' => '10.0.0.9',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'is_active' => false,
        ]);

        $service = new OriginProxyRealIpService($this->createMock(OriginSsh::class));
        $conf = $service->confContents();

        $this->assertStringContainsString('set_real_ip_from 172.86.71.141;', $conf);
        $this->assertStringNotContainsString('10.0.0.9', $conf);
        $this->assertStringNotContainsString('real_ip_header', $conf);
    }

    public function test_saving_a_proxy_queues_origin_realip_sync(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('proxy-servers.store'), [
                'host' => '172.86.71.141',
                'port' => 22,
                'username' => 'root',
                'password' => 'secret',
                'label' => 'Edge',
            ])
            ->assertRedirect();

        Queue::assertPushedOn('deploy', SyncOriginProxyRealIpJob::class);
    }
}
