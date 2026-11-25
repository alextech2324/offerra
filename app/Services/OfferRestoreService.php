<?php

namespace App\Services;

use App\Models\GeoMinDeposit;
use App\Models\Offer;
use App\Models\User;
use App\Support\CloakOptions;
use App\Support\InfrastructureOptions;
use App\Support\MarketOptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfferRestoreService
{
    public function __construct(
        private readonly OfferGenerator $generator,
        private readonly InfrastructureProvisioner $provisioner,
    ) {}

    /**
     * @param  array{template?: string, geo?: string}  $overrides
     */
    public function restore(Offer $offer, User $initiatedBy, array $overrides = []): Offer
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

        $this->applyRestoreChoices($offer, $overrides);

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
            // Start a new 14-day brand shield immediately, same as adding a domain.
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

    /**
     * Apply optional template / GEO before rebuildLocalFolder, while still archived.
     *
     * @param  array{template?: string, geo?: string}  $overrides
     */
    private function applyRestoreChoices(Offer $offer, array $overrides): void
    {
        $hasTemplate = filled($overrides['template'] ?? null);
        $hasGeo = filled($overrides['geo'] ?? null);

        if (! $hasTemplate && ! $hasGeo) {
            return;
        }

        $catalog = app(TemplateCatalog::class);
        $template = $hasTemplate
            ? trim((string) $overrides['template'])
            : (string) $offer->template;
        $allowed = $catalog->selectableIds((string) $offer->template);

        if (! in_array($template, $allowed, true)) {
            throw new RuntimeException('Цей шаблон вимкнено');
        }

        $geo = $hasGeo
            ? MarketOptions::normalizeGeo((string) $overrides['geo'])
            : MarketOptions::normalizeGeo((string) $offer->geo);

        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => $template,
            'geo' => $geo,
            'lang' => (string) $offer->lang,
            'phone' => (string) $offer->phone,
            'phone_countries' => $offer->phoneCountriesList(),
        ]);

        $template = (string) $pinned['template'];
        $geo = (string) $pinned['geo'];
        $lang = strtolower((string) $pinned['lang']);
        $phone = strtolower((string) $pinned['phone']);
        $phoneCountries = is_array($pinned['phone_countries'] ?? null)
            ? $pinned['phone_countries']
            : $offer->phoneCountriesList();
        $minDeposit = (string) ($offer->min_deposit ?: '250');
        $currency = strtoupper((string) ($offer->currency ?: 'EUR'));

        if (! TemplateCatalog::usesMlGeo($template) && $hasGeo) {
            $lang = MarketOptions::defaultLangForGeo($geo);
            $codes = $catalog->languageCodesFor($template);
            if ($codes !== [] && ! in_array($lang, $codes, true)) {
                $lang = in_array('en', $codes, true) ? 'en' : $codes[0];
            }

            $geoPhone = MarketOptions::phoneForGeo($geo);
            $phoneCountries = MarketOptions::sanitizePhoneCodes($phoneCountries);
            if (! in_array($geoPhone, $phoneCountries, true)) {
                $phoneCountries[] = $geoPhone;
            }
            $phone = $geoPhone;

            $deposit = GeoMinDeposit::catalog()[$geo] ?? null;
            if (is_array($deposit)) {
                if (filled($deposit['min_deposit'] ?? null)) {
                    $minDeposit = (string) $deposit['min_deposit'];
                }
                if (filled($deposit['currency'] ?? null)) {
                    $currency = strtoupper((string) $deposit['currency']);
                }
            } else {
                $preset = MarketOptions::geoPresetsByCode()[$geo] ?? null;
                if (! empty($preset['currency'])) {
                    $currency = strtoupper((string) $preset['currency']);
                }
            }
        }

        $normalized = MarketOptions::normalizePhoneFields($phone, $phoneCountries, $geo);

        $offer->forceFill([
            'template' => $template,
            'geo' => $geo,
            'lang' => $lang,
            'phone' => $normalized['phone'],
            'phone_countries' => implode(',', $normalized['phone_countries']),
            'min_deposit' => $minDeposit,
            'currency' => $currency,
        ])->save();
    }
}
