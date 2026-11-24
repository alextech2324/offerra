<?php

namespace App\Jobs;

use App\Models\Offer;
use App\Services\DeployService;
use App\Services\InfrastructureProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionInfrastructureJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $offerId) {}

    public function handle(InfrastructureProvisioner $provisioner, DeployService $deploy): void
    {
        $offer = Offer::query()->with('user.settings')->find($this->offerId);

        if (! $offer?->user || ! $offer->provision_infrastructure) {
            return;
        }

        try {
            $provisioner->provision($offer);
            $offer->refresh();

            if ($deploy->enqueueDeployAfterInfra($offer->fresh())) {
                Log::info('Auto-deploy queued after infra', [
                    'offer' => $this->offerId,
                    'domain' => $offer->domain,
                ]);
            }

            if ($offer->dnsStatus() === 'pending') {
                $nsPending = ($offer->infra_meta['dynadot_ns'] ?? '') === 'pending';
                RecheckInfrastructureDnsJob::dispatch($offer->id)
                    ->delay($nsPending ? now()->addMinutes(5) : now());
            }
        } catch (\Throwable $e) {
            Log::error('Infrastructure provisioning failed', [
                'offer' => $this->offerId,
                'domain' => $offer->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
