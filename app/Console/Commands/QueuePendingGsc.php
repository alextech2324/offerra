<?php

namespace App\Console\Commands;

use App\Jobs\ProbeOfferAvailabilityJob;
use App\Models\Offer;
use App\Services\OfferGscSubmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueuePendingGsc extends Command
{
    protected $signature = 'offers:queue-gsc-pending';

    protected $description = 'Поставити в чергу GSC submit для живих оферів, які ще не подані';

    public function handle(OfferGscSubmitter $gsc): int
    {
        $moved = DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->where(function ($query): void {
                $query->where('payload', 'like', '%SubmitOfferToGscJob%')
                    ->orWhere('payload', 'like', '%ProbeOfferAvailabilityJob%');
            })
            ->update(['queue' => 'gsc']);

        if ($moved > 0) {
            $this->info("moved GSC jobs to gsc queue: {$moved}");
        }

        $offers = Offer::query()
            ->with('user.settings')
            ->where('provision_infrastructure', true)
            ->where('status', 'deployed')
            ->where('submitted_for_indexing', false)
            ->where('availability_status', 'ok')
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($offers as $offer) {
            if (! $gsc->shouldQueue($offer)) {
                continue;
            }

            $gsc->queue($offer);
            $this->line("queued: {$offer->domain}");
            $queued++;
        }

        $probed = 0;
        $waiting = Offer::query()
            ->with('user.settings')
            ->where('provision_infrastructure', true)
            ->where('status', 'deployed')
            ->where('submitted_for_indexing', false)
            ->where(function ($query): void {
                $query->whereNull('availability_status')
                    ->orWhere('availability_status', '!=', 'ok');
            })
            ->orderBy('id')
            ->limit(400)
            ->get();
        foreach ($waiting as $offer) {
            if (! $gsc->shouldProbeAfterDns($offer)) {
                continue;
            }
            ProbeOfferAvailabilityJob::dispatch($offer->id);
            $probed++;
        }
        if ($probed > 0) {
            $this->info("reprobe waiting GSC: {$probed}");
        }

        if ($queued === 0 && $moved === 0 && $probed === 0) {
            $this->info('nothing to queue');
        }

        return self::SUCCESS;
    }
}
