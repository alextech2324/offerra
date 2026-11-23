<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Models\ProxyServer;
use App\Services\DeployService;
use App\Services\EdgeProxyService;
use App\Services\InfrastructureProvisioner;
use App\Support\InfrastructureOptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecheckInfrastructureDnsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    /**
     * One queued recheck per offer at a time — a perpetually-pending domain must
     * never accumulate duplicate jobs (this is what flooded the queue to 8k+).
     * Keep this just above $timeout: a killed worker holds the unique lock until
     * uniqueFor, and 15 minutes of silence looks like DNS is stuck.
     */
    public int $uniqueFor = 400;

    public function __construct(public int $offerId)
    {
        // Own queue: default is flooded by CRM/GSC, and deploy is what the
        // user waits on for create / files. Recheck can sit behind neither.
        $this->onQueue('dns');
    }

    public function uniqueId(): string
    {
        return (string) $this->offerId;
    }

    public function handle(InfrastructureProvisioner $provisioner, DeployService $deploy, EdgeProxyService $edge): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user || ! $offer->provision_infrastructure) {
            return;
        }

        $this->retryPendingProxySsl($offer, $edge);

        if (in_array($offer->dnsStatus(), ['ready', 'skipped', 'na'], true)) {
            return;
        }

        if ($provisioner->settleIfAlreadyLive($offer)) {
            return;
        }

        try {
            $provisioner->recheckDns($offer);
            if ($deploy->enqueueDeployAfterInfra($offer->fresh())) {
                Log::info('Auto-deploy queued after DNS recheck', [
                    'offer' => $this->offerId,
                    'domain' => $offer->domain,
                ]);
            }
            $provisioner->enqueueProxyEdgeIfRequested($offer->fresh());
        } catch (\Throwable $e) {
            Log::error('Infrastructure DNS recheck failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function retryPendingProxySsl(Offer $offer, EdgeProxyService $edge): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $options = InfrastructureOptions::forOffer($offer);
        $wantsProxy = InfrastructureOptions::wantsProxy($options)
            || ($meta['edge'] ?? '') === 'proxy';
        if (! $wantsProxy) {
            return;
        }
        if (($meta['edge'] ?? '') === 'proxy' && ($meta['ssl'] ?? '') === 'done') {
            return;
        }

        $proxyId = (int) ($meta['edge_proxy_id'] ?? $options['edge_proxy_id'] ?? 0);
        $proxy = $proxyId > 0 ? ProxyServer::query()->find($proxyId) : null;
        if ($proxy === null) {
            return;
        }

        try {
            $edge->attach($offer, $proxy);
        } catch (\Throwable $e) {
            Log::warning('Pending proxy SSL retry failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
