<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\ProxyServer;
use App\Support\InfrastructureOptions;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Place offers on edge proxies the same way OriginPool places them on origins:
 * fewest other offers of this brand, then the least total load.
 */
class ProxyPool
{
    private const COUNTS_CACHE_KEY = 'proxy-pool-offer-counts';

    private const COUNTS_CACHE_SECONDS = 30;

    /**
     * @return array<int, int>
     */
    public function offerCounts(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::COUNTS_CACHE_KEY);
        }

        return Cache::remember(
            self::COUNTS_CACHE_KEY,
            self::COUNTS_CACHE_SECONDS,
            fn (): array => $this->countOffersByProxy(),
        );
    }

    public function forgetCounts(): void
    {
        Cache::forget(self::COUNTS_CACHE_KEY);
    }

    /**
     * @return list<array{server: ProxyServer, offers: int}>
     */
    public function candidates(bool $fresh = false): array
    {
        $counts = $this->offerCounts($fresh);

        $rows = [];
        foreach (ProxyServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            if (! $server->hasSshCredentials()) {
                continue;
            }

            $rows[] = [
                'server' => $server,
                'offers' => (int) ($counts[(int) $server->id] ?? 0),
            ];
        }

        return $rows;
    }

    public function hasCapacity(): bool
    {
        return $this->candidates() !== [];
    }

    /**
     * @return array<int, int>
     */
    public function brandCountsByProxy(string $brand): array
    {
        $key = mb_strtolower(trim($brand));
        if ($key === '') {
            return [];
        }

        $counts = [];
        $this->eachLiveOffer(function (Offer $offer) use (&$counts, $key): void {
            if (mb_strtolower(trim((string) ($offer->brand ?? ''))) !== $key) {
                return;
            }

            $id = $this->boundProxyId($offer);
            if ($id < 1) {
                return;
            }

            $counts[$id] = ($counts[$id] ?? 0) + 1;
        });

        return $counts;
    }

    public function allocate(?Offer $offer = null): ProxyServer
    {
        $candidates = $this->candidates(fresh: true);

        if ($candidates === []) {
            throw new RuntimeException(
                'Немає доступних проксі. Додайте або активуйте сервер у розділі Proxy.',
            );
        }

        $brandOnProxy = [];
        if ($offer !== null) {
            $brandOnProxy = $this->brandCountsByProxy((string) ($offer->brand ?? ''));
        }

        return $this->pickCandidate($candidates, $brandOnProxy);
    }

    public function assign(Offer $offer): ProxyServer
    {
        return Cache::lock('proxy-pool-allocate', 20)->block(25, function () use ($offer) {
            $offer->refresh();

            $bound = $this->usableBound($offer);
            if ($bound !== null) {
                return $bound;
            }

            $server = $this->allocate($offer);
            $this->bind($offer, $server);

            return $server;
        });
    }

    /**
     * @param  list<int>  $offerIds
     * @return array<int, int> offer id => proxy id
     */
    public function spread(array $offerIds): array
    {
        $candidates = $this->candidates(fresh: true);

        if ($candidates === []) {
            throw new RuntimeException('Немає доступних проксі.');
        }

        $load = [];
        foreach ($candidates as $row) {
            $load[(int) $row['server']->id] = $row['offers'];
        }

        $offers = Offer::query()
            ->whereIn('id', $offerIds)
            ->get(['id', 'brand'])
            ->keyBy('id');

        $brandLoads = [];
        $assignment = [];

        foreach ($offerIds as $offerId) {
            $offerId = (int) $offerId;
            $brandKey = mb_strtolower(trim((string) ($offers->get($offerId)?->brand ?? '')));
            if ($brandKey !== '' && ! isset($brandLoads[$brandKey])) {
                $brandLoads[$brandKey] = $this->brandCountsByProxy($brandKey);
            }

            $eligible = [];
            foreach ($candidates as $row) {
                $id = (int) $row['server']->id;
                $eligible[] = ['server' => $row['server'], 'offers' => $load[$id]];
            }

            $target = $this->pickCandidate($eligible, $brandLoads[$brandKey] ?? []);
            $tid = (int) $target->id;
            $assignment[$offerId] = $tid;
            $load[$tid] = ($load[$tid] ?? 0) + 1;
            if ($brandKey !== '') {
                $brandLoads[$brandKey][$tid] = (int) ($brandLoads[$brandKey][$tid] ?? 0) + 1;
            }
        }

        return $assignment;
    }

    public function boundProxyId(Offer $offer): int
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $fromMeta = (int) ($meta['edge_proxy_id'] ?? 0);
        if ($fromMeta > 0) {
            return $fromMeta;
        }

        $options = is_array($meta['options'] ?? null) ? $meta['options'] : [];

        return (int) ($options['edge_proxy_id'] ?? 0);
    }

    public function bind(Offer $offer, ProxyServer $server): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $options = is_array($meta['options'] ?? null) ? $meta['options'] : [];
        $options['edge'] = InfrastructureOptions::EDGE_PROXY;
        $options['edge_proxy_id'] = (int) $server->id;
        $options['cloudflare_zone'] = true;
        $options['cloudflare_dns'] = false;
        $options['dynadot_ns'] = true;
        $options['cloudflare_ssl'] = false;
        $options['cloudflare_https'] = false;
        $options['cloudflare_www_redirect'] = false;
        $meta['options'] = $options;

        $offer->forceFill(['infra_meta' => $meta])->save();
        $this->forgetCounts();
    }

    /**
     * @param  list<array{server: ProxyServer, offers: int}>  $candidates
     * @param  array<int, int>  $brandOnProxy
     */
    private function pickCandidate(array $candidates, array $brandOnProxy = []): ProxyServer
    {
        $best = null;
        $bestBrand = null;
        $bestLoad = null;

        foreach ($candidates as $row) {
            $id = (int) $row['server']->id;
            $brandN = (int) ($brandOnProxy[$id] ?? 0);
            $load = (int) $row['offers'];

            if (
                $best === null
                || $brandN < $bestBrand
                || ($brandN === $bestBrand && $load < $bestLoad)
            ) {
                $best = $row['server'];
                $bestBrand = $brandN;
                $bestLoad = $load;
            }
        }

        if ($best === null) {
            throw new RuntimeException(
                'Немає доступних проксі. Додайте або активуйте сервер у розділі Proxy.',
            );
        }

        return $best;
    }

    private function usableBound(Offer $offer): ?ProxyServer
    {
        $id = $this->boundProxyId($offer);
        if ($id < 1) {
            return null;
        }

        $server = ProxyServer::query()->find($id);
        if ($server && $server->is_active && $server->hasSshCredentials()) {
            return $server;
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function countOffersByProxy(): array
    {
        $counts = [];
        $this->eachLiveOffer(function (Offer $offer) use (&$counts): void {
            $id = $this->boundProxyId($offer);
            if ($id < 1) {
                return;
            }
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        });

        return $counts;
    }

    /**
     * @param  callable(Offer): void  $callback
     */
    private function eachLiveOffer(callable $callback): void
    {
        Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->select(['id', 'brand', 'infra_meta'])
            ->chunkById(500, function ($chunk) use ($callback): void {
                foreach ($chunk as $offer) {
                    $callback($offer);
                }
            });
    }
}
