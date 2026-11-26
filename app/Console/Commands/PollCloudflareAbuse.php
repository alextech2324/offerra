<?php

namespace App\Console\Commands;

use App\Services\CloudflareAbusePollService;
use Illuminate\Console\Command;

class PollCloudflareAbuse extends Command
{
    protected $signature = 'cloudflare:poll-abuse';

    protected $description = 'Poll Cloudflare incoming abuse reports, Telegram, then auto-archive after 3 minutes';

    public function handle(CloudflareAbusePollService $poller): int
    {
        $stats = $poller->poll();

        $this->info(
            'cf_abuse accounts='.$stats['accounts']
            .' fetched='.$stats['fetched']
            .' new='.$stats['new']
            .' notified='.$stats['notified']
            .' archived='.$stats['archived']
            .' forbidden='.$stats['forbidden']
            .' errors='.$stats['errors']
        );

        return self::SUCCESS;
    }
}
