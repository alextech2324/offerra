<?php

namespace App\Console\Commands;

use App\Services\CloudflareAbusePollService;
use Illuminate\Console\Command;

class PollCloudflareAbuse extends Command
{
    protected $signature = 'cloudflare:poll-abuse';

    protected $description = 'Poll Cloudflare incoming abuse reports and Telegram the offer owner';

    public function handle(CloudflareAbusePollService $poller): int
    {
        $stats = $poller->poll();

        $this->info(
            'cf_abuse accounts='.$stats['accounts']
            .' fetched='.$stats['fetched']
            .' new='.$stats['new']
            .' notified='.$stats['notified']
            .' forbidden='.$stats['forbidden']
            .' errors='.$stats['errors']
        );

        return self::SUCCESS;
    }
}
