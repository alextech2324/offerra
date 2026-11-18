<?php

namespace App\Console\Commands;

use App\Services\OriginProxyRealIpService;
use Illuminate\Console\Command;

class SyncOriginProxyRealIp extends Command
{
    protected $signature = 'origin:sync-proxy-realip';

    protected $description = 'Trust active edge-proxy IPs on every origin so CF-Connecting-IP becomes REMOTE_ADDR';

    public function handle(OriginProxyRealIpService $realIp): int
    {
        $failed = 0;
        foreach ($realIp->syncAll() as $row) {
            $mark = ($row['ok'] ?? false) ? 'ok' : 'FAIL';
            $this->line("{$row['host']} {$mark} {$row['message']}");
            if (! ($row['ok'] ?? false)) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
