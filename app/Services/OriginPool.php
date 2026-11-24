<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\UserSetting;
use App\Support\DeployDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Central pool of admin-managed origin servers.
 *
 * Offers are no longer pinned to a per-user SSH host from Settings. A new offer
 * prefers a pool server that has the fewest other offers of the same brand, then
 * the least total load. That way a 5-domain funnel is spread across origins: if
 * one IP is abused, the rest of the brand stays up. An offer keeps its assigned
 * host in infra_meta.deploy_host until an admin evacuates it.
 */
class OriginPool
{
    private const COUNTS_CACHE_KEY = 'origin-pool-offer-counts';

    private const COUNTS_CACHE_SECONDS = 30;

    public function __construct(private readonly OriginServerSync $sync) {}

    /**
     * Offers per host, cached briefly — allocation runs on every unbound deploy.
     *
     * @return array<string, int>
     */
    public function offerCounts(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::COUNTS_CACHE_KEY);
        }

        return Cache::remember(
            self::COUNTS_CACHE_KEY,
            self::COUNTS_CACHE_SECONDS,
            fn (): array => $this->sync->offerCountsByHost(),
        );
    }

    public function forgetCounts(): void
    {
        Cache::forget(self::COUNTS_CACHE_KEY);
    }

    /**
     * Servers that may receive a new offer.
     *
     * @return list<array{server: OriginServer, offers: int}>
     */
    public function candidates(): array
    {
        $counts = $this->offerCounts();

        $rows = [];
        foreach (OriginServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            if ($server->healthStatus() === 'down') {
                continue;
            }

            $host = $this->sync->normalizeHost((string) $server->host);
            $offers = (int) ($counts[$host] ?? 0);

            if (! $server->acceptsNewOffers($offers)) {
                continue;
            }

            $rows[] = ['server' => $server, 'offers' => $offers];
        }

        return $rows;
    }

    public function hasCapacity(): bool
    {
        return $this->candidates() !== [];
    }

    /**
     * Active offers of this brand already bound to each host.
     *
     * @return array<string, int>
     */
    public function brandCountsByHost(string $brand): array
    {
        $key = mb_strtolower(trim($brand));
        if ($key === '') {
            return [];
        }

        $counts = [];

        Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$key])
            ->orderBy('id')
            ->select(['id', 'deploy_panel_name', 'infra_meta'])
            ->chunkById(500, function ($chunk) use (&$counts): void {
                foreach ($chunk as $offer) {
                    $host = $this->sync->normalizeHost($this->boundHost($offer));
                    if ($host === '') {
                        continue;
                    }
                    $counts[$host] = ($counts[$host] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /**
     * Pick a pool server: fewest offers of this brand first, then least total load.
     *
     * @param  list<array{server: OriginServer, offers: int}>  $candidates
     * @param  array<string, int>  $brandOnHost
     */
    private function pickCandidate(array $candidates, array $brandOnHost = []): OriginServer
    {
        $best = null;
        $bestBrand = null;
        $bestLoad = null;

        foreach ($candidates as $row) {
            $host = $this->sync->normalizeHost((string) $row['server']->host);
            $brandN = (int) ($brandOnHost[$host] ?? 0);
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
                'Немає доступних origin-серверів у пулі. Додайте або активуйте сервер у розділі Origin Servers.',
            );
        }

        return $best;
    }

    /**
     * Pick a pool server for this offer (brand anti-affinity, then least loaded).
     */
    public function allocate(?Offer $offer = null): OriginServer
    {
        $candidates = $this->candidates();

        if ($candidates === []) {
            throw new RuntimeException(
                'Немає доступних origin-серверів у пулі. Додайте або активуйте сервер у розділі Origin Servers.',
            );
        }

        $brandOnHost = [];
        if ($offer !== null) {
            $brandOnHost = $this->brandCountsByHost((string) ($offer->brand ?? ''));
        }

        return $this->pickCandidate($candidates, $brandOnHost);
    }

    /**
     * Spread a list of offer ids across the pool, balancing on current load.
     *
     * @param  list<int>  $offerIds
     * @return array<int, string> offer id => host
     */
    public function spread(array $offerIds, ?OriginServer $exclude = null): array
    {
        $candidates = $this->candidates();

        if ($exclude !== null) {
            $excludeHost = $this->sync->normalizeHost((string) $exclude->host);
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $row) => $this->sync->normalizeHost((string) $row['server']->host) !== $excludeHost,
            ));
        }

        if ($candidates === []) {
            throw new RuntimeException('Немає інших активних серверів у пулі, щоб розкидати оффери.');
        }

        $load = [];
        $limits = [];
        foreach ($candidates as $row) {
            $host = trim((string) $row['server']->host);
            $load[$host] = $row['offers'];
            $max = (int) ($row['server']->max_offers ?? 0);
            $limits[$host] = $max > 0 ? $max : null;
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
                $brandLoads[$brandKey] = $this->brandCountsByHost($brandKey);
            }

            $eligible = [];
            foreach ($candidates as $row) {
                $host = trim((string) $row['server']->host);
                if ($limits[$host] !== null && $load[$host] >= $limits[$host]) {
                    continue;
                }
                $eligible[] = ['server' => $row['server'], 'offers' => $load[$host]];
            }

            if ($eligible === []) {
                foreach ($candidates as $row) {
                    $eligible[] = ['server' => $row['server'], 'offers' => $load[trim((string) $row['server']->host)]];
                }
            }

            $targetServer = $this->pickCandidate($eligible, $brandLoads[$brandKey] ?? []);
            $target = trim((string) $targetServer->host);

            $assignment[$offerId] = $target;
            $load[$target] = ($load[$target] ?? 0) + 1;
            if ($brandKey !== '') {
                $norm = $this->sync->normalizeHost($target);
                $brandLoads[$brandKey][$norm] = (int) ($brandLoads[$brandKey][$norm] ?? 0) + 1;
            }
        }

        return $assignment;
    }

    public function serverForHost(?string $host): ?OriginServer
    {
        $normalized = $this->sync->normalizeHost((string) $host);
        if ($normalized === '') {
            return null;
        }

        $exact = OriginServer::query()->where('host', trim((string) $host))->first()
            ?? OriginServer::query()->where('host', $normalized)->first();

        if ($exact) {
            return $exact;
        }

        return OriginServer::query()
            ->get()
            ->first(fn (OriginServer $server) => $this->sync->normalizeHost((string) $server->host) === $normalized);
    }

    /**
     * Host this offer is currently bound to (infra_meta wins over the panel column).
     */
    public function boundHost(Offer $offer): string
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $fromMeta = trim((string) ($meta['deploy_host'] ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $panel = trim((string) ($offer->deploy_panel_name ?? ''));

        return filter_var($panel, FILTER_VALIDATE_IP) ? $panel : '';
    }

    /**
     * Persist the offer → server binding.
     */
    public function bind(Offer $offer, OriginServer $server): void
    {
        $host = trim((string) $server->host);
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $meta['deploy_host'] = $host;

        $offer->forceFill([
            'infra_meta' => $meta,
            'deploy_panel_name' => $host,
        ])->save();

        $this->forgetCounts();
    }

    /**
     * Drop the offer → server pin after archive/purge so the IP is not still
     * listed as hosting this domain.
     */
    public function unbind(Offer $offer): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        unset($meta['deploy_host'], $meta['migrated_from'], $meta['origin']);

        $offer->forceFill([
            'infra_meta' => $meta,
            'deploy_panel_name' => null,
        ])->save();

        $this->forgetCounts();
    }

    /**
     * Copy the server's SSH/deploy settings onto an unsaved clone of the user's
     * settings, so every existing consumer that reads $settings->deploy_* keeps
     * working without knowing about the pool.
     */
    public function applyTo(UserSetting $settings, OriginServer $server): UserSetting
    {
        $probe = $settings->exists ? $settings->replicate() : clone $settings;

        $probe->deploy_host = trim((string) $server->host);
        $probe->deploy_port = (int) ($server->port ?: 22);
        $probe->deploy_username = trim((string) $server->username) ?: 'root';
        $probe->deploy_password = $server->password;
        $probe->deploy_driver = $server->deploy_driver ?: DeployDriver::UBUNTU;
        $probe->deploy_path_template = $server->deploy_path_template
            ?: DeployDriver::defaultPath($server->deploy_driver);
        $probe->deploy_panel_name = $probe->deploy_host;

        return $probe;
    }

    /**
     * Bound origin that can still receive this offer's files.
     *
     * A deleted, inactive, or down IP must not keep retrying SSH. Restore and
     * provision then allocate a live pool host. Active rows without credentials
     * still throw so a mis-typed password is visible.
     */
    public function usableBoundServer(Offer $offer): ?OriginServer
    {
        $host = $this->boundHost($offer);
        if ($host === '') {
            return null;
        }

        $server = $this->serverForHost($host);
        if ($server === null || ! $server->is_active || $server->healthStatus() === 'down') {
            return null;
        }

        if ($server->hasSshCredentials()) {
            return $server;
        }

        throw new RuntimeException(
            "Немає SSH-доступу до сервера {$host} (колонка Server у офера). Додайте його в Origin Servers.",
        );
    }

    /**
     * Settings ready to deploy this offer: its bound server, or a freshly
     * allocated one when the offer has no host yet (or the old host was deleted).
     */
    public function settingsForOffer(UserSetting $settings, Offer $offer, bool $bind = true): UserSetting
    {
        $bound = $this->usableBoundServer($offer);
        if ($bound !== null) {
            return $this->applyTo($settings, $bound);
        }

        return Cache::lock('origin-pool-allocate', 20)->block(25, function () use ($settings, $offer, $bind) {
            $offer->refresh();
            $bound = $this->usableBoundServer($offer);
            if ($bound !== null) {
                return $this->applyTo($settings, $bound);
            }

            $server = $this->allocate($offer);

            if ($bind) {
                $this->bind($offer, $server);
            }

            return $this->applyTo($settings, $server);
        });
    }

    /**
     * Pool overview for the admin panel.
     *
     * @return array{
     *     total: int,
     *     pool: int,
     *     spare: int,
     *     drain: int,
     *     accepting: int,
     *     offers: int,
     *     capacity: ?int,
     *     next_host: ?string
     * }
     */
    public function summary(): array
    {
        $counts = $this->offerCounts();
        $servers = OriginServer::query()->get();
        $candidates = $this->candidates();

        $capacity = 0;
        $unlimited = false;
        foreach ($candidates as $row) {
            $free = $row['server']->freeCapacityFrom($row['offers']);
            if ($free === null) {
                $unlimited = true;
                continue;
            }
            $capacity += $free;
        }

        return [
            'total' => $servers->count(),
            'pool' => $servers->filter(fn (OriginServer $s) => $s->role() === OriginServer::ROLE_POOL)->count(),
            'spare' => $servers->filter(fn (OriginServer $s) => $s->role() === OriginServer::ROLE_SPARE)->count(),
            'drain' => $servers->filter(fn (OriginServer $s) => $s->role() === OriginServer::ROLE_DRAIN)->count(),
            'accepting' => count($candidates),
            'offers' => array_sum($counts),
            'capacity' => $unlimited ? null : $capacity,
            'next_host' => collect($candidates)->sortBy('offers')->value('server')?->host,
        ];
    }

    /**
     * @return Collection<int, OriginServer>
     */
    public function spares(): Collection
    {
        return OriginServer::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (OriginServer $s) => $s->role() === OriginServer::ROLE_SPARE)
            ->values();
    }
}
