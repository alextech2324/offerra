<?php

namespace App\Services;

use App\Jobs\DeployOfferJob;
use App\Jobs\PurgeOfferFromOriginJob;
use App\Jobs\RebindOfferDnsJob;
use App\Models\Offer;
use App\Models\OriginServer;
use App\Support\OriginRebalancePlanner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Move every live offer off one origin server and spread it across the pool:
 * retarget the binding, redeploy the files, then rebind Cloudflare A records.
 */
class OriginEvacuationService
{
    /** Wait after DNS rebind before deleting the old origin copy (CF orange TTL). */
    private const PURGE_AFTER_REBIND_SECONDS = 1800;

    public function __construct(
        private readonly OriginPool $pool,
        private readonly OriginServerSync $sync,
    ) {}

    /**
     * @return list<int>
     */
    public function offerIdsOn(OriginServer $server): array
    {
        $host = $this->sync->normalizeHost((string) $server->host);
        $ids = [];

        Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->select(['id', 'deploy_panel_name', 'infra_meta'])
            ->chunkById(500, function ($chunk) use (&$ids, $host): void {
                foreach ($chunk as $offer) {
                    if ($this->sync->normalizeHost($this->pool->boundHost($offer)) === $host) {
                        $ids[] = (int) $offer->id;
                    }
                }
            });

        return $ids;
    }

    /**
     * @return array{offers: int, targets: array<string, int>, queued: int}
     */
    public function evacuate(OriginServer $server, bool $markDrain = true): array
    {
        $offerIds = $this->offerIdsOn($server);

        if ($markDrain) {
            $server->forceFill(['role' => OriginServer::ROLE_DRAIN])->save();
        }

        if ($offerIds === []) {
            return ['offers' => 0, 'targets' => [], 'queued' => 0];
        }

        $assignment = $this->pool->spread($offerIds, $server);
        $from = trim((string) $server->host);
        $moves = [];
        $targets = [];

        foreach ($offerIds as $offerId) {
            $host = $assignment[(int) $offerId] ?? null;
            if ($host === null) {
                continue;
            }
            $moves[(int) $offerId] = [
                'id' => (int) $offerId,
                'from' => $from,
                'to' => $host,
            ];
            $targets[$host] = ($targets[$host] ?? 0) + 1;
        }

        $queued = $this->retargetAndQueue($moves);

        Log::info('Origin evacuation queued', [
            'from' => $server->host,
            'offers' => count($offerIds),
            'targets' => $targets,
        ]);

        return [
            'offers' => count($offerIds),
            'targets' => $targets,
            'queued' => $queued,
        ];
    }

    /**
     * Origins an offer can be moved onto (active, SSH, not draining, not down).
     *
     * @return list<array{host: string, label: string, hoster: string, role: string, offers_count: int}>
     */
    public function moveTargets(): array
    {
        $counts = $this->pool->offerCounts();

        return OriginServer::query()
            ->where('is_active', true)
            ->get()
            ->sort(OriginServer::compareForPanel(...))
            ->values()
            ->filter(fn (OriginServer $server) => $this->isMovableTarget($server))
            ->map(function (OriginServer $server) use ($counts) {
                $host = $this->sync->normalizeHost((string) $server->host);

                return [
                    'host' => trim((string) $server->host),
                    'label' => $server->displayLabel(),
                    'hoster' => trim((string) ($server->hoster ?? '')),
                    'role' => $server->role(),
                    'offers_count' => (int) ($counts[$host] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function movableTarget(string $host): OriginServer
    {
        $server = $this->pool->serverForHost($host);
        if ($server === null) {
            throw new InvalidArgumentException("Сервер {$host} не в реєстрі origin.");
        }
        if (! $server->is_active) {
            throw new InvalidArgumentException("Сервер {$host} вимкнений.");
        }
        if (! $server->hasSshCredentials()) {
            throw new InvalidArgumentException("Немає SSH до {$host}.");
        }
        if ($server->role() === OriginServer::ROLE_DRAIN) {
            throw new InvalidArgumentException("Сервер {$host} звільняється — на нього не перекидаємо.");
        }
        if ($server->healthStatus() === 'down') {
            throw new InvalidArgumentException("Сервер {$host} зараз down.");
        }

        return $server;
    }

    /**
     * Pin selected offers to one origin, redeploy files, then rebind DNS.
     *
     * @param  iterable<Offer>  $offers
     * @return array{moved: int, queued: int, skipped: int}
     */
    public function moveOffersTo(iterable $offers, OriginServer $target): array
    {
        $targetHost = $this->sync->normalizeHost((string) $target->host);
        $moves = [];
        $skipped = 0;

        foreach ($offers as $offer) {
            $from = $this->sync->normalizeHost($this->pool->boundHost($offer));
            if ($from === $targetHost) {
                $skipped++;
                continue;
            }

            $moves[(int) $offer->id] = [
                'id' => (int) $offer->id,
                'from' => $from,
                'to' => trim((string) $target->host),
            ];
        }

        if ($moves === []) {
            return ['moved' => 0, 'queued' => 0, 'skipped' => $skipped];
        }

        $queued = $this->retargetAndQueue($moves);

        Log::info('Offer origin move queued', [
            'to' => $targetHost,
            'moved' => count($moves),
        ]);

        return [
            'moved' => count($moves),
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    private function isMovableTarget(OriginServer $server): bool
    {
        return $server->is_active
            && $server->hasSshCredentials()
            && $server->role() !== OriginServer::ROLE_DRAIN
            && $server->healthStatus() !== 'down';
    }

    /**
     * @return array{
     *     hosts: list<string>,
     *     total: int,
     *     target: array<string, int>,
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     moves: list<array{id: int, brand: string, from: string, to: string}>
     * }
     */
    public function planRebalance(): array
    {
        $servers = $this->rebalanceServers();
        $hosts = [];
        $limits = [];
        foreach ($servers as $server) {
            $host = $this->sync->normalizeHost((string) $server->host);
            $hosts[] = $host;
            $max = (int) ($server->max_offers ?? 0);
            $limits[$host] = $max > 0 ? $max : null;
        }

        $rows = [];
        $before = array_fill_keys($hosts, 0);

        Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->select(['id', 'brand', 'deploy_panel_name', 'infra_meta'])
            ->chunkById(500, function ($chunk) use (&$rows, &$before, $hosts): void {
                foreach ($chunk as $offer) {
                    $host = $this->sync->normalizeHost($this->pool->boundHost($offer));
                    if (! in_array($host, $hosts, true)) {
                        continue;
                    }
                    $rows[] = [
                        'id' => (int) $offer->id,
                        'brand' => (string) ($offer->brand ?? ''),
                        'host' => $host,
                    ];
                    $before[$host]++;
                }
            });

        $moves = OriginRebalancePlanner::plan($hosts, $rows, $limits);
        $target = OriginRebalancePlanner::targets($hosts, $before, array_sum($before), $limits);
        $after = $before;
        foreach ($moves as $move) {
            $after[$move['from']]--;
            $after[$move['to']]++;
        }

        return [
            'hosts' => $hosts,
            'total' => array_sum($before),
            'target' => $target,
            'before' => $before,
            'after' => $after,
            'moves' => $moves,
        ];
    }

    /**
     * @return array{moved: int, targets: array<string, int>, queued: int, before: array<string, int>, after: array<string, int>}
     */
    public function rebalance(): array
    {
        $plan = $this->planRebalance();
        $moves = $plan['moves'];

        if ($moves === []) {
            return [
                'moved' => 0,
                'targets' => [],
                'queued' => 0,
                'before' => $plan['before'],
                'after' => $plan['after'],
            ];
        }

        $byId = [];
        foreach ($moves as $move) {
            $byId[(int) $move['id']] = $move;
        }

        $queued = $this->retargetAndQueue($byId);

        $targets = [];
        foreach ($moves as $move) {
            $targets[$move['to']] = ($targets[$move['to']] ?? 0) + 1;
        }

        Log::info('Origin pool rebalance queued', [
            'moved' => count($moves),
            'targets' => $targets,
        ]);

        return [
            'moved' => count($moves),
            'targets' => $targets,
            'queued' => $queued,
            'before' => $plan['before'],
            'after' => $plan['after'],
        ];
    }

    /**
     * Pool boxes that can take a lander (same set as allocate, plus currently full ones as sources).
     *
     * @return list<OriginServer>
     */
    private function rebalanceServers(): array
    {
        $servers = [];

        foreach (OriginServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            if ($server->role() !== OriginServer::ROLE_POOL) {
                continue;
            }
            if (! $server->hasSshCredentials()) {
                continue;
            }
            if ($server->healthStatus() === 'down') {
                continue;
            }
            $servers[] = $server;
        }

        return $servers;
    }

    /**
     * @param  array<int, array{id?: int, from: string, to: string}>  $moves  keyed by offer id
     */
    private function retargetAndQueue(array $moves): int
    {
        $offerIds = array_map('intval', array_keys($moves));

        foreach (Offer::query()->whereIn('id', $offerIds)->orderBy('id')->cursor() as $offer) {
            $move = $moves[(int) $offer->id] ?? null;
            $host = $move['to'] ?? null;
            if ($host === null) {
                continue;
            }

            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            $meta['deploy_host'] = $host;
            $meta['migrated_from'] = $move['from'] ?? $this->pool->boundHost($offer);

            $offer->forceFill([
                'infra_meta' => $meta,
                'deploy_panel_name' => $host,
                'provision_infrastructure' => true,
            ])->save();
        }

        $this->pool->forgetCounts();

        $queued = 0;
        foreach ($offerIds as $offerId) {
            $host = $moves[$offerId]['to'] ?? null;
            if ($host === null) {
                continue;
            }

            $chain = [
                new DeployOfferJob($offerId),
                new RebindOfferDnsJob($offerId, $host),
            ];

            Bus::chain($chain)->onQueue('deploy')->dispatch();

            // Purge sits on its own queue so leftover origin deletes cannot block new offers.
            $from = $this->sync->normalizeHost((string) ($moves[$offerId]['from'] ?? ''));
            $to = $this->sync->normalizeHost((string) $host);
            if ($from !== '' && $from !== $to) {
                PurgeOfferFromOriginJob::dispatch($offerId, $from)
                    ->delay(now()->addSeconds(self::PURGE_AFTER_REBIND_SECONDS));
            }
            $queued++;
        }

        return $queued;
    }
}
