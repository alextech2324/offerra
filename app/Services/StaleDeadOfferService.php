<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StaleDeadOfferService
{
    public const CACHE_LAST_SCAN_AT = 'stale_dead.last_scan_at';

    public const SNAPSHOT_PATH = 'stale-dead-snapshot.json';

    /** Last GSC submit of the brand, and last lead on any live sibling. */
    public const DEAD_AFTER_DAYS = 21;

    /** @var list<string> */
    private const INACTIVE_STATUSES = ['archived', 'archiving', 'purging', 'teardown_failed'];

    public function __construct(
        private readonly OfferTeardownService $teardown,
    ) {}

    public function cutoff(int $days = self::DEAD_AFTER_DAYS): CarbonInterface
    {
        return now()->subDays(max(1, $days));
    }

    /**
     * Live offers of a brand whose newest circulation is older than N days and
     * whose newest lead on any live sibling is also older than N days (or never).
     *
     * Circulation = GSC submit (`indexed_at`) when present, otherwise `created_at`.
     * Adding or restoring a domain therefore resets the 21-day shield for the
     * whole brand. Brands that never submitted to GSC are left alone.
     * Deposits and clicks do not protect; archived siblings do not.
     */
    public function baseCandidatesQuery(int $days = self::DEAD_AFTER_DAYS): Builder
    {
        $cutoff = $this->cutoff($days);

        return Offer::query()
            ->whereNotIn('offers.status', self::INACTIVE_STATUSES)
            ->whereExists(function ($q) use ($cutoff): void {
                $q->selectRaw('1')
                    ->from('offers as brand_offers')
                    ->leftJoin('offer_stats as brand_stats', 'brand_stats.offer_id', '=', 'brand_offers.id')
                    ->whereColumn('brand_offers.user_id', 'offers.user_id')
                    ->whereRaw('LOWER(TRIM(brand_offers.brand)) = LOWER(TRIM(offers.brand))')
                    ->whereNotIn('brand_offers.status', self::INACTIVE_STATUSES)
                    ->groupBy('brand_offers.user_id')
                    ->groupByRaw('LOWER(TRIM(brand_offers.brand))')
                    ->havingRaw('MAX(brand_offers.indexed_at) IS NOT NULL')
                    ->havingRaw('MAX(COALESCE(brand_offers.indexed_at, brand_offers.created_at)) < ?', [$cutoff])
                    ->havingRaw('(MAX(brand_stats.last_lead_at) IS NULL OR MAX(brand_stats.last_lead_at) < ?)', [$cutoff]);
            });
    }

    /**
     * Scope: own offers, or all if the user may manage everyone's offers.
     */
    public function candidatesQuery(User $user, int $days = self::DEAD_AFTER_DAYS): Builder
    {
        $query = $this->baseCandidatesQuery($days);

        if (! $user->canManageAllOffers()) {
            $query->where('offers.user_id', $user->id);
        }

        return $query;
    }

    /**
     * Banner reads the daily 10:00 snapshot only — never a live recount.
     *
     * @return array{count: int, last_scan_at: string|null}
     */
    public function hintFor(User $user, int $days = self::DEAD_AFTER_DAYS): array
    {
        $snapshot = $this->readSnapshot();

        return [
            'count' => $this->snapshotCountFor($user, $snapshot),
            'last_scan_at' => $snapshot['scanned_at'],
        ];
    }

    /**
     * Freeze today's candidates for the Archive banner. Does not enqueue archive.
     *
     * @return array{total: int, by_user: array<int, int>}
     */
    public function scan(int $days = self::DEAD_AFTER_DAYS): array
    {
        $offers = $this->baseCandidatesQuery($days)
            ->orderBy('offers.id')
            ->get(['offers.id', 'offers.user_id']);

        $grouped = [];
        foreach ($offers as $offer) {
            $uid = (int) $offer->user_id;
            $grouped[$uid] ??= ['count' => 0, 'ids' => []];
            $grouped[$uid]['ids'][] = (int) $offer->id;
            $grouped[$uid]['count']++;
        }

        $byUser = [];
        $total = 0;
        foreach ($grouped as $userId => $row) {
            $byUser[$userId] = $row['count'];
            $total += $row['count'];
        }

        $scannedAt = now()->toIso8601String();
        $this->writeSnapshot([
            'scanned_at' => $scannedAt,
            'days' => $days,
            'by_user' => $grouped,
        ]);
        Cache::forever(self::CACHE_LAST_SCAN_AT, $scannedAt);

        Log::info('stale_dead.scan', [
            'total' => $total,
            'by_user' => $byUser,
            'scanned_at' => $scannedAt,
            'days' => $days,
        ]);

        return [
            'total' => $total,
            'by_user' => $byUser,
        ];
    }

    public function archiveAllFor(User $user, int $days = self::DEAD_AFTER_DAYS): int
    {
        if (! $user->canArchiveOffers()) {
            return 0;
        }

        $snapshot = $this->readSnapshot();
        $ids = $this->snapshotIdsFor($user, $snapshot);

        $enqueued = 0;

        foreach ($ids as $id) {
            $offer = Offer::query()->find($id);
            if (! $offer) {
                continue;
            }

            if (! $user->canManageOffer($offer)) {
                continue;
            }

            try {
                $this->teardown->enqueueArchive($offer, $user);
                $enqueued++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'вже архівується') || str_contains($msg, 'в архіві')) {
                    continue;
                }
                Log::warning('stale_dead.archive_skip', [
                    'offer_id' => $offer->id,
                    'domain' => $offer->domain,
                    'error' => $msg,
                ]);
            }
        }

        $this->clearSnapshotFor($user, $snapshot);

        return $enqueued;
    }

    /**
     * @return array{scanned_at: string|null, days: int, by_user: array<int, array{count: int, ids: list<int>}>}
     */
    public function readSnapshot(): array
    {
        if (! Storage::disk('local')->exists(self::SNAPSHOT_PATH)) {
            return [
                'scanned_at' => null,
                'days' => self::DEAD_AFTER_DAYS,
                'by_user' => [],
            ];
        }

        $raw = json_decode((string) Storage::disk('local')->get(self::SNAPSHOT_PATH), true);
        if (! is_array($raw)) {
            return [
                'scanned_at' => null,
                'days' => self::DEAD_AFTER_DAYS,
                'by_user' => [],
            ];
        }

        $byUser = [];
        foreach (($raw['by_user'] ?? []) as $userId => $row) {
            $ids = [];
            if (is_array($row)) {
                foreach ($row['ids'] ?? [] as $id) {
                    $ids[] = (int) $id;
                }
            }
            $byUser[(int) $userId] = [
                'count' => (int) (is_array($row) ? ($row['count'] ?? count($ids)) : 0),
                'ids' => $ids,
            ];
        }

        $scannedAt = $raw['scanned_at'] ?? null;

        return [
            'scanned_at' => is_string($scannedAt) && $scannedAt !== '' ? $scannedAt : null,
            'days' => (int) ($raw['days'] ?? self::DEAD_AFTER_DAYS),
            'by_user' => $byUser,
        ];
    }

    /**
     * @param  array{scanned_at: string|null, days: int, by_user: array<int, array{count: int, ids: list<int>}>}  $snapshot
     */
    private function snapshotCountFor(User $user, array $snapshot): int
    {
        if ($user->canManageAllOffers()) {
            $total = 0;
            foreach ($snapshot['by_user'] as $row) {
                $total += (int) $row['count'];
            }

            return $total;
        }

        return (int) ($snapshot['by_user'][$user->id]['count'] ?? 0);
    }

    /**
     * @param  array{scanned_at: string|null, days: int, by_user: array<int, array{count: int, ids: list<int>}>}  $snapshot
     * @return list<int>
     */
    private function snapshotIdsFor(User $user, array $snapshot): array
    {
        if ($user->canManageAllOffers()) {
            $ids = [];
            foreach ($snapshot['by_user'] as $row) {
                foreach ($row['ids'] as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return $snapshot['by_user'][$user->id]['ids'] ?? [];
    }

    /**
     * @param  array{scanned_at: string|null, days: int, by_user: array<int, array{count: int, ids: list<int>}>}  $snapshot
     */
    private function clearSnapshotFor(User $user, array $snapshot): void
    {
        if ($user->canManageAllOffers()) {
            foreach (array_keys($snapshot['by_user']) as $userId) {
                $snapshot['by_user'][$userId] = ['count' => 0, 'ids' => []];
            }
        } else {
            $snapshot['by_user'][$user->id] = ['count' => 0, 'ids' => []];
        }

        $this->writeSnapshot($snapshot);
    }

    /**
     * @param  array{scanned_at: string|null, days: int, by_user: array<int, array{count: int, ids: list<int>}>}  $snapshot
     */
    private function writeSnapshot(array $snapshot): void
    {
        $disk = Storage::disk('local');
        $ok = $disk->put(
            self::SNAPSHOT_PATH,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        if ($ok === false) {
            throw new \RuntimeException('Не вдалося записати stale-dead snapshot.');
        }

        // Scheduler often runs as www-data; a root deploy scan must not leave
        // a 644 root-owned file that the next noon cannot overwrite.
        $path = $disk->path(self::SNAPSHOT_PATH);
        if (is_file($path)) {
            @chmod($path, 0666);
        }
    }
}
