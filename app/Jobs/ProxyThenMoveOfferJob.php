<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\ProxyServer;
use App\Services\EdgeProxyService;
use App\Services\OriginEvacuationService;
use App\Services\OriginPool;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Put the lander on the edge proxy first (grey A → proxy IP), then move files
 * to the target origin. Never writes the origin IP into public DNS.
 */
class ProxyThenMoveOfferJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 720;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $offerId,
        public string $targetHost,
        public string $proxyTarget = 'proxy:1',
    ) {
        $this->onQueue('deploy');
    }

    public function uniqueId(): string
    {
        return $this->offerId.':proxy-then-move:'.$this->targetHost;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 90];
    }

    public function handle(
        EdgeProxyService $edge,
        OriginEvacuationService $evac,
        OriginPool $pool,
    ): void {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user) {
            return;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging'], true)) {
            return;
        }

        if (($offer->infra_meta['edge'] ?? '') !== 'proxy') {
            if (! preg_match('/^proxy:(\d+)$/', $this->proxyTarget, $m)) {
                throw new RuntimeException('Невідомий край: '.$this->proxyTarget);
            }
            $proxy = ProxyServer::query()->find((int) $m[1]);
            if ($proxy === null) {
                throw new RuntimeException('Проксі не знайдено.');
            }
            $edge->attach($offer, $proxy);
            $offer->refresh();
        }

        if (($offer->infra_meta['edge'] ?? '') !== 'proxy') {
            throw new RuntimeException('Офер не на проксі після attach — origin A не чіпаємо.');
        }

        $targetHost = strtolower(trim($this->targetHost));
        $target = OriginServer::query()->where('host', $targetHost)->first();
        if ($target === null) {
            throw new RuntimeException('Немає origin '.$this->targetHost);
        }

        if (strtolower(trim($pool->boundHost($offer))) === $targetHost) {
            return;
        }

        $result = $evac->moveOffersTo([$offer], $target);
        Log::info('Proxy-then-move queued origin deploy', [
            'offer' => $this->offerId,
            'to' => $targetHost,
            'result' => $result,
        ]);
    }
}
