<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\DeployService;
use App\Services\InfrastructureProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecheckInfrastructureDnsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    /**
     * One queued recheck per offer at a time — a perpetually-pending domain must
     * never accumulate duplicate jobs (this is what flooded the queue to 8k+).
     * Keep this just above $timeout: a killed worker holds the unique lock until
     * uniqueFor, and 15 minutes of silence looks like DNS is stuck.
     */
    public int $uniqueFor = 200;

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

    public function handle(InfrastructureProvisioner $provisioner, DeployService $deploy): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user || ! $offer->provision_infrastructure) {
            return;
        }

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
        } catch (\Throwable $e) {
            Log::error('Infrastructure DNS recheck failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
