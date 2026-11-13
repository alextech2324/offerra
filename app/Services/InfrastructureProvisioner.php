<?php

namespace App\Services;

use App\Jobs\ProvisionInfrastructureJob;
use App\Models\CloudflareAccount;
use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\CloudflareZoneCutover;
use App\Support\InfrastructureDnsReadiness;
use App\Support\InfrastructureOptions;
use App\Support\PublicDnsLookup;
use Illuminate\Support\Facades\Http;

class InfrastructureProvisioner
{
    public function __construct(
        private readonly CloudflareClient $cloudflare,
        private readonly DynadotClient $dynadot,
        private readonly OriginHostService $origin,
        private readonly OriginPool $pool,
        private readonly PublicDnsLookup $publicDns,
    ) {}

    /**
     * SSH is no longer part of user settings — the origin comes from the admin
     * pool, so here we only check the provider credentials the user still owns.
     */
    public static function settingsReady(?UserSetting $settings): bool
    {
        if ($settings === null) {
            return false;
        }

        return filled($settings->cloudflare_api_token)
            && filled($settings->cloudflare_account_id)
            && filled($settings->dynadot_api_key)
            && app(OriginPool::class)->hasCapacity();
    }

    public function enqueue(Offer $offer): void
    {
        if (! $offer->provision_infrastructure) {
            return;
        }

        if (in_array($offer->infra_status, ['provisioning', 'ready'], true)) {
            return;
        }

        $offer->update([
            'infra_status' => 'pending',
            'infra_error' => null,
        ]);

        // Provisioning is user-critical and must not be starved behind the
        // background DNS-recheck backlog on the "default" queue. Route it to the
        // "deploy" queue, which has dedicated workers and leads into auto-deploy.
        ProvisionInfrastructureJob::dispatch($offer->id)->onQueue('deploy');
    }

    public function provision(Offer $offer): void
    {
        $offer->loadMissing('user.settings');
        $settings = $this->providerSettings($offer);
        $options = InfrastructureOptions::forOffer($offer);

        if (! $settings || ! self::settingsReady($settings)) {
            $offer->update([
                'infra_status' => 'failed',
                'infra_error' => 'Заповніть Cloudflare і Dynadot у налаштуваннях, і переконайтесь, що в пулі є активний origin-сервер.',
            ]);

            return;
        }

        try {
            // Binds the offer to its pool server (or keeps the existing one) and
            // hands every origin step the matching SSH credentials.
            $settings = $this->pool->settingsForOffer($settings, $offer);
        } catch (\Throwable $e) {
            $offer->update([
                'infra_status' => 'failed',
                'infra_error' => DynadotClient::redactSecrets($e->getMessage()),
            ]);

            return;
        }

        if (! InfrastructureOptions::anyEnabled($options)) {
            $offer->update([
                'infra_status' => null,
                'infra_error' => null,
            ]);

            return;
        }

        $domain = strtolower(trim($offer->domain));
        $meta = array_merge($offer->infra_meta ?? [], ['options' => $options]);

        $offer->update([
            'infra_status' => 'provisioning',
            'infra_error' => null,
        ]);

        try {
            $this->runSteps($settings, $domain, $options, $meta, $offer);

            $serverIp = $this->origin->originIpForOffer($offer, $settings);
            $expectedNs = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];
            $dnsVia = $this->publicReadiness($domain, $serverIp, $expectedNs);

            if (! InfrastructureOptions::needsDnsWait($options)) {
                $meta['dns'] = 'skipped';
                unset($meta['dns_error'], $meta['dns_via']);
            } elseif ($dnsVia !== null) {
                $meta['dns'] = 'done';
                $meta['dns_via'] = $dnsVia;
                unset($meta['dns_error']);
            } else {
                $meta['dns'] = 'pending';
                unset($meta['dns_error'], $meta['dns_via']);
            }

            $offer->update([
                'infra_status' => 'ready',
                'infra_error' => null,
                'infra_meta' => $meta,
            ]);

            if (($meta['dns'] ?? null) === 'done') {
                app(OfferGscSubmitter::class)->queueAfterDns($offer->fresh() ?? $offer);
            }
        } catch (\Throwable $e) {
            $offer->update([
                'infra_status' => 'failed',
                'infra_error' => DynadotClient::redactSecrets($e->getMessage()),
                'infra_meta' => $meta,
            ]);

            throw $e;
        }
    }

    /**
     * Site already answers HTTPS and registrar NS is not mid-cutover.
     * Stop the orange «Очікується DNS» and the every-minute recheck.
     */
    public function settleIfAlreadyLive(Offer $offer): bool
    {
        if ($offer->status !== 'deployed' || $offer->availability_status !== 'ok') {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['dynadot_ns'] ?? '') === 'pending' && ($meta['dns_via'] ?? '') !== 'records') {
            return false;
        }

        if (($meta['dns'] ?? null) === 'done' && $offer->dnsStatus() === 'ready') {
            return false;
        }

        $meta['dns'] = 'done';
        if (($meta['dns_via'] ?? '') === '') {
            $meta['dns_via'] = 'https';
        }
        unset($meta['dns_error']);
        $offer->update([
            'infra_status' => 'ready',
            'infra_error' => null,
            'infra_meta' => $meta,
        ]);

        app(OfferGscSubmitter::class)->queueAfterDns($offer->fresh() ?? $offer);

        return true;
    }

    public function recheckDns(Offer $offer): void
    {
        $offer->loadMissing('user.settings');
        $settings = $this->providerSettings($offer);

        if (! $settings) {
            return;
        }

        $options = InfrastructureOptions::forOffer($offer);
        $meta = array_merge($offer->infra_meta ?? [], ['options' => $options]);
        $domain = strtolower(trim($offer->domain));
        $serverIp = $this->origin->originIpForOffer($offer, $settings);

        // Спочатку лише перевірка DNS/HTTPS — без повторного проходження Cloudflare/Dynadot.
        // Інакше тимчасові таймаути API тримають статус «Очікується DNS», хоча сайт уже живий.
        if (! InfrastructureOptions::needsDnsWait($options)) {
            $meta['dns'] = $meta['dns'] ?? 'skipped';
            unset($meta['dns_error']);
            $offer->update([
                'infra_status' => 'ready',
                'infra_error' => null,
                'infra_meta' => $meta,
            ]);

            return;
        }

        $expectedNs = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];

        if ($this->markDnsDoneIfReady($offer, $meta, $domain, $serverIp, $expectedNs)) {
            return;
        }

        try {
            $this->runSteps($settings, $domain, $options, $meta, $offer);
            $expectedNs = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : $expectedNs;
        } catch (\Throwable $e) {
            $meta['dns_error'] = DynadotClient::redactSecrets($e->getMessage());

            // Якщо сайт уже живий — не блокуємо статус через збій повторного provision.
            if ($this->markDnsDoneIfReady($offer, $meta, $domain, $serverIp, $expectedNs)) {
                return;
            }

            $offer->update([
                'infra_status' => 'ready',
                'infra_error' => null,
                'infra_meta' => array_merge($meta, ['dns' => 'pending']),
            ]);

            return;
        }

        if ($this->markDnsDoneIfReady($offer, $meta, $domain, $serverIp, $expectedNs)) {
            return;
        }

        unset($meta['dns_via']);
        if ($this->publicDns->nameservers($domain) === [] && $this->publicDns->aRecords($domain) === []) {
            $meta['dns_error'] = 'Реєстр не публікує домен (NXDOMAIN). NS у Dynadot уже Cloudflare — часто server hold, API його не знімає.';
        } else {
            unset($meta['dns_error']);
        }
        $offer->update([
            'infra_status' => 'ready',
            'infra_error' => null,
            'infra_meta' => array_merge($meta, ['dns' => 'pending']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $expectedNs
     */
    private function markDnsDoneIfReady(
        Offer $offer,
        array $meta,
        string $domain,
        string $serverIp,
        array $expectedNs,
    ): bool {
        $via = $this->publicReadiness($domain, $serverIp, $expectedNs);

        if ($via === null) {
            return false;
        }

        $meta['dns'] = 'done';
        $meta['dns_via'] = $via;
        unset($meta['dns_error']);
        if ($via === 'records') {
            $meta['dynadot_ns'] = 'done';
            unset($meta['dynadot_ns_error']);
            $this->retirePreviousCloudflareZoneIfSafe($offer, $meta);
        }

        $offer->update([
            'infra_status' => 'ready',
            'infra_error' => null,
            'infra_meta' => $meta,
        ]);

        app(OfferGscSubmitter::class)->queueAfterDns($offer->fresh() ?? $offer);

        return true;
    }

    /**
     * Stage 1: public NS/A records. Stage 2: live HTTPS.
     * Either is enough — Google can crawl as soon as HTTPS answers.
     *
     * @param  list<string>  $expectedNs
     */
    private function publicReadiness(string $domain, string $serverIp, array $expectedNs): ?string
    {
        if ($this->dnsLooksReady($domain, $serverIp, $expectedNs)) {
            return 'records';
        }

        // HTTPS on the previous Cloudflare account must not finish a slot
        // switch — public NS still have to match the new zone.
        if ($expectedNs !== [] && ! InfrastructureDnsReadiness::nameserversAcceptable(
            $this->publicDns->nameservers($domain),
            $expectedNs,
        )) {
            return null;
        }

        if ($this->httpsLooksLive($domain)) {
            return 'https';
        }

        return null;
    }

    /**
     * @param  array<string, bool>  $options
     * @param  array<string, mixed>  $meta
     */
    private function runSteps(
        UserSetting $settings,
        string $domain,
        array $options,
        array &$meta,
        ?Offer $offer = null,
    ): void {
        // Ubuntu origin: always create /var/www/offers/{domain}/public_html before DNS/edge.
        if (($meta['origin'] ?? '') !== 'done') {
            $this->origin->ensureWebRoot($settings, $domain);
            $meta['origin'] = 'done';
        }

        $zoneId = (string) ($meta['cloudflare_zone_id'] ?? '');
        $nameservers = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];
        $zoneAlready = $zoneId !== '' && ($meta['cloudflare'] ?? '') === 'done';
        $dnsAlready = ($meta['cloudflare_dns'] ?? '') === 'done';
        $edgeAlready = ($meta['cloudflare_edge'] ?? '') === 'done'
            || (($meta['ssl'] ?? '') === 'done' && ($meta['ssl_force'] ?? '') === 'done');

        if (InfrastructureOptions::needsCloudflareZone($options)) {
            if ($options['cloudflare_zone'] ?? false) {
                if (! $zoneAlready) {
                    $zone = $this->cloudflare->ensureZone($settings, $domain);
                    $zoneId = $zone['zone_id'];
                    $nameservers = $zone['nameservers'];
                    $meta['cloudflare'] = 'done';
                    $meta['cloudflare_zone_id'] = $zoneId;
                    $meta['nameservers'] = $nameservers;
                } else {
                    $this->syncOwnedCloudflareZone($settings, $domain, $zoneId, $nameservers, $meta);
                }
            } elseif ($zoneId === '') {
                $zone = $this->cloudflare->findZone($settings, $domain);

                if ($zone !== null) {
                    $zoneId = $zone['zone_id'];
                    $nameservers = $zone['nameservers'];
                    $meta['cloudflare_zone_id'] = $zoneId;
                    $meta['nameservers'] = $nameservers;
                }
            }
        }

        $zoneId = (string) ($meta['cloudflare_zone_id'] ?? $zoneId);
        $nameservers = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : $nameservers;
        $dnsAlready = ($meta['cloudflare_dns'] ?? '') === 'done';
        $edgeAlready = ($meta['cloudflare_edge'] ?? '') === 'done'
            || (($meta['ssl'] ?? '') === 'done' && ($meta['ssl_force'] ?? '') === 'done');

        $serverIp = $offer !== null
            ? $this->origin->originIpForOffer($offer, $settings)
            : $this->originIpFromMetaOrSettings($settings, $meta);

        // Recheck runs every minute while registrar NS is pending. Rewriting the
        // zone (purge + A + edge) each time times out the default workers.
        if (($options['cloudflare_dns'] ?? false) && $zoneId !== '' && ! $dnsAlready) {
            $this->cloudflare->ensureRootARecord($settings, $zoneId, $domain, $serverIp);
            $meta['cloudflare_dns'] = 'done';
            $meta['cloudflare_www_dns'] = 'done';
            $meta['deploy_host'] = $serverIp;
        }

        if (InfrastructureOptions::needsCloudflareEdge($options) && $zoneId !== '' && ! $edgeAlready) {
            $this->applyCloudflareEdge($settings, $domain, $zoneId, $options, $meta);
        }

        if (($options['dynadot_ns'] ?? false) && $nameservers !== []) {
            $liveNs = $this->publicDns->nameservers($domain);
            // Restore recreates the CF zone on the same account. If public NS
            // already match, another set_ns is an empty EPP update that can
            // put CentralNic/Identity Digital into serverHold.
            if (CloudflareZoneCutover::registrarNsMatch($liveNs, $nameservers)) {
                $meta['dynadot_ns'] = 'done';
                unset($meta['dynadot_ns_error']);
                $needsNs = false;
            } else {
                $needsNs = ($meta['dynadot_ns'] ?? '') !== 'done'
                    || CloudflareZoneCutover::shouldRetrySetNs($liveNs, $nameservers);
            }

            if ($needsNs) {
                try {
                    $this->dynadot->setNameservers($settings, $domain, $nameservers);
                    $meta['dynadot_ns'] = 'done';
                    unset($meta['dynadot_ns_error']);
                } catch (\Throwable $e) {
                    if (! DynadotClient::isRetryableNsError($e->getMessage())) {
                        throw $e;
                    }

                    // Fresh regs, TLS blips, and account lock contention are
                    // recoverable — DNS recheck will retry set_ns.
                    $meta['dynadot_ns'] = 'pending';
                    $meta['dynadot_ns_error'] = DynadotClient::redactSecrets($e->getMessage());
                    $meta['dns'] = 'pending';
                }
            }
        }
    }

    /**
     * @param  array<string, bool>  $options
     * @param  array<string, mixed>  $meta
     */
    private function applyCloudflareEdge(
        UserSetting $settings,
        string $domain,
        string $zoneId,
        array $options,
        array &$meta,
    ): void {
        $this->cloudflare->configureEdgeSecurity($settings, $zoneId, $domain, $options);

        $meta['cloudflare_edge'] = 'done';

        if ($options['cloudflare_ssl'] ?? false) {
            $meta['ssl'] = 'done';
        }

        if ($options['cloudflare_https'] ?? false) {
            $meta['ssl_force'] = 'done';
        }

        if ($options['cloudflare_www_redirect'] ?? false) {
            $meta['www_redirect'] = 'done';
        }

        $meta['ssl_hsts'] = 'skipped';
    }

    /**
     * Fact-based DNS readiness: public NS must match Cloudflare (when known),
     * and A must point at the origin or a Cloudflare proxy IP.
     * Dynadot parking responses must never count as "ready".
     *
     * @param  list<string>  $expectedNameservers
     */
    private function dnsLooksReady(string $domain, string $serverIp, array $expectedNameservers = []): bool
    {
        $expectedNameservers = array_values(array_unique(array_filter(array_map(
            static fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')),
            $expectedNameservers,
        ))));

        if ($expectedNameservers !== []) {
            $liveNs = $this->publicDns->nameservers($domain);
            if (! InfrastructureDnsReadiness::nameserversAcceptable($liveNs, $expectedNameservers)) {
                return false;
            }
        }

        return $this->aRecordPointsToTarget($domain, $serverIp);
    }

    private function aRecordPointsToTarget(string $domain, string $serverIp): bool
    {
        $ips = $this->publicDns->aRecords($domain);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($ip === '' || $this->isLikelyParkingIp($ip)) {
                continue;
            }

            if ($ip === $serverIp || InfrastructureDnsReadiness::isCloudflareProxyIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isLikelyParkingIp(string $ip): bool
    {
        // Common Dynadot / Sedo / aftermarket parking anycast ranges we have seen.
        return str_starts_with($ip, '185.53.')
            || str_starts_with($ip, '207.244.')
            || str_starts_with($ip, '173.236.');
    }

    private function httpsLooksLive(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return $this->httpsHostResponds($domain) || $this->httpsHostResponds('www.'.$domain);
    }

    private function httpsHostResponds(string $host): bool
    {
        try {
            $response = Http::timeout(8)
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => false,
                ])
                ->get('https://'.$host.'/');

            $status = $response->status();
            $location = strtolower((string) $response->header('Location'));

            if (str_starts_with($location, 'http://')) {
                return false;
            }

            $self = 'https://'.$host;
            if ($status >= 300 && $status < 400 && rtrim($location, '/') === rtrim($self, '/')) {
                return false;
            }

            // 4xx (incl. Dynadot parking 410) must not count as live.
            return $status >= 200 && $status < 400;
        } catch (\Throwable) {
            return false;
        }
    }

    public function rebindARecord(Offer $offer, string $ip): void
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \RuntimeException('Невалідний IPv4 для A-запису.');
        }

        $offer->loadMissing('user.settings');
        $candidates = $this->cloudflareSettingsCandidates($offer);

        if ($candidates === []) {
            throw new \RuntimeException('Немає Cloudflare API token для '.$offer->domain);
        }

        $last = null;

        foreach ($candidates as $label => $settings) {
            try {
                $this->rebindARecordWith($offer, $settings, $ip, $label);

                return;
            } catch (\Throwable $e) {
                $last = $e;

                if (
                    $this->cloudflare->isUnauthorizedError($e)
                    || $this->cloudflare->isZoneAbsentError($e)
                ) {
                    continue;
                }

                throw $e;
            }
        }

        throw $last ?? new \RuntimeException('Немає Cloudflare API token для '.$offer->domain);
    }

    /**
     * @param  'offer'|'owner'|'owner_backup'|'admin'|'admin_backup'  $label
     */
    private function rebindARecordWith(Offer $offer, UserSetting $settings, string $ip, string $label): void
    {
        $domain = strtolower(trim((string) $offer->domain));
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $zoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));

        try {
            if ($zoneId === '') {
                $zone = $this->cloudflare->findZone($settings, $domain);

                if ($zone === null) {
                    throw new \RuntimeException('Cloudflare-зону не знайдено.');
                }

                $zoneId = (string) $zone['zone_id'];
            }

            $this->cloudflare->ensureRootARecord($settings, $zoneId, $domain, $ip);
        } catch (\Throwable $e) {
            $zone = $this->cloudflare->findZone($settings, $domain);

            if ($zone === null) {
                throw $e;
            }

            $zoneId = (string) $zone['zone_id'];
            $this->cloudflare->ensureRootARecord($settings, $zoneId, $domain, $ip);
        }

        $meta['deploy_host'] = $ip;
        $meta['cloudflare_zone_id'] = $zoneId;
        unset($meta['dns_error']);

        if (str_contains($label, 'backup')) {
            $meta['cloudflare_slot'] = 'backup';
        } elseif ($label === 'owner' || $label === 'admin') {
            $meta['cloudflare_slot'] = 'primary';
        }

        $offer->update([
            'infra_meta' => $meta,
            'deploy_panel_name' => $ip,
            'cloudflare_api_token' => $settings->cloudflare_api_token,
            'cloudflare_account_id' => $settings->cloudflare_account_id,
            'cloudflare_account_name' => $settings->cloudflare_account_name,
        ]);
    }

    /**
     * Tokens that may own this zone: offer → owner primary/backup → admin slots.
     *
     * @return array<string, UserSetting>
     */
    private function cloudflareSettingsCandidates(Offer $offer): array
    {
        $candidates = [];
        $owner = $offer->user?->settings;

        $push = function (string $label, ?UserSetting $base, ?string $token, ?string $accountId = null, ?string $accountName = null) use (&$candidates): void {
            $token = CloudflareClient::normalizeApiToken($token);
            if ($token === '' || $base === null) {
                return;
            }

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

        if ($owner) {
            $push(
                'offer',
                $owner,
                $offer->cloudflare_api_token,
                $offer->cloudflare_account_id,
                $offer->cloudflare_account_name,
            );
            $push('owner', $owner, $owner->cloudflare_api_token, $owner->cloudflare_account_id, $owner->cloudflare_account_name);
            $push(
                'owner_backup',
                $owner,
                $owner->cloudflare_backup_api_token,
                $owner->cloudflare_backup_account_id,
                $owner->cloudflare_backup_account_name,
            );

            // Older CF accounts stay on sibling offers after Settings tokens rotate.
            $sibling = 0;
            Offer::query()
                ->where('user_id', $offer->user_id)
                ->whereNotNull('cloudflare_api_token')
                ->where('cloudflare_api_token', '!=', '')
                ->orderByDesc('id')
                ->limit(400)
                ->get(['cloudflare_api_token', 'cloudflare_account_id', 'cloudflare_account_name'])
                ->each(function (Offer $row) use ($push, $owner, &$sibling): void {
                    $sibling++;
                    $push(
                        'sibling_'.$sibling,
                        $owner,
                        $row->cloudflare_api_token,
                        $row->cloudflare_account_id,
                        $row->cloudflare_account_name,
                    );
                });
        }

        $admin = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id')
            ->with('settings')
            ->first()?->settings;

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

    /**
     * @return array{creds: array{token: string, account_id: string, name: string}, slot: string, pool_id: ?int}
     */
    public function resolveCloudflareSwitchTarget(UserSetting $userSettings, string $target): array
    {
        $target = trim($target);

        if ($target === 'primary') {
            $creds = $userSettings->cloudflareSlotCredentials('primary');
            if ($creds['token'] === '' || $creds['account_id'] === '') {
                throw new \RuntimeException('Заповніть основний Cloudflare (token + Account ID) у налаштуваннях власника.');
            }

            return ['creds' => $creds, 'slot' => 'primary', 'pool_id' => null];
        }

        if ($target === 'backup') {
            $creds = $userSettings->cloudflareSlotCredentials('backup');
            if ($creds['token'] !== '' && $creds['account_id'] !== '') {
                return ['creds' => $creds, 'slot' => 'backup', 'pool_id' => null];
            }

            $spare = CloudflareAccount::query()->where('is_active', true)->orderBy('id')->get()
                ->first(fn (CloudflareAccount $account) => $account->isUsable());
            if ($spare === null) {
                throw new \RuntimeException('Немає запасного Cloudflare в пулі адміна.');
            }

            return [
                'creds' => $spare->credentials(),
                'slot' => 'spare',
                'pool_id' => $spare->id,
            ];
        }

        if (preg_match('/^spare:(\d+)$/', $target, $match) === 1) {
            $account = CloudflareAccount::query()->find((int) $match[1]);
            if ($account === null || ! $account->isUsable()) {
                throw new \RuntimeException('Цей запасний Cloudflare вимкнений або без token/Account ID.');
            }

            return [
                'creds' => $account->credentials(),
                'slot' => 'spare',
                'pool_id' => $account->id,
            ];
        }

        throw new \RuntimeException('Невідома Cloudflare-ціль.');
    }

    /**
     * Move an offer's Cloudflare zone onto the owner's primary CF or an admin spare account.
     * Recreates zone/A/SSL/redirects, updates Dynadot NS, then DNS recheck.
     * The previous zone is deleted only after public NS match the new pair.
     */
    public function switchCloudflareAccount(Offer $offer, string $target): void
    {
        $offer->loadMissing('user.settings');
        $userSettings = $offer->user?->settings;

        if ($userSettings === null) {
            throw new \RuntimeException('Немає налаштувань користувача.');
        }

        $resolved = $this->resolveCloudflareSwitchTarget($userSettings, $target);
        $targetCreds = $resolved['creds'];
        $targetSlot = $resolved['slot'];
        $poolId = $resolved['pool_id'];

        if (! filled($userSettings->dynadot_api_key)) {
            throw new \RuntimeException('Потрібен Dynadot API key, щоб змінити NS.');
        }

        $userSettings = $this->withPoolOrigin($userSettings, $offer);

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $currentSlot = CloudflareZoneCutover::slotFromMeta($meta);
        $domain = strtolower(trim((string) $offer->domain));
        $oldZoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));
        $oldPoolId = isset($meta['cloudflare_pool_id']) ? (int) $meta['cloudflare_pool_id'] : null;

        $oldCreds = $this->resolveActiveCloudflareCredentials($offer, $userSettings, $currentSlot);
        $targetSettings = $this->settingsWithCloudflareSlot($userSettings, $targetCreds);

        $extraIps = [];
        if ($oldZoneId !== '' && ($oldCreds['token'] ?? '') !== '') {
            try {
                $oldSettings = $this->settingsWithCloudflareSlot($userSettings, $oldCreds);
                foreach ($this->cloudflare->listARecords($oldSettings, $oldZoneId, $domain) as $record) {
                    $content = trim((string) ($record['content'] ?? ''));
                    if ($content !== '' && filter_var($content, FILTER_VALIDATE_IP)) {
                        $extraIps[] = $content;
                    }
                }
            } catch (\Throwable) {
                // Ignore — file probe / meta still resolve the origin.
            }
        }

        // Prefer origin that actually has lander files (not Settings.deploy_host).
        try {
            $serverIp = $this->origin->originIpForOffer($offer, $userSettings, $extraIps);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Немає IP origin для A-запису (ні в офера, ні на origin-серверах, ні в Settings).',
                0,
                $e,
            );
        }

        $options = InfrastructureOptions::forOffer($offer);
        // Migration always needs the full CF+Dynadot path.
        $options['cloudflare_zone'] = true;
        $options['cloudflare_dns'] = true;
        $options['cloudflare_ssl'] = $options['cloudflare_ssl'] ?? true;
        $options['cloudflare_https'] = $options['cloudflare_https'] ?? true;
        $options['cloudflare_www_redirect'] = $options['cloudflare_www_redirect'] ?? true;
        $options['dynadot_ns'] = true;

        $offer->update([
            'provision_infrastructure' => true,
            'infra_status' => 'provisioning',
            'infra_error' => null,
        ]);

        // Force a fresh zone on the target account (do not reuse the old account's zone id).
        unset($meta['cloudflare_zone_id'], $meta['nameservers'], $meta['dns_error'], $meta['dns_via'], $meta['dns']);
        $meta['dynadot_ns'] = 'pending';
        $meta['options'] = $options;
        $previousSlot = $currentSlot;
        $previousPoolId = $oldPoolId;
        $meta['cloudflare_slot'] = $targetSlot;
        $meta['cloudflare_migrating_to'] = $targetSlot;
        if ($poolId !== null) {
            $meta['cloudflare_pool_id'] = $poolId;
        } else {
            unset($meta['cloudflare_pool_id']);
        }

        try {
            $zone = $this->cloudflare->ensureZone($targetSettings, $domain);
            $zoneId = (string) $zone['zone_id'];
            $nameservers = $zone['nameservers'];
            $meta['cloudflare'] = 'done';
            $meta['cloudflare_zone_id'] = $zoneId;
            $meta['nameservers'] = $nameservers;

            $this->cloudflare->ensureRootARecord($targetSettings, $zoneId, $domain, $serverIp);
            $meta['cloudflare_dns'] = 'done';
            $meta['cloudflare_www_dns'] = 'done';
            $meta['deploy_host'] = $serverIp;

            // SSL probe must hit the offer origin, not Settings.deploy_host.
            $targetSettings->deploy_host = $serverIp;
            $this->applyCloudflareEdge($targetSettings, $domain, $zoneId, $options, $meta);

            try {
                $dynadotSettings = $this->providerSettings($offer) ?? $userSettings;
                $this->dynadot->setNameservers($dynadotSettings, $domain, $nameservers);
                $meta['dynadot_ns'] = 'done';
                unset($meta['dynadot_ns_error']);
            } catch (\Throwable $e) {
                // Keep CF migration successful even if Dynadot cannot update NS yet
                // (domain in another registrar account, NS not ready, API busy, etc.).
                $meta['dynadot_ns'] = 'pending';
                $meta['dynadot_ns_error'] = $e->getMessage();
            }

            // Keep the previous zone until public NS actually point at the new
            // pair. Deleting it here left registrar NS answering an empty zone.
            if ($oldZoneId !== '' && $oldZoneId !== $zoneId) {
                $meta['cloudflare_old_zone_id'] = $oldZoneId;
                $meta['cloudflare_old_slot'] = $currentSlot;
                if ($oldPoolId) {
                    $meta['cloudflare_old_pool_id'] = $oldPoolId;
                }
                $meta['cloudflare_old_zone_delete'] = 'pending';
            } else {
                $meta['cloudflare_old_zone_delete'] = 'skipped';
            }
            unset($meta['cloudflare_migrating_to']);
            $this->retirePreviousCloudflareZoneIfSafe($offer, $meta, $userSettings, $targetCreds['token']);

            $expectedNs = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];
            $dnsVia = $this->publicReadiness($domain, $serverIp, $expectedNs);
            if ($dnsVia !== null && ($meta['dynadot_ns'] ?? '') === 'done') {
                $meta['dns'] = 'done';
                $meta['dns_via'] = $dnsVia;
                unset($meta['dns_error']);
            } else {
                $meta['dns'] = 'pending';
            }

            $offer->update([
                'infra_status' => 'ready',
                'infra_error' => null,
                'infra_meta' => $meta,
                'cloudflare_api_token' => $targetCreds['token'],
                'cloudflare_account_id' => $targetCreds['account_id'] !== '' ? $targetCreds['account_id'] : null,
                'cloudflare_account_name' => $targetCreds['name'] !== '' ? $targetCreds['name'] : null,
                'deploy_panel_name' => $serverIp,
            ]);

            if (($meta['dns'] ?? null) === 'done') {
                app(OfferGscSubmitter::class)->queueAfterDns($offer->fresh() ?? $offer);
            }
        } catch (\Throwable $e) {
            $meta['cloudflare_migrate_error'] = $e->getMessage();
            unset($meta['cloudflare_migrating_to']);
            $meta['cloudflare_slot'] = $previousSlot;
            if ($previousPoolId) {
                $meta['cloudflare_pool_id'] = $previousPoolId;
            } else {
                unset($meta['cloudflare_pool_id']);
            }
            $offer->update([
                'infra_status' => 'failed',
                'infra_error' => DynadotClient::redactSecrets(
                    CloudflareClient::humanizeZoneCreateError($e->getMessage()),
                ),
                'infra_meta' => $meta,
            ]);

            throw $e;
        }
    }

    /**
     * If the current token already owns a zone for this domain, trust that
     * zone's NS pair over stale infra_meta (moved/pending leftover after a switch).
     *
     * @param  list<string>  $nameservers
     * @param  array<string, mixed>  $meta
     */
    private function syncOwnedCloudflareZone(
        UserSetting $settings,
        string $domain,
        string &$zoneId,
        array &$nameservers,
        array &$meta,
    ): void {
        try {
            $owned = $this->cloudflare->findZone($settings, $domain);
        } catch (\Throwable) {
            return;
        }

        if ($owned === null) {
            return;
        }

        $status = strtolower((string) ($owned['status'] ?? ''));
        if (in_array($status, ['moved', 'deleted'], true)) {
            $fresh = $this->cloudflare->ensureZone($settings, $domain);
            $zoneId = (string) ($fresh['zone_id'] ?? '');
            $nameservers = is_array($fresh['nameservers'] ?? null) ? $fresh['nameservers'] : [];
            $meta['cloudflare'] = 'done';
            $meta['cloudflare_zone_id'] = $zoneId;
            $meta['nameservers'] = $nameservers;
            unset(
                $meta['cloudflare_dns'],
                $meta['cloudflare_www_dns'],
                $meta['cloudflare_edge'],
                $meta['ssl'],
                $meta['ssl_force'],
            );

            return;
        }

        $ownedNs = is_array($owned['nameservers'] ?? null) ? $owned['nameservers'] : [];
        if ($ownedNs === []) {
            return;
        }

        $zoneId = (string) $owned['zone_id'];
        $nameservers = $ownedNs;
        $meta['cloudflare_zone_id'] = $zoneId;
        $meta['nameservers'] = $nameservers;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function retirePreviousCloudflareZoneIfSafe(
        Offer $offer,
        array &$meta,
        ?UserSetting $settings = null,
        string $activeToken = '',
    ): void {
        if (($meta['cloudflare_old_zone_delete'] ?? '') !== 'pending') {
            return;
        }

        $oldZoneId = trim((string) ($meta['cloudflare_old_zone_id'] ?? ''));
        if ($oldZoneId === '') {
            $meta['cloudflare_old_zone_delete'] = 'skipped';

            return;
        }

        $expectedNs = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];
        $liveNs = $this->publicDns->nameservers(strtolower(trim((string) $offer->domain)));
        if (! CloudflareZoneCutover::shouldDeleteOldZone($liveNs, $expectedNs)) {
            return;
        }

        $settings ??= $this->providerSettings($offer) ?? $offer->user?->settings;
        if ($settings === null) {
            return;
        }

        $oldCreds = $this->resolvePreviousCloudflareCredentials($settings, $meta);
        $newToken = CloudflareClient::normalizeApiToken(
            $activeToken !== ''
                ? $activeToken
                : ($offer->cloudflare_api_token ?: $settings->cloudflare_api_token),
        );
        if ($oldCreds['token'] === '' || ($newToken !== '' && hash_equals($newToken, $oldCreds['token']))) {
            $meta['cloudflare_old_zone_delete'] = 'skipped';
            unset($meta['cloudflare_old_zone_id']);

            return;
        }

        try {
            $oldSettings = $this->settingsWithCloudflareSlot($settings, $oldCreds);
            $meta['cloudflare_old_zone_delete'] = $this->cloudflare->deleteZone($oldSettings, $oldZoneId);
            unset($meta['cloudflare_old_zone_id'], $meta['cloudflare_old_zone_delete_error']);
        } catch (\Throwable $e) {
            $meta['cloudflare_old_zone_delete'] = 'failed: '.DynadotClient::redactSecrets($e->getMessage());
            $meta['cloudflare_old_zone_delete_error'] = DynadotClient::redactSecrets($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{token: string, account_id: string, name: string}
     */
    private function resolvePreviousCloudflareCredentials(UserSetting $settings, array $meta): array
    {
        $poolId = (int) ($meta['cloudflare_old_pool_id'] ?? 0);
        if ($poolId > 0) {
            $account = CloudflareAccount::query()->find($poolId);
            if ($account !== null) {
                return $account->credentials();
            }
        }

        $slot = (string) ($meta['cloudflare_old_slot'] ?? 'primary');
        if ($slot === 'spare') {
            return ['token' => '', 'account_id' => '', 'name' => ''];
        }

        return $settings->cloudflareSlotCredentials($slot === 'backup' ? 'backup' : 'primary');
    }

    /**
     * @param  array{token: string, account_id: string, name: string}  $creds
     */
    private function settingsWithCloudflareSlot(UserSetting $base, array $creds): UserSetting
    {
        $merged = $base->replicate();
        $merged->cloudflare_api_token = $creds['token'];
        $merged->cloudflare_account_id = $creds['account_id'] !== '' ? $creds['account_id'] : null;
        $merged->cloudflare_account_name = $creds['name'] !== '' ? $creds['name'] : null;

        return $merged;
    }

    /**
     * @return array{token: string, account_id: string, name: string}
     */
    private function resolveActiveCloudflareCredentials(Offer $offer, UserSetting $settings, string $currentSlot): array
    {
        // Prefer the credentials currently stored on the offer (last successful CF account).
        $offerToken = CloudflareClient::normalizeApiToken($offer->cloudflare_api_token);
        if ($offerToken !== '') {
            return [
                'token' => $offerToken,
                'account_id' => trim((string) ($offer->cloudflare_account_id ?? '')),
                'name' => trim((string) ($offer->cloudflare_account_name ?? '')),
            ];
        }

        return $settings->cloudflareSlotCredentials($currentSlot);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function originIpFromMetaOrSettings(UserSetting $settings, array $meta): string
    {
        $fromMeta = trim((string) ($meta['deploy_host'] ?? ''));

        if ($fromMeta !== '' && filter_var($fromMeta, FILTER_VALIDATE_IP)) {
            return $fromMeta;
        }

        return $this->origin->originIp($settings);
    }

    private function providerSettings(Offer $offer): ?UserSetting
    {
        $settings = $offer->user?->settings;

        if ($settings === null) {
            return null;
        }

        if (filled($offer->cloudflare_api_token) || filled($offer->dynadot_api_key)) {
            $merged = $settings->replicate();

            if (filled($offer->cloudflare_api_token)) {
                $merged->cloudflare_api_token = CloudflareClient::normalizeApiToken($offer->cloudflare_api_token);

                if (filled($offer->cloudflare_account_id)) {
                    $merged->cloudflare_account_id = $offer->cloudflare_account_id;
                }
            }

            if (filled($offer->dynadot_api_key)) {
                $merged->dynadot_api_key = $offer->dynadot_api_key;

                if (filled($offer->dynadot_contact_id)) {
                    $merged->dynadot_contact_id = $offer->dynadot_contact_id;
                }
            }

            $settings = $merged;
        }

        return $this->withPoolOrigin($settings, $offer);
    }

    /**
     * Attach the offer's pool origin (host/user/password) to a settings clone so
     * every origin call keeps reading $settings->deploy_*. Read-only paths never
     * allocate a new server — provision() re-resolves strictly with binding.
     */
    private function withPoolOrigin(UserSetting $settings, Offer $offer, bool $bind = false): UserSetting
    {
        try {
            return $this->pool->settingsForOffer($settings, $offer, $bind);
        } catch (\Throwable) {
            return $settings;
        }
    }
}
