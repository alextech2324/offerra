<?php

namespace App\Jobs;

use App\Services\OriginProxyRealIpService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncOriginProxyRealIpJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('deploy');
    }

    public function uniqueId(): string
    {
        return 'origin-proxy-realip';
    }

    public function handle(OriginProxyRealIpService $realIp): void
    {
        foreach ($realIp->syncAll() as $row) {
            if (! ($row['ok'] ?? false)) {
                Log::warning('Origin proxy realip sync failed', $row);
            }
        }
    }
}
