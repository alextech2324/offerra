<?php

namespace App\Models;

use App\Support\InfrastructureOptions;
use App\Services\OfferGoogleIndexInspector;
use App\Services\TemplateCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Offer extends Model
{
    protected $fillable = [
        'user_id',
        'folder',
        'brand',
        'domain',
        'geo',
        'lang',
        'phone',
        'phone_countries',
        'min_deposit',
        'currency',
        'template',
        'status',
        'deploy_panel_name',
        'cloudflare_account_name',
        'dynadot_account_name',
        'cloudflare_api_token',
        'cloudflare_account_id',
        'dynadot_api_key',
        'dynadot_contact_id',
        'dynadot_sandbox',
        'remote_path',
        'deployed_at',
        'deploy_error',
        'archived_at',
        'archived_by',
        'teardown_meta',
        'submitted_for_indexing',
        'indexed_at',
        'google_index_status',
        'google_indexed_at',
        'google_index_checked_at',
        'google_index_coverage',
        'google_index_canonical',
        'verification_filename',
        'vitals_enabled',
        'from_search_team',
        'provision_infrastructure',
        'infra_status',
        'infra_error',
        'infra_meta',
        'availability_status',
        'availability_checked_at',
        'availability_error',
        'availability_fail_streak',
    ];

    protected function casts(): array
    {
        return [
            'deployed_at' => 'datetime',
            'archived_at' => 'datetime',
            'submitted_for_indexing' => 'boolean',
            'indexed_at' => 'datetime',
            'google_indexed_at' => 'datetime',
            'google_index_checked_at' => 'datetime',
            'availability_checked_at' => 'datetime',
            'availability_fail_streak' => 'integer',
            'provision_infrastructure' => 'boolean',
            'vitals_enabled' => 'boolean',
            'from_search_team' => 'boolean',
            'dynadot_sandbox' => 'boolean',
            'cloudflare_api_token' => 'encrypted',
            'dynadot_api_key' => 'encrypted',
            'infra_meta' => 'array',
            'teardown_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(OfferStat::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Ensure a stats row exists (for postbacks / clicks later).
     */
    public function ensureStats(): OfferStat
    {
        return $this->stats()->firstOrCreate(
            ['offer_id' => $this->id],
            [
                'clicks_geo_count' => 0,
                'leads_count' => 0,
                'deposits_count' => 0,
                'is_protected' => false,
            ],
        );
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isArchiving(): bool
    {
        return $this->status === 'archiving';
    }

    public function isPurging(): bool
    {
        return $this->status === 'purging';
    }

    /**
     * @return list<string>
     */
    public function phoneCountriesList(): array
    {
        $raw = $this->phone_countries ?: $this->phone;

        if (! $raw) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $code) => strtolower(trim($code)),
            explode(',', strtolower((string) $raw)),
        ), static fn (string $code) => strlen($code) === 2 && ctype_alpha($code))));
    }

    /**
     * DNS propagation state for the offers table.
     * na | skipped | waiting | pending | ready
     */
    public function dnsStatus(): string
    {
        $meta = is_array($this->infra_meta) ? $this->infra_meta : [];
        $dns = $meta['dns'] ?? null;

        // Registrar NS cutover (CF slot switch): keep the orange wait until
        // public NS actually match. dns_via=records means they already do —
        // leftover Dynadot API errors must not keep the row orange forever.
        if (($meta['dynadot_ns'] ?? '') === 'pending' && $this->provision_infrastructure) {
            if (($meta['dns_via'] ?? '') !== 'records') {
                return 'pending';
            }
        }

        if ($dns === 'done') {
            // Stale "dns=done" after a CF switch that deleted the old zone
            // before registrar NS moved — recheck must retry set_ns.
            if ($this->provision_infrastructure
                && $this->availability_status === 'down'
                && is_string($this->availability_error)
                && (
                    str_starts_with($this->availability_error, 'No A record')
                    || str_starts_with($this->availability_error, 'NXDOMAIN')
                )
            ) {
                return 'pending';
            }

            return 'ready';
        }

        if (! $this->provision_infrastructure) {
            return 'na';
        }

        $options = InfrastructureOptions::forOffer($this);

        if (! InfrastructureOptions::needsDnsWait($options)) {
            return 'skipped';
        }

        if ($dns === 'pending' || $this->infra_status === 'ready') {
            return 'pending';
        }

        return 'waiting';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanelArray(): array
    {
        $templateLabel = app(TemplateCatalog::class)->label($this->template);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'user_email' => $this->user?->email,
            'folder' => $this->folder,
            'brand' => $this->brand,
            'domain' => $this->domain,
            'geo' => $this->geo,
            'lang' => $this->lang,
            'phone' => strtolower((string) ($this->phone ?? '')),
            'phone_countries' => $this->phoneCountriesList(),
            'min_deposit' => $this->min_deposit ?: '250',
            'currency' => strtoupper((string) ($this->currency ?: 'EUR')),
            'template' => $templateLabel,
            'template_id' => $this->template,
            'vitals_enabled' => (bool) $this->vitals_enabled,
            'status' => $this->status,
            'deploy_panel' => $this->deploy_panel_name,
            'cloudflare_account' => $this->cloudflare_account_name,
            'cloudflare_slot' => $this->cloudflareSlotForPanel(),
            ...$this->ownerCloudflarePanelFields(),
            'dynadot_account' => $this->dynadot_account_name,
            'deployed_at' => $this->deployed_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'deploy_error' => $this->deploy_error,
            'archived_at' => $this->archived_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'teardown_meta' => $this->teardown_meta ?? [],
            'submitted_for_indexing' => $this->submitted_for_indexing,
            'indexed_at' => $this->indexed_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'google_index_status' => (string) ($this->google_index_status ?? ''),
            'google_index_miss' => OfferGoogleIndexInspector::isMiss($this),
            'google_indexed' => (bool) $this->google_indexed_at,
            'google_indexed_at' => $this->google_indexed_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'google_index_checked_at' => $this->google_index_checked_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'google_index_coverage' => $this->google_index_coverage,
            'google_index_canonical' => $this->google_index_canonical,
            'date' => $this->created_at?->format('Y-m-d'),
            'provision_infrastructure' => (bool) $this->provision_infrastructure,
            'infra_status' => $this->infra_status,
            'infra_error' => $this->infra_status === 'failed' ? $this->infra_error : null,
            'dns_status' => $this->dnsStatus(),
            'dns_error' => is_array($this->infra_meta) ? ($this->infra_meta['dns_error'] ?? null) : null,
            'gsc_status' => is_array($this->infra_meta)
                ? (string) (($this->infra_meta['gsc']['status'] ?? '') ?: '')
                : '',
            'gsc_error' => is_array($this->infra_meta) ? ($this->infra_meta['gsc_error'] ?? null) : null,
            'gsc_ready' => $this->gscReadyForPanel(),
            'availability_status' => in_array($this->availability_status, ['ok', 'down'], true)
                ? $this->availability_status
                : 'unchecked',
            'availability_checked_at' => $this->availability_checked_at
                ?->timezone('Europe/Kyiv')
                ->format('Y-m-d H:i'),
            'availability_error' => $this->availability_error,
            'infra_meta' => $this->infra_meta ?? [],
        ];
    }

    private function gscReadyForPanel(): bool
    {
        if (! $this->provision_infrastructure || $this->status !== 'deployed') {
            return false;
        }

        $meta = is_array($this->infra_meta) ? $this->infra_meta : [];
        if (($meta['dns'] ?? null) !== 'done') {
            return false;
        }

        $this->loadMissing('user.settings');
        $settings = $this->user?->settings;
        if (! $settings) {
            return false;
        }

        return $settings->hasGoogleOAuth()
            && filled($settings->gsc_verification_filename)
            && filled(config('services.google.client_id'));
    }

    /**
     * Owner Cloudflare slot labels for bulk "CF акаунт" (no secrets).
     *
     * @return array{
     *     owner_cf_primary_name: string,
     *     owner_has_cf_primary: bool
     * }
     */
    private function ownerCloudflarePanelFields(): array
    {
        $this->loadMissing('user.settings');
        $settings = $this->user?->settings;

        $primary = trim((string) ($settings?->cloudflare_account_name ?? ''));

        return [
            'owner_cf_primary_name' => $primary !== '' ? $primary : 'Основний CF',
            'owner_has_cf_primary' => (bool) $settings?->hasCloudflareSlot('primary'),
        ];
    }

    private function cloudflareSlotForPanel(): string
    {
        $slot = (string) (is_array($this->infra_meta) ? ($this->infra_meta['cloudflare_slot'] ?? '') : '');

        return in_array($slot, ['backup', 'spare'], true) ? $slot : 'primary';
    }
}
