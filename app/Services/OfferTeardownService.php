<?php

namespace App\Services;

use App\Jobs\ArchiveOfferJob;
use App\Models\CloudflareAccount;
use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfferTeardownService
{
    public function __construct(
        private readonly CloudflareClient $cloudflare,
        private readonly GoogleSearchConsoleClient $gsc,
        private readonly OfferVerificationFileService $verificationFiles,
        private readonly OriginHostService $origin,
        private readonly DeployService $deploy,
        private readonly OriginPool $pool,
        private readonly DynadotClient $dynadot,
        private readonly string $offersPath,
    ) {}

    public function enqueueArchive(Offer $offer, User $initiatedBy): void
    {
        $this->enqueueTeardown($offer, $initiatedBy, purge: false);
    }

    public function enqueuePurge(Offer $offer, User $initiatedBy): void
    {
        $this->enqueueTeardown($offer, $initiatedBy, purge: true);
    }

    private function enqueueTeardown(Offer $offer, User $initiatedBy, bool $purge): void
    {
        if (in_array($offer->status, ['archiving', 'purging', 'archived'], true)) {
            throw new RuntimeException($purge
                ? 'Офер вже видаляється або в архіві.'
                : 'Оффер вже архівується або в архіві.');
        }

        if ($offer->status === 'deploying') {
            throw new RuntimeException($purge
                ? 'Зачекайте завершення деплою перед видаленням.'
                : 'Зачекайте завершення деплою перед архівацією.');
        }

        $offer->update([
            'status' => $purge ? 'purging' : 'archiving',
            'archived_at' => null,
            'archived_by' => $initiatedBy->id,
            'teardown_meta' => [
                'started_at' => now()->toIso8601String(),
                'steps' => [],
                'purge' => $purge,
            ],
        ]);

        // User-initiated teardown must not sit behind the DNS-recheck backlog.
        ArchiveOfferJob::dispatch($offer->id)->onQueue('deploy');
    }

    public function run(Offer $offer): void
    {
        $offer->refresh();
        $offer->loadMissing('user.settings');

        $domain = strtolower(trim($offer->domain));
        $meta = is_array($offer->teardown_meta) ? $offer->teardown_meta : [];
        $steps = is_array($meta['steps'] ?? null) ? $meta['steps'] : [];
        $errors = [];

        $ownerSettings = $offer->user?->settings;

        // Drop from Search Console while Google OAuth still works — soft-fail.
        $steps['gsc'] = $this->removeFromSearchConsole($offer, $ownerSettings);

        // Wipe the lander from every origin we can SSH to — current host, the
        // previous host after a move, and leftovers on other pool boxes. Archive
        // used to delete only the bound IP, so the old VPS kept serving 200s and
        // the hoster still got the complaint.
        $steps['origin'] = $this->removeOriginFiles($offer, $ownerSettings, $domain, $errors);

        $cloudflareOk = false;
        $cloudflareHardErrors = [];

        foreach ($this->cloudflareCandidates($offer) as $label => $settings) {
            try {
                $outcome = $this->removeCloudflareZone($settings, $offer);
                $steps['cloudflare_'.$label] = $outcome;

                if (in_array($outcome, ['deleted', 'already_gone', 'skipped_not_found'], true)) {
                    $cloudflareOk = true;
                    $this->forgetCloudflareZoneId($offer);
                }
            } catch (\Throwable $e) {
                if ($this->cloudflare->isUnauthorizedError($e)) {
                    $steps['cloudflare_'.$label] = 'skipped_unauthorized';
                    Log::warning('Offer teardown Cloudflare unauthorized (ignored if zone already gone)', [
                        'offer_id' => $offer->id,
                        'domain' => $domain,
                        'host' => $label,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $steps['cloudflare_'.$label] = 'error: '.$e->getMessage();
                $cloudflareHardErrors[] = 'Cloudflare ('.$label.'): '.$e->getMessage();
                Log::warning('Offer teardown Cloudflare failed', [
                    'offer_id' => $offer->id,
                    'domain' => $domain,
                    'host' => $label,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Zone deleted / already missing for at least one account → do not fail on admin 403 etc.
        if (! $cloudflareOk) {
            foreach ($cloudflareHardErrors as $error) {
                $errors[] = $error;
            }
        }

        try {
            $this->verificationFiles->delete($offer);
            $steps['verification'] = 'deleted';
        } catch (\Throwable $e) {
            $steps['verification'] = 'error: '.$e->getMessage();
            $errors[] = 'Verification: '.$e->getMessage();
        }

        try {
            $localPath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$offer->folder;
            if (File::isDirectory($localPath)) {
                File::deleteDirectory($localPath);
            }
            $steps['local_folder'] = 'deleted';
        } catch (\Throwable $e) {
            $steps['local_folder'] = 'error: '.$e->getMessage();
            $errors[] = 'Local: '.$e->getMessage();
        }

        try {
            $parked = $this->parkDynadotNameservers($offer);
            $steps['dynadot'] = $parked['status'];
            if ($parked['detail'] !== '') {
                $meta['dynadot_ns'] = $parked['detail'];
            }
        } catch (\Throwable $e) {
            $steps['dynadot'] = 'error: '.$e->getMessage();
            $errors[] = 'Dynadot NS: '.$e->getMessage();
            Log::warning('Offer teardown Dynadot park NS failed', [
                'offer_id' => $offer->id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }

        $meta['steps'] = $steps;
        $meta['finished_at'] = now()->toIso8601String();
        $meta['dynadot'] = $steps['dynadot'] ?? 'kept';
        unset($meta['errors']);

        $purge = (bool) ($meta['purge'] ?? false) || $offer->status === 'purging';

        if ($purge) {
            $offer->delete();

            return;
        }

        if ($errors !== []) {
            $meta['errors'] = $errors;
            $offer->update([
                'status' => 'teardown_failed',
                'teardown_meta' => $meta,
            ]);

            return;
        }

        $infraMeta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        unset(
            $infraMeta['gsc'],
            $infraMeta['gsc_error'],
            $infraMeta['deploy_host'],
            $infraMeta['migrated_from'],
            $infraMeta['origin'],
            $infraMeta['cloudflare'],
            $infraMeta['cloudflare_zone_id'],
            $infraMeta['cloudflare_dns'],
            $infraMeta['cloudflare_www_dns'],
            $infraMeta['cloudflare_edge'],
            $infraMeta['cloudflare_slot'],
            $infraMeta['cloudflare_old_zone_delete'],
            $infraMeta['nameservers'],
            $infraMeta['dns'],
            $infraMeta['dns_via'],
            $infraMeta['ssl'],
            $infraMeta['ssl_force'],
            $infraMeta['ssl_hsts'],
            $infraMeta['www_redirect'],
        );

        $offer->update([
            'status' => 'archived',
            'archived_at' => now(),
            'remote_path' => null,
            'deploy_error' => null,
            'deploy_panel_name' => null,
            'infra_status' => null,
            'infra_error' => null,
            'infra_meta' => $infraMeta,
            'submitted_for_indexing' => false,
            'indexed_at' => null,
            'google_index_status' => null,
            'google_indexed_at' => null,
            'google_index_checked_at' => null,
            'google_index_coverage' => null,
            'google_index_canonical' => null,
            'teardown_meta' => $meta,
        ]);
    }

    /**
     * Remove /var/www/offers/{domain} from every origin that may still have it.
     *
     * @param  list<string>  $errors
     */
    private function removeOriginFiles(Offer $offer, ?UserSetting $ownerSettings, string $domain, array &$errors): string
    {
        $byHost = $this->originHostsForTeardown($offer, $ownerSettings);

        if ($byHost === []) {
            if ($this->pool->boundHost($offer) === '') {
                return 'skipped_no_host';
            }

            $errors[] = 'Origin: немає SSH до привʼязаного сервера офера';

            return 'error: no_ssh_targets';
        }

        $results = [];
        $anyDeleted = false;
        $bound = strtolower(trim($this->pool->boundHost($offer)));

        foreach ($byHost as $host => $settings) {
            try {
                $this->origin->deleteWebRoot($settings, $domain);
                $results[] = $host.':deleted';
                $anyDeleted = true;
            } catch (\Throwable $e) {
                $results[] = $host.':error:'.$e->getMessage();
                Log::warning('Offer teardown origin failed', [
                    'offer_id' => $offer->id,
                    'domain' => $domain,
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $anyDeleted) {
            $msg = implode('; ', $results);
            $errors[] = 'Origin: '.$msg;

            return 'error: '.$msg;
        }

        if ($bound !== '' && ! in_array($bound.':deleted', $results, true)) {
            $msg = implode('; ', $results);
            $errors[] = 'Origin: не знято з поточного хоста '.$bound.' ('.$msg.')';

            return 'error: '.$msg;
        }

        return implode('; ', $results);
    }

    /**
     * @return array<string, UserSetting>
     */
    private function originHostsForTeardown(Offer $offer, ?UserSetting $ownerSettings): array
    {
        /** @var array<string, UserSetting> $byHost */
        $byHost = [];
        $base = $ownerSettings ?? new UserSetting;

        $push = function (string $host, UserSetting $settings) use (&$byHost): void {
            $host = strtolower(trim($host));
            if ($host === '' || isset($byHost[$host])) {
                return;
            }
            $byHost[$host] = $settings;
        };

        if ($offer->user && $ownerSettings) {
            try {
                $resolved = $this->deploy->resolveDeploySettings($offer->user, $offer, allocate: false);
                $push((string) $resolved->deploy_host, $resolved);
            } catch (\Throwable $e) {
                Log::warning('Offer teardown could not resolve offer origin SSH', [
                    'offer_id' => $offer->id,
                    'domain' => $offer->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($ownerSettings) {
            $push((string) $ownerSettings->deploy_host, $ownerSettings);
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $previous = strtolower(trim((string) ($meta['migrated_from'] ?? '')));
        if ($previous !== '') {
            $previousServer = $this->pool->serverForHost($previous);
            if ($previousServer?->hasSshCredentials()) {
                $push($previous, $this->pool->applyTo($base, $previousServer));
            }
        }

        foreach (OriginServer::query()->orderBy('id')->get() as $server) {
            if (! $server->hasSshCredentials()) {
                continue;
            }
            $push((string) $server->host, $this->pool->applyTo($base, $server));
        }

        return $byHost;
    }

    private function removeFromSearchConsole(Offer $offer, ?UserSetting $settings): string
    {
        if (! $settings || ! $settings->hasGoogleOAuth()) {
            return 'skipped_no_google';
        }

        if (! filled(config('services.google.client_id'))) {
            return 'skipped_no_oauth_config';
        }

        $wasSubmitted = (bool) $offer->submitted_for_indexing;
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gscStatus = (string) (($meta['gsc']['status'] ?? null) ?: '');
        if (! $wasSubmitted && $gscStatus === '') {
            return 'skipped_not_submitted';
        }

        try {
            return $this->gsc->removeOffer($offer, $settings);
        } catch (\Throwable $e) {
            Log::warning('Offer teardown GSC failed', [
                'offer_id' => $offer->id,
                'domain' => $offer->domain,
                'error' => $e->getMessage(),
            ]);

            return 'error: '.$e->getMessage();
        }
    }

    /**
     * @return array<string, UserSetting>
     */
    private function cloudflareCandidates(Offer $offer): array
    {
        $candidates = [];
        $owner = $offer->user?->settings;

        $push = function (string $label, ?UserSetting $base, ?string $token, ?string $accountId = null, ?string $accountName = null) use (&$candidates): void {
            $token = CloudflareClient::normalizeApiToken($token);
            if ($token === '' || $base === null) {
                return;
            }

            // Deduplicate by token fingerprint so primary/backup/offer do not triple-delete.
            foreach ($candidates as $existing) {
                if (CloudflareClient::normalizeApiToken($existing->cloudflare_api_token) === $token) {
                    return;
                }
            }

            $probe = $base->replicate();
            $probe->cloudflare_api_token = $token;
            if ($accountId !== null && $accountId !== '') {
                $probe->cloudflare_account_id = $accountId;
            }
            if ($accountName !== null && $accountName !== '') {
                $probe->cloudflare_account_name = $accountName;
            }
            $candidates[$label] = $probe;
        };

        // 1) Token stored on the offer (last successful CF account after switch/migrate).
        if ($owner) {
            $push(
                'offer',
                $owner,
                $offer->cloudflare_api_token,
                $offer->cloudflare_account_id,
                $offer->cloudflare_account_name,
            );
        }

        // 2) Owner primary + backup slots from Settings.
        if ($owner) {
            $push('owner', $owner, $owner->cloudflare_api_token, $owner->cloudflare_account_id, $owner->cloudflare_account_name);
            $push(
                'owner_backup',
                $owner,
                $owner->cloudflare_backup_api_token,
                $owner->cloudflare_backup_account_id,
                $owner->cloudflare_backup_account_name,
            );
        }

        // 3) Admin primary + backup (shared / fallback accounts).
        $admin = $this->adminSettings();
        if ($admin) {
            $push('admin', $admin, $admin->cloudflare_api_token, $admin->cloudflare_account_id, $admin->cloudflare_account_name);
            $push(
                'admin_backup',
                $admin,
                $admin->cloudflare_backup_api_token,
                $admin->cloudflare_backup_account_id,
                $admin->cloudflare_backup_account_name,
            );
        }

        $base = $owner ?? $admin;
        if ($base) {
            foreach (CloudflareAccount::query()->orderBy('id')->get() as $account) {
                $push(
                    'pool_'.$account->id,
                    $base,
                    $account->api_token,
                    $account->account_id,
                    $account->name,
                );
            }
        }

        return $candidates;
    }

    private function adminSettings(): ?UserSetting
    {
        $admin = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id')
            ->with('settings')
            ->first();

        return $admin?->settings;
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function parkDynadotNameservers(Offer $offer): array
    {
        if (DynadotClient::parkingDropsFromTld((string) $offer->domain)) {
            return [
                'status' => 'skipped_tld',
                'detail' => 'not parking .online — CentralNic NXDOMAIN after Dynadot parking blocks restore',
            ];
        }

        $settings = $this->dynadotSettingsFor($offer);
        if (! $settings) {
            return ['status' => 'kept_no_key', 'detail' => ''];
        }

        try {
            $this->dynadot->parkNameservers($settings, $offer->domain);
        } catch (\Throwable $e) {
            if (DynadotClient::isUnmanageableDomainError($e->getMessage())) {
                return [
                    'status' => 'skipped_disabled',
                    'detail' => $e->getMessage(),
                ];
            }

            throw $e;
        }

        return [
            'status' => 'parked',
            'detail' => implode(',', DynadotClient::PARKING_NAMESERVERS),
        ];
    }

    private function dynadotSettingsFor(Offer $offer): ?UserSetting
    {
        $owner = $offer->user?->settings;
        if ($owner) {
            if (filled($offer->dynadot_api_key)) {
                $owner->dynadot_api_key = $offer->dynadot_api_key;
            }
            if (filled($owner->dynadot_api_key)) {
                return $owner;
            }
        }

        $admin = $this->adminSettings();

        return ($admin && filled($admin->dynadot_api_key)) ? $admin : null;
    }

    /**
     * @return 'deleted'|'already_gone'|'skipped_not_found'
     */
    private function removeCloudflareZone(UserSetting $settings, Offer $offer): string
    {
        $domain = strtolower(trim($offer->domain));
        $storedZoneId = $this->storedCloudflareZoneId($offer);
        $hadStoredId = $storedZoneId !== '';

        if ($storedZoneId !== '') {
            try {
                $result = $this->cloudflare->deleteZone($settings, $storedZoneId);
                if ($result === 'deleted') {
                    return 'deleted';
                }
            } catch (\Throwable $e) {
                if (! $this->cloudflare->isUnauthorizedError($e) && ! $this->cloudflare->isZoneAbsentError($e)) {
                    throw $e;
                }
                // Stale ID or token cannot see this zone → resolve by domain for this account.
            }
        }

        $found = $this->cloudflare->findZone($settings, $domain);
        $foundZoneId = trim((string) ($found['zone_id'] ?? ''));

        if ($foundZoneId === '') {
            return $hadStoredId ? 'already_gone' : 'skipped_not_found';
        }

        $result = $this->cloudflare->deleteZone($settings, $foundZoneId);

        return $result === 'deleted' ? 'deleted' : 'already_gone';
    }

    private function storedCloudflareZoneId(Offer $offer): string
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        return trim((string) ($meta['cloudflare_zone_id'] ?? ''));
    }

    private function forgetCloudflareZoneId(Offer $offer): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        if (! array_key_exists('cloudflare_zone_id', $meta)) {
            return;
        }

        unset($meta['cloudflare_zone_id']);
        $offer->infra_meta = $meta;
        $offer->save();
    }

}
