<?php

namespace Tests\Unit;

use App\Models\OriginServer;
use App\Services\OriginHostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OriginHostPoolFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_and_inactive_hosts_are_not_live_pool(): void
    {
        OriginServer::query()->create([
            'host' => '10.9.0.8',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => true,
            'health' => ['status' => 'ok'],
        ]);
        OriginServer::query()->create([
            'host' => '10.9.0.9',
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'role' => OriginServer::ROLE_POOL,
            'is_active' => false,
            'health' => ['status' => 'ok'],
        ]);

        $origin = app(OriginHostService::class);
        $this->assertTrue($origin->isLivePoolHost('10.9.0.8'));
        $this->assertFalse($origin->isLivePoolHost('10.9.0.9'));
        $this->assertFalse($origin->isLivePoolHost('144.172.64.213'));
        $this->assertFalse($origin->isLivePoolHost('81.180.92.59'));
    }
}
