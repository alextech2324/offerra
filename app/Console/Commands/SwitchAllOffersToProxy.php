<?php

namespace App\Console\Commands;

use App\Jobs\SwitchOfferEdgeJob;
use App\Models\Offer;
use App\Models\ProxyServer;
use Illuminate\Console\Command;

class SwitchAllOffersToProxy extends Command
{
    protected $signature = 'offers:switch-all-to-proxy
                            {--proxy= : Proxy server id (default: first active)}
                            {--dry-run : Count only, do not queue}';

    protected $description = 'Put every live offer behind the edge proxy instead of orange Cloudflare';

    public function handle(): int
    {
        $proxy = $this->resolveProxy();
        if ($proxy === null) {
            $this->error('Немає активного проксі.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $queued = 0;
        $skipped = 0;

        $offers = Offer::query()
            ->where('provision_infrastructure', true)
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->get(['id', 'domain', 'status', 'infra_meta']);

        foreach ($offers as $offer) {
            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            if (($meta['edge'] ?? '') === 'proxy'
                && (int) ($meta['edge_proxy_id'] ?? 0) === (int) $proxy->id) {
                $skipped++;

                continue;
            }

            if (! $dry) {
                SwitchOfferEdgeJob::dispatch($offer->id, 'proxy:'.$proxy->id);
            }
            $queued++;
            $this->line(($dry ? 'would-queue' : 'queued').": {$offer->domain}");
        }

        $this->info("proxy={$proxy->host} queued={$queued} already_on_proxy={$skipped}");

        return self::SUCCESS;
    }

    private function resolveProxy(): ?ProxyServer
    {
        $id = (int) $this->option('proxy');
        if ($id > 0) {
            return ProxyServer::query()->whereKey($id)->where('is_active', true)->first();
        }

        return ProxyServer::query()->where('is_active', true)->orderBy('id')->first();
    }
}
