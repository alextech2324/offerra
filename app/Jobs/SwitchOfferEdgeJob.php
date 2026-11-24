<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Models\ProxyServer;
use App\Services\EdgeProxyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SwitchOfferEdgeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(
        public int $offerId,
        public string $target,
    ) {
        $this->onQueue('deploy');
    }

    public function uniqueId(): string
    {
        return $this->offerId.':edge:'.$this->target;
    }

    public function handle(EdgeProxyService $edge): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user) {
            return;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging'], true)) {
            return;
        }

        $target = trim($this->target);

        try {
            if ($target === 'cloudflare') {
                $edge->detach($offer);
            } elseif (preg_match('/^proxy:(\d+)$/', $target, $m)) {
                $proxy = ProxyServer::query()->find((int) $m[1]);
                if ($proxy === null) {
                    throw new \RuntimeException('Проксі не знайдено.');
                }
                $edge->attach($offer, $proxy);
            } else {
                throw new \RuntimeException('Невідомий край: '.$target);
            }

            $offer->refresh();
            $dnsPending = ($offer->infra_meta['dns'] ?? '') === 'pending';
            if ($dnsPending || $offer->needsProxySslRetry()) {
                RecheckInfrastructureDnsJob::dispatch($offer->id)->delay(now()->addSeconds(20));
            }
        } catch (\Throwable $e) {
            Log::error('Offer edge switch failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain ?? null,
                'target' => $target,
                'error' => $e->getMessage(),
            ]);

            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            $meta['edge_error'] = $e->getMessage();
            $offer->update([
                'infra_error' => $e->getMessage(),
                'infra_meta' => $meta,
            ]);
        }
    }
}
