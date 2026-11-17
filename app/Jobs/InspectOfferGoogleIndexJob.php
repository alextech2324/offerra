<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\OfferGoogleIndexInspector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InspectOfferGoogleIndexJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 259200;

    public function __construct(public int $offerId, public bool $force = false)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return ($this->force ? 'gsc-inspect-force:' : 'gsc-inspect:').$this->offerId;
    }

    public function handle(OfferGoogleIndexInspector $inspector): void
    {
        $offer = Offer::query()->with(['user.settings.googleAccounts', 'user.googleAccounts'])->find($this->offerId);
        if (! $offer) {
            return;
        }

        $inspector->inspect($offer, $this->force);
    }
}
