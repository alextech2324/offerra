<?php

namespace App\Console\Commands;

use App\Services\EgoNewBrandAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NotifyEgoNewBrands extends Command
{
    protected $signature = 'offers:notify-ego-new-brands {--since=today : today, yesterday, or a Y-m-d date}';

    protected $description = 'Send colleague-bot pushes for EGO brands that first appeared since the given date';

    public function handle(EgoNewBrandAlertService $alerts): int
    {
        if (! $alerts->configured()) {
            $this->error('EGO_BRAND_ALERT_BOT_TOKEN / EGO_BRAND_ALERT_CHAT_ID не задані.');

            return self::FAILURE;
        }

        $since = $this->parseSince((string) $this->option('since'));
        $result = $alerts->notifyNewSince($since);

        $this->info('since='.$since->toDateTimeString().' sent='.$result['sent'].' skipped='.$result['skipped']);

        foreach ($result['brands'] as $brand) {
            $this->line('ok: '.$brand);
        }

        if ($result['sent'] === 0) {
            $this->line('Немає нових брендів EGO за цей період.');
        }

        return self::SUCCESS;
    }

    private function parseSince(string $raw): Carbon
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            '', 'today' => now()->startOfDay(),
            'yesterday' => now()->subDay()->startOfDay(),
            default => Carbon::parse($raw)->startOfDay(),
        };
    }
}
