<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\CloudflareAbusePollService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ArchiveCfAbuseOfferJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $offerId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'cf-abuse-archive:'.$this->offerId;
    }

    public function handle(CloudflareAbusePollService $poller): void
    {
        $offer = Offer::query()->with('user')->find($this->offerId);
        if (! $offer) {
            return;
        }

        $poller->archiveOfferNow($offer);
    }
}
