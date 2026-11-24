<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use App\Support\CloakOptions;
use App\Support\InfrastructureOptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfferRestoreService
{
    public function __construct(
        private readonly OfferGenerator $generator,
        private readonly InfrastructureProvisioner $provisioner,
    ) {}

    public function restore(Offer $offer, User $initiatedBy): Offer
    {
        $offer->refresh();
        $offer->loadMissing('user.settings');

        if ($offer->status !== 'archived') {
            throw new RuntimeException('Повернути з архіву можна лише повністю заархівований оффер.');
        }

        $domain = strtolower(trim((string) $offer->domain));

        $conflict = Offer::query()
            ->whereRaw('LOWER(domain) = ?', [$domain])
            ->where('id', '!=', $offer->id)
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->exists();

        if ($conflict) {
            throw new RuntimeException(
                "Активний оффер з доменом {$domain} вже існує. Спочатку заархівуйте його або змініть домен.",
            );
        }

        $settings = $offer->user?->settings;

        if (! $settings) {
            throw new RuntimeException('У власника оффера немає налаштувань Server.');
        }

        if (! InfrastructureProvisioner::settingsReady($settings)) {
            throw new RuntimeException(
                'Заповніть SSH, Cloudflare і Dynadot у налаштуваннях власника, щоб поновити оффер.',
            );
        }

        // Drop the old origin pin before rebuild so restore cannot SSH a deleted IP.
        app(OriginPool::class)->unbind($offer);

        // Rebuild while still archived so a failure keeps the offer in the archive list.
        $this->generator->rebuildLocalFolder($offer->fresh());

        $options = InfrastructureOptions::fromPreferredEdge();

        // Fresh infra progress — archive deleted origin/CF zone; do not trust old "done" flags.
        $meta = CloakOptions::mergeIntoInfraMeta(
            ['options' => $options],
            CloakOptions::fromOffer($offer),
        );

        $offer->update([
            'status' => 'generated',
            'archived_at' => null,
            'archived_by' => null,
            'teardown_meta' => null,
            'deploy_error' => null,
            'remote_path' => null,
            'deployed_at' => null,
            // Fresh pool allocation on restore — the old server may be gone or full.
            'deploy_panel_name' => null,
            'provision_infrastructure' => true,
            'infra_status' => 'pending',
            'infra_error' => null,
            'infra_meta' => $meta,
            'availability_status' => 'unchecked',
            'availability_error' => null,
            'availability_checked_at' => null,
            'submitted_for_indexing' => false,
            // Start a new 21-day brand shield immediately, same as adding a domain.
            // Inspect still waits: it requires submitted_for_indexing.
            'indexed_at' => now(),
            'google_index_status' => null,
            'google_indexed_at' => null,
            'google_index_checked_at' => null,
            'google_index_coverage' => null,
            'google_index_canonical' => null,
        ]);

        $this->provisioner->enqueue($offer->fresh());

        Log::info('Offer restored from archive', [
            'offer_id' => $offer->id,
            'domain' => $domain,
            'by' => $initiatedBy->id,
        ]);

        return $offer->fresh();
    }
}
