<?php

namespace App\Console\Commands;

use App\Jobs\RecheckInfrastructureDnsJob;
use App\Models\Offer;
use App\Services\InfrastructureProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecheckInfrastructureDns extends Command
{
    protected $signature = 'offers:recheck-infra-dns';

    protected $description = 'Перевірити DNS для офферів, що очікують поширення';

    public function handle(): int
    {
        $offers = Offer::query()
            ->where('provision_infrastructure', true)
            ->where('infra_status', 'ready')
            ->orderBy('id')
            ->get(['id', 'domain', 'status', 'availability_status', 'provision_infrastructure', 'infra_status', 'infra_meta']);

        $this->settlePubliclyReadyRegistrarNs($offers);
        $this->settleLiveDeployedOffers($offers);
        $this->promoteQueuedRechecksToDns();
        $pruned = $this->pruneDuplicateSwitchJobs();
        if ($pruned > 0) {
            $this->info("pruned duplicate switch jobs: {$pruned}");
        }

        $pendingOffers = $offers->filter(
            fn ($offer) => $offer->dnsStatus() === 'pending' || $offer->needsProxySslRetry(),
        );

        if ($pendingOffers->isEmpty()) {
            return self::SUCCESS;
        }

        $alreadyQueuedIds = $this->queuedRecheckOfferIds() + $this->queuedSwitchOfferIds();

        $queued = 0;
        foreach ($pendingOffers as $offer) {
            if (isset($alreadyQueuedIds[$offer->id])) {
                continue;
            }

            RecheckInfrastructureDnsJob::dispatch($offer->id);
            $alreadyQueuedIds[$offer->id] = true;
            $this->line("queued: {$offer->domain}");
            $queued++;
        }

        if ($queued === 0) {
            $this->info('all pending offers already queued');
        }

        return self::SUCCESS;
    }

    /**
     * Public NS already match the CF pair, but Dynadot never confirmed set_ns.
     * Stop the orange «Очікується DNS» and the every-minute retry.
     *
     * @param  Collection<int, Offer>  $offers
     */
    private function settlePubliclyReadyRegistrarNs($offers): void
    {
        $settled = 0;
        foreach ($offers as $offer) {
            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            if (($meta['dynadot_ns'] ?? '') !== 'pending') {
                continue;
            }
            if (($meta['dns_via'] ?? '') !== 'records') {
                continue;
            }

            $meta['dynadot_ns'] = 'done';
            unset($meta['dynadot_ns_error']);
            if (($meta['dns'] ?? null) !== 'done') {
                $meta['dns'] = 'done';
            }
            $offer->update(['infra_meta' => $meta]);
            $settled++;
        }

        if ($settled > 0) {
            $this->info("settled registrar NS (public records already match): {$settled}");
        }
    }

    /**
     * Deployed offers that already answer HTTPS but still show pending DNS.
     *
     * @param  Collection<int, Offer>  $offers
     */
    private function settleLiveDeployedOffers($offers): void
    {
        $provisioner = app(InfrastructureProvisioner::class);
        $settled = 0;
        foreach ($offers as $offer) {
            if ($provisioner->settleIfAlreadyLive($offer)) {
                $settled++;
            }
        }

        if ($settled > 0) {
            $this->info("settled live deployed offers: {$settled}");
        }
    }

    /**
     * Recheck used to sit on `default` (behind CRM/GSC) and later on `deploy`
     * (blocking offer create). Move leftover unreserved rows onto `dns`.
     */
    private function promoteQueuedRechecksToDns(): void
    {
        $moved = DB::table('jobs')
            ->whereIn('queue', ['default', 'deploy'])
            ->whereNull('reserved_at')
            ->where(function ($q): void {
                $q->where('payload', 'like', '%RecheckInfrastructureDnsJob%')
                    ->orWhere('payload', 'like', '%RebindOfferDnsJob%')
                    ->orWhere('payload', 'like', '%SwitchOfferEdgeJob%');
            })
            ->update(['queue' => 'dns']);

        if ($moved > 0) {
            $this->info("moved DNS jobs to dns queue: {$moved}");
        }
    }

    /**
     * Keep one SwitchOfferEdgeJob per offer. Recheck used to re-dispatch after
     * the 15-minute unique lock expired, while the first job was still waiting.
     */
    private function pruneDuplicateSwitchJobs(): int
    {
        $keep = [];
        $delete = [];

        DB::table('jobs')
            ->where('payload', 'like', '%SwitchOfferEdgeJob%')
            ->whereNull('reserved_at')
            ->orderBy('id')
            ->select(['id', 'payload'])
            ->chunkById(200, function ($rows) use (&$keep, &$delete): void {
                foreach ($rows as $job) {
                    $offerId = $this->extractOfferId((string) $job->payload);
                    if (! $offerId) {
                        continue;
                    }
                    if (isset($keep[$offerId])) {
                        $delete[] = (int) $job->id;
                    } else {
                        $keep[$offerId] = true;
                    }
                }
            });

        if ($delete === []) {
            return 0;
        }

        return DB::table('jobs')->whereIn('id', $delete)->whereNull('reserved_at')->delete();
    }

    /**
     * @return array<int, true>
     */
    private function queuedRecheckOfferIds(): array
    {
        return $this->queuedOfferIds('%RecheckInfrastructureDnsJob%');
    }

    /**
     * @return array<int, true>
     */
    private function queuedSwitchOfferIds(): array
    {
        return $this->queuedOfferIds('%SwitchOfferEdgeJob%');
    }

    /**
     * @return array<int, true>
     */
    private function queuedOfferIds(string $payloadLike): array
    {
        $alreadyQueuedIds = [];

        DB::table('jobs')
            ->where('payload', 'like', $payloadLike)
            ->orderBy('id')
            ->select(['id', 'payload'])
            ->chunkById(200, function ($rows) use (&$alreadyQueuedIds): void {
                foreach ($rows as $job) {
                    $offerId = $this->extractOfferId((string) $job->payload);
                    if ($offerId) {
                        $alreadyQueuedIds[$offerId] = true;
                    }
                }
            });

        return $alreadyQueuedIds;
    }

    private function extractOfferId(string $payload): ?int
    {
        if (preg_match('/offerId\\\\?";i:(\d+)/', $payload, $m)) {
            return (int) $m[1];
        }

        $data = json_decode($payload, true);
        $cmd = $data['data']['command'] ?? null;

        if (! is_string($cmd) || $cmd === '') {
            return null;
        }

        try {
            $obj = unserialize($cmd, ['allowed_classes' => [RecheckInfrastructureDnsJob::class]]);
            if ($obj instanceof RecheckInfrastructureDnsJob) {
                return (int) $obj->offerId;
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
