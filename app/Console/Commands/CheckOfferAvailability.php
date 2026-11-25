<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\OfferAvailabilityProbe;
use Illuminate\Console\Command;

class CheckOfferAvailability extends Command
{
    protected $signature = 'offers:check-availability
                            {--limit=4000 : Max deployed offers to check this run}
                            {--stale-minutes=14 : Re-check deployed offers older than this}
                            {--only-down : Only re-check offers currently marked down}
                            {--force : Ignore last-check age and re-probe every match}';

    protected $description = 'HTTPS availability probe for deployed offers (green/red dots)';

    public function handle(OfferAvailabilityProbe $probe): int
    {
        @set_time_limit(0);

        $limit = max(1, (int) $this->option('limit'));
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $onlyDown = (bool) $this->option('only-down');
        $force = (bool) $this->option('force');
        $staleCutoff = now()->subMinutes($staleMinutes);

        $offers = Offer::query()
            ->where('status', 'deployed')
            ->when($onlyDown, fn ($q) => $q->where('availability_status', 'down'))
            ->when(! $force, function ($query) use ($staleCutoff): void {
                $query->where(function ($age) use ($staleCutoff): void {
                    $age->whereNull('availability_checked_at')
                        ->orWhere('availability_checked_at', '<', $staleCutoff);
                });
            })
            ->orderByRaw('availability_checked_at IS NULL DESC')
            ->orderBy('availability_checked_at')
            ->limit($limit)
            ->get();

        if ($offers->isEmpty()) {
            $this->info('nothing to check');

            return self::SUCCESS;
        }

        $this->info('checking '.$offers->count().' offers…'.($onlyDown ? ' (only-down)' : '').($force ? ' (force)' : ''));

        $totals = ['ok' => 0, 'down' => 0, 'soft_fail' => 0];

        foreach ($offers->chunk(100) as $chunk) {
            $counts = $probe->checkAndUpdateMany($chunk->all());
            $totals['ok'] += $counts['ok'];
            $totals['down'] += $counts['down'];
            $totals['soft_fail'] += $counts['soft_fail'];
        }

        $this->info(sprintf(
            'done ok=%d down=%d soft_fail=%d',
            $totals['ok'],
            $totals['down'],
            $totals['soft_fail'],
        ));

        return self::SUCCESS;
    }
}
