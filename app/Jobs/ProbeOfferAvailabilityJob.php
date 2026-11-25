<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\OfferAvailabilityProbe;
use App\Services\OfferGscSubmitter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After DNS is ready: poll HTTPS every minute until live, then auto-queue GSC.
 */
class ProbeOfferAvailabilityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    /** Keep polling up to ~7 days (once per minute). */
    public int $tries = 10080;

    public int $uniqueFor = 3600;

    private const RETRY_SECONDS = 60;

    public function __construct(public int $offerId)
    {
        // Ahead of mass CRM/GSC-inspect jobs on `default`.
        $this->onQueue('gsc');
    }

    public function uniqueId(): string
    {
        return 'offer-availability-probe:'.$this->offerId;
    }

    public function handle(OfferAvailabilityProbe $probe, OfferGscSubmitter $gsc): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);
        if (! $offer) {
            return;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging', 'teardown_failed'], true)) {
            return;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['dns'] ?? null) !== 'done') {
            return;
        }

        if ($offer->submitted_for_indexing || ($meta['gsc']['status'] ?? null) === 'submitted') {
            return;
        }

        if ($offer->status !== 'deployed') {
            $this->release(self::RETRY_SECONDS);

            return;
        }

        $probe->checkAndUpdate($offer);
        $fresh = $offer->fresh() ?? $offer;

        if ($fresh->availability_status === 'ok') {
            $gsc->queue($fresh, 5);

            return;
        }

        Log::info('Offer still not live after DNS — retry in 1 minute', [
            'offer' => $fresh->id,
            'domain' => $fresh->domain,
            'error' => $fresh->availability_error,
            'attempt' => $this->attempts(),
        ]);

        if ($this->attempts() < $this->tries) {
            $this->release(self::RETRY_SECONDS);
        }
    }
}
