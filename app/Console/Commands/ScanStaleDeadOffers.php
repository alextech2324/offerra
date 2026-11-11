<?php

namespace App\Console\Commands;

use App\Services\StaleDeadOfferService;
use Illuminate\Console\Command;

class ScanStaleDeadOffers extends Command
{
    protected $signature = 'offers:scan-stale-dead
        {--days=21 : Last brand GSC and last brand lead older than N days}';

    protected $description = 'Daily 10:00 snapshot of stale-dead brands for the Archive banner (no archive)';

    public function handle(StaleDeadOfferService $service): int
    {
        $days = max(1, (int) $this->option('days'));
        $result = $service->scan($days);

        $this->info("stale_dead total={$result['total']} users=".count($result['by_user']).' days='.$days);
        foreach ($result['by_user'] as $userId => $cnt) {
            $this->line("  user_id={$userId} count={$cnt}");
        }

        return self::SUCCESS;
    }
}
