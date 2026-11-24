<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\InfrastructureProvisioner;
use App\Services\OriginHostService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RebindOfferDnsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 30;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $offerId,
        public string $ip,
    ) {
        $this->onQueue('dns');
    }

    public function uniqueId(): string
    {
        return $this->offerId.':'.$this->ip;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        // Cloudflare 429 — wait longer between attempts.
        return [30, 60, 120, 180];
    }

    public function handle(InfrastructureProvisioner $provisioner, OriginHostService $origin): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user) {
            return;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging'], true)) {
            return;
        }

        if (! $origin->hostHasOfferIndex($this->ip, (string) $offer->domain)) {
            Log::info('DNS rebind waiting — lander files not on target yet', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'ip' => $this->ip,
            ]);
            $this->retryLater(90);

            return;
        }

        try {
            $provisioner->rebindARecord($offer, $this->ip);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $is429 = str_contains($message, '429')
                || str_contains(strtolower($message), 'throttl')
                || str_contains(strtolower($message), 'rate limit');

            Log::error('Bulk DNS rebind failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'ip' => $this->ip,
                'attempt' => $this->attempts(),
                'error' => $message,
            ]);

            if ($is429 && $this->attempts() < $this->tries) {
                throw $e; // let queue retry with backoff
            }

            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            $meta['dns_error'] = $message;
            $offer->update(['infra_meta' => $meta]);
        }
    }

    private function retryLater(int $seconds): void
    {
        if ($this->job) {
            $this->release($seconds);
        }
    }
}
