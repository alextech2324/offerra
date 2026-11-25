<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\OfferAvailabilityProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Manual mass HTTPS recheck: all current red dots, a selected id list,
 * or (admin) every deployed offer regardless of the current dot.
 */
class ScanDownOffersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /**
     * @param  list<int>|null  $offerIds
     */
    public function __construct(
        public ?int $userId = null,
        public ?array $offerIds = null,
        public bool $allDeployed = false,
    ) {
        $this->onQueue('gsc');
        if ($this->offerIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $this->offerIds)));
            sort($ids);
            $this->offerIds = $ids;
        }
    }

    public function uniqueId(): string
    {
        if ($this->offerIds !== null) {
            return 'scan-availability:ids:'.md5(implode(',', $this->offerIds));
        }

        $scope = $this->allDeployed ? 'all' : 'down';

        return 'scan-availability:'.$scope.':'.($this->userId ?? 'all');
    }

    public function handle(OfferAvailabilityProbe $probe): void
    {
        @set_time_limit(0);

        $query = Offer::query()->where('status', 'deployed');

        if ($this->offerIds !== null) {
            if ($this->offerIds === []) {
                return;
            }
            $query->whereIn('id', $this->offerIds);
        } else {
            if (! $this->allDeployed) {
                $query->where('availability_status', 'down');
            }
            if ($this->userId !== null) {
                $query->where('user_id', $this->userId);
            }
        }

        $totals = ['ok' => 0, 'down' => 0, 'soft_fail' => 0, 'n' => 0];

        $query->orderBy('id')->chunkById(80, function ($offers) use ($probe, &$totals): void {
            $totals['n'] += $offers->count();
            $counts = $probe->checkAndUpdateMany($offers->all());
            $totals['ok'] += $counts['ok'];
            $totals['down'] += $counts['down'];
            $totals['soft_fail'] += $counts['soft_fail'];
        });

        Log::info('Mass availability scan finished', [
            'user_id' => $this->userId,
            'ids' => $this->offerIds !== null ? count($this->offerIds) : null,
            'all_deployed' => $this->allDeployed,
            ...$totals,
        ]);
    }
}
