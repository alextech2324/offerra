<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\CloudflareClient;
use App\Services\OriginHostService;
use App\Services\OriginPool;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Drop lander files from an origin the offer is no longer bound to.
 * No-op if the offer was moved back (or never left) that host.
 */
class PurgeOfferFromOriginJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 40;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $offerId,
        public string $host,
    ) {
        $this->onQueue('purge');
    }

    public function uniqueId(): string
    {
        return $this->offerId.':'.strtolower(trim($this->host));
    }

    public function handle(OriginPool $pool, OriginHostService $origin, CloudflareClient $cloudflare): void
    {
        $host = strtolower(trim($this->host));
        if ($host === '') {
            return;
        }

        $server = $pool->serverForHost($host);
        if (! $server?->hasSshCredentials() || $server->healthStatus() === 'down') {
            Log::info('Origin purge skipped — host gone from pool', [
                'offer' => $this->offerId,
                'host' => $host,
            ]);

            return;
        }

        $offer = Offer::query()->with('user.settings')->find($this->offerId);
        if (! $offer?->user?->settings) {
            return;
        }

        $current = strtolower(trim($pool->boundHost($offer)));
        if ($current === $host) {
            return;
        }

        $domain = (string) $offer->domain;

        if ($current === '' || ! $origin->hostHasOfferIndex($current, $domain)) {
            Log::info('Origin purge waiting — new host has no lander files yet', [
                'offer' => $this->offerId,
                'domain' => $domain,
                'from' => $host,
                'to' => $current,
            ]);
            $this->retryLater(120);

            return;
        }

        if ($this->cloudflareStillPointsAt($offer, $cloudflare, $host)) {
            Log::info('Origin purge waiting — Cloudflare A still on old origin', [
                'offer' => $this->offerId,
                'domain' => $domain,
                'from' => $host,
            ]);
            $this->retryLater(300);

            return;
        }

        try {
            $settings = $pool->applyTo($offer->user->settings, $server);
            $origin->deleteWebRoot($settings, $domain);
        } catch (\Throwable $e) {
            Log::warning('Origin file purge after move failed', [
                'offer' => $this->offerId,
                'domain' => $domain,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cloudflareStillPointsAt(Offer $offer, CloudflareClient $cloudflare, string $host): bool
    {
        $settings = $offer->user?->settings;
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $zoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));
        $domain = strtolower(trim((string) $offer->domain));

        if (! $settings || $zoneId === '' || $domain === '') {
            return true;
        }

        try {
            foreach ($cloudflare->listARecords($settings, $zoneId, $domain) as $record) {
                $ip = strtolower(trim((string) ($record['content'] ?? '')));
                if ($ip === $host) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function retryLater(int $seconds): void
    {
        if ($this->job) {
            $this->release($seconds);
        }
    }
}
