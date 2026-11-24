<?php

namespace App\Console\Commands;

use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use App\Services\ProxyPool;
use Illuminate\Console\Command;

class SwitchAllOffersToProxy extends Command
{
    protected $signature = 'offers:switch-all-to-proxy
                            {--proxy= : Pin every offer to this proxy id (default: spread across the pool)}
                            {--dry-run : Count only, do not queue}';

    protected $description = 'Put every live offer behind the edge proxy instead of orange Cloudflare';

    public function handle(ProxyPool $pool): int
    {
        $pinnedId = (int) $this->option('proxy');
        $pinned = null;
        if ($pinnedId > 0) {
            $pinned = ProxyServer::query()->whereKey($pinnedId)->where('is_active', true)->first();
            if ($pinned === null) {
                $this->error('Немає активного проксі з таким id.');

                return self::FAILURE;
            }
        } elseif (! $pool->hasCapacity()) {
            $this->error('Немає активного проксі.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $queued = 0;
        $skipped = 0;
        $pending = [];

        $offers = Offer::query()
            ->where('provision_infrastructure', true)
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->get(['id', 'domain', 'status', 'infra_meta']);

        foreach ($offers as $offer) {
            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            $currentId = (int) ($meta['edge_proxy_id'] ?? 0);
            $onProxy = ($meta['edge'] ?? '') === 'proxy' && $currentId > 0;

            if ($pinned !== null) {
                if ($onProxy && $currentId === (int) $pinned->id) {
                    $skipped++;

                    continue;
                }
            } elseif ($onProxy) {
                $skipped++;

                continue;
            }

            $pending[] = $offer;
        }

        $assignment = [];
        if ($pinned !== null) {
            foreach ($pending as $offer) {
                $assignment[(int) $offer->id] = (int) $pinned->id;
            }
        } elseif ($pending !== []) {
            $assignment = $pool->spread(array_map(
                static fn (Offer $offer): int => (int) $offer->id,
                $pending,
            ));
        }

        foreach ($pending as $offer) {
            $proxyId = (int) ($assignment[(int) $offer->id] ?? 0);
            if ($proxyId < 1) {
                $this->warn("skip {$offer->domain}: no proxy assignment");

                continue;
            }

            if (! $dry) {
                SwitchOfferEdgeJob::dispatch($offer->id, 'proxy:'.$proxyId);
            }
            $queued++;
            $this->line(($dry ? 'would-queue' : 'queued').": {$offer->domain} → proxy:{$proxyId}");
        }

        $label = $pinned !== null ? "proxy={$pinned->host}" : 'spread=pool';
        $this->info("{$label} queued={$queued} already_on_proxy={$skipped}");

        return self::SUCCESS;
    }
}
