<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\DynadotClient;
use App\Services\InfrastructureProvisioner;
use Illuminate\Console\Command;

class RequeueUnauthorizedDynadot extends Command
{
    protected $signature = 'offers:requeue-unauthorized-dynadot {--dry-run : Count only, do not enqueue}';

    protected $description = 'Re-run provision for live offers that failed because the panel IP is not on the Dynadot API whitelist';

    public function handle(InfrastructureProvisioner $provisioner): int
    {
        $dry = (bool) $this->option('dry-run');
        $queued = 0;

        Offer::query()
            ->where('provision_infrastructure', true)
            ->where('infra_status', 'failed')
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->each(function (Offer $offer) use ($provisioner, $dry, &$queued): void {
                if (! DynadotClient::isUnauthorizedIpError((string) $offer->infra_error)) {
                    return;
                }

                $this->line(($dry ? 'would-queue: ' : 'queued: ').$offer->domain);
                $queued++;

                if ($dry) {
                    return;
                }

                $provisioner->enqueue($offer);
            });

        $this->info($dry ? "would requeue {$queued}" : "requeued {$queued}");

        return self::SUCCESS;
    }
}
