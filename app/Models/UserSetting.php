<?php

namespace App\Models;

use App\Services\CloudflareClient;
use App\Support\DeployDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'affiliate_tag',
        'crm_api_key',
        'tg_bot_token',
        'tg_chat_id',
        'tg_group_chat_id',
        'mirror_probe_token',
        'sales_postback_token',
        'geo_click_token',
        'deploy_driver',
        'deploy_panel_name',
        'deploy_host',
        'deploy_port',
        'deploy_username',
        'deploy_password',
        'deploy_path_template',
        'origin_health',
        'origin_health_alerts',
        'dynadot_api_key',
        'dynadot_api_secret',
        'dynadot_contact_id',
        'dynadot_sandbox',
        'dynadot_default_years',
        'dynadot_account_name',
        'cloudflare_api_token',
        'cloudflare_account_id',
        'cloudflare_default_proxied',
        'cloudflare_account_name',
        'cloudflare_backup_api_token',
        'cloudflare_backup_account_id',
        'cloudflare_backup_account_name',
        'gsc_verification_filename',
        'google_oauth_refresh_token',
        'google_oauth_email',
        'google_oauth_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'crm_api_key' => 'encrypted',
            'tg_bot_token' => 'encrypted',
            'deploy_password' => 'encrypted',
            'dynadot_api_key' => 'encrypted',
            'dynadot_api_secret' => 'encrypted',
            'cloudflare_api_token' => 'encrypted',
            'cloudflare_backup_api_token' => 'encrypted',
            'google_oauth_refresh_token' => 'encrypted',
            'deploy_port' => 'integer',
            'dynadot_sandbox' => 'boolean',
            'dynadot_default_years' => 'integer',
            'cloudflare_default_proxied' => 'boolean',
            'origin_health_alerts' => 'boolean',
            'origin_health' => 'array',
            'google_oauth_connected_at' => 'datetime',
        ];
    }

    public function hasGoogleOAuth(): bool
    {
        return filled($this->google_oauth_refresh_token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function googleAccounts(): HasMany
    {
        return $this->hasMany(GoogleAccount::class, 'user_id', 'user_id')
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function primaryGoogleAccount(): ?GoogleAccount
    {
        if ($this->relationLoaded('googleAccounts')) {
            return $this->googleAccounts->firstWhere('is_primary', true)
                ?? $this->googleAccounts->first();
        }

        return $this->googleAccounts()->where('is_primary', true)->first()
            ?? $this->googleAccounts()->first();
    }

    /**
     * @return Collection<int, GoogleAccount>
     */
    public function googleAccountsForInspect(): Collection
    {
        $this->loadMissing('googleAccounts');

        return $this->googleAccounts
            ->filter(fn (GoogleAccount $account) => filled($account->refresh_token))
            ->values();
    }

    public function syncPrimaryGoogleMirror(): void
    {
        unset($this->relations['googleAccounts']);

        $primary = $this->primaryGoogleAccount();

        $this->forceFill([
            'google_oauth_refresh_token' => $primary?->refresh_token,
            'google_oauth_email' => $primary?->email,
            'google_oauth_connected_at' => $primary?->connected_at,
        ])->save();
    }

    /**
     * @return list<array{id: int, email: string, is_primary: bool, connected_at: ?string}>
     */
    public function googleAccountsForPanel(): array
    {
        $this->loadMissing('googleAccounts');

        return $this->googleAccounts
            ->map(fn (GoogleAccount $account) => $account->toPanelArray())
            ->values()
            ->all();
    }

    public function isUbuntuDriver(): bool
    {
        return DeployDriver::isUbuntu($this->deploy_driver);
    }

    public function hasOriginCredentials(): bool
    {
        return filled($this->deploy_host)
            && filled($this->deploy_username)
            && filled($this->deploy_password);
    }

    /**
     * @return array<string, mixed>
     */
    public function originHealthForPanel(): array
    {
        $health = is_array($this->origin_health) ? $this->origin_health : [];

        return [
            'status' => (string) ($health['status'] ?? 'unchecked'),
            'checked_at' => $health['checked_at'] ?? null,
            'fail_streak' => (int) ($health['fail_streak'] ?? 0),
            'message' => $health['message'] ?? null,
            'metrics' => is_array($health['metrics'] ?? null) ? $health['metrics'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanelArray(): array
    {
        return [
            'affiliate_tag' => $this->affiliate_tag ?? 'BRO',
            'tg_chat_id' => $this->tg_chat_id ?? '',
            'tg_group_chat_id' => $this->tg_group_chat_id ?? '',
            'sales_postback_token' => $this->sales_postback_token ?? '',
            'has_crm_api_key' => filled($this->crm_api_key),
            'has_tg_bot_token' => filled($this->tg_bot_token),
            'has_dynadot_api_key' => filled($this->dynadot_api_key),
            'has_dynadot_contact_id' => filled($this->dynadot_contact_id),
            'dynadot_contact_id' => $this->dynadot_contact_id ?? '',
            'dynadot_account_name' => $this->dynadot_account_name ?? '',
            'has_cloudflare_api_token' => filled($this->cloudflare_api_token),
            'cloudflare_account_id' => $this->cloudflare_account_id ?? '',
            'cloudflare_default_proxied' => (bool) ($this->cloudflare_default_proxied ?? true),
            'cloudflare_account_name' => $this->cloudflare_account_name ?? '',
            'has_cloudflare_backup_api_token' => filled($this->cloudflare_backup_api_token),
            'cloudflare_backup_account_id' => $this->cloudflare_backup_account_id ?? '',
            'cloudflare_backup_account_name' => $this->cloudflare_backup_account_name ?? '',
            'gsc_verification_filename' => $this->gsc_verification_filename ?? '',
            'has_gsc_verification_file' => filled($this->gsc_verification_filename),
            'google_oauth_connected' => $this->hasGoogleOAuth(),
            'google_oauth_email' => $this->google_oauth_email ?? '',
            'google_oauth_connected_at' => $this->google_oauth_connected_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
            'google_accounts' => $this->googleAccountsForPanel(),
            'google_oauth_configured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
        ];
    }

    /**
     * Snapshot of Cloudflare/Dynadot credentials + labels at offer creation time.
     * Names are shown in the offers table; secrets stay encrypted and out of the UI.
     *
     * @return array{
     *     cloudflare_account_name: ?string,
     *     dynadot_account_name: ?string,
     *     cloudflare_api_token: ?string,
     *     cloudflare_account_id: ?string,
     *     dynadot_api_key: ?string,
     *     dynadot_contact_id: ?string,
     *     dynadot_sandbox: ?bool
     * }
     */
    public function providerSnapshotForOffer(): array
    {
        $cfName = trim((string) ($this->cloudflare_account_name ?? ''));
        $dynName = trim((string) ($this->dynadot_account_name ?? ''));
        $cfToken = CloudflareClient::normalizeApiToken($this->cloudflare_api_token);
        $cfAccountId = trim((string) ($this->cloudflare_account_id ?? ''));
        $dynKey = trim((string) ($this->dynadot_api_key ?? ''));
        $dynContact = trim((string) ($this->dynadot_contact_id ?? ''));

        return [
            'cloudflare_account_name' => $cfName !== '' ? $cfName : null,
            'dynadot_account_name' => $dynName !== '' ? $dynName : null,
            'cloudflare_api_token' => $cfToken !== '' ? $cfToken : null,
            'cloudflare_account_id' => $cfAccountId !== '' ? $cfAccountId : null,
            'dynadot_api_key' => $dynKey !== '' ? $dynKey : null,
            'dynadot_contact_id' => $dynContact !== '' ? $dynContact : null,
            'dynadot_sandbox' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toEditArray(): array
    {
        return array_merge($this->toPanelArray(), [
            'crm_api_key' => $this->crm_api_key ?? '',
            'tg_bot_token' => $this->tg_bot_token ?? '',
            'dynadot_api_key' => $this->dynadot_api_key ?? '',
            'dynadot_api_secret' => $this->dynadot_api_secret ?? '',
            'cloudflare_api_token' => $this->cloudflare_api_token ?? '',
            'cloudflare_backup_api_token' => $this->cloudflare_backup_api_token ?? '',
        ]);
    }

    /**
     * @return array{token: string, account_id: string, name: string}
     */
    public function cloudflareSlotCredentials(string $slot): array
    {
        $slot = $slot === 'backup' ? 'backup' : 'primary';

        if ($slot === 'backup') {
            return [
                'token' => CloudflareClient::normalizeApiToken($this->cloudflare_backup_api_token),
                'account_id' => trim((string) ($this->cloudflare_backup_account_id ?? '')),
                'name' => trim((string) ($this->cloudflare_backup_account_name ?? '')),
            ];
        }

        return [
            'token' => CloudflareClient::normalizeApiToken($this->cloudflare_api_token),
            'account_id' => trim((string) ($this->cloudflare_account_id ?? '')),
            'name' => trim((string) ($this->cloudflare_account_name ?? '')),
        ];
    }

    public function hasCloudflareSlot(string $slot): bool
    {
        $creds = $this->cloudflareSlotCredentials($slot);

        return $creds['token'] !== '' && $creds['account_id'] !== '';
    }
}
