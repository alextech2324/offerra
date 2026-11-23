<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\ProxyServer;
use App\Support\OriginSsh;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EdgeProxyService
{
    public function __construct(
        private readonly CloudflareClient $cloudflare,
        private readonly OriginHostService $origin,
        private readonly OriginSsh $ssh,
        private readonly DynadotClient $dynadot,
    ) {}

    /**
     * Put the offer behind the proxy: LE cert, nginx vhost, grey-cloud A → proxy IP.
     * Creates the Cloudflare zone first when the offer was deployed without one.
     */
    public function attach(Offer $offer, ProxyServer $proxy): void
    {
        $offer->loadMissing('user.settings');
        $settings = $offer->user?->settings;
        if ($settings === null) {
            throw new RuntimeException('Немає налаштувань користувача.');
        }

        $domain = $this->normalizedDomain($offer);
        $originIp = $this->origin->originIpForOffer($offer, $settings);
        $cf = $this->cloudflareSettingsFor($offer, $settings);
        $token = CloudflareClient::normalizeApiToken((string) $cf->cloudflare_api_token);
        if ($token === '' || trim((string) $cf->cloudflare_account_id) === '') {
            throw new RuntimeException('Немає Cloudflare token і Account ID, щоб створити зону під проксі.');
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $zoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));
        $nameservers = is_array($meta['nameservers'] ?? null) ? $meta['nameservers'] : [];

        if ($zoneId === '') {
            $zone = $this->cloudflare->ensureZone($cf, $domain);
            $zoneId = trim((string) ($zone['zone_id'] ?? ''));
            $nameservers = is_array($zone['nameservers'] ?? null) ? $zone['nameservers'] : [];
            if ($zoneId === '') {
                throw new RuntimeException('Cloudflare не повернув zone id для '.$domain);
            }
            $meta['cloudflare'] = 'done';
            $meta['cloudflare_zone_id'] = $zoneId;
            $meta['nameservers'] = $nameservers;
            $offer->forceFill(['infra_meta' => $meta])->save();
        }

        $this->cloudflare->setRootARecords($cf, $zoneId, $domain, $proxy->host, false);

        if ($nameservers !== []) {
            try {
                $this->dynadot->setNameservers($this->dynadotSettingsFor($offer, $settings), $domain, $nameservers);
                $meta['dynadot_ns'] = 'done';
                unset($meta['dynadot_ns_error']);
            } catch (\Throwable $e) {
                $meta['dynadot_ns'] = 'pending';
                $meta['dynadot_ns_error'] = $e->getMessage();
            }
        }

        // DNS-01 needs the new CF nameservers to be public. Bind HTTP-only if
        // Let's Encrypt cannot see the zone yet — Recheck / retry will get TLS.
        try {
            $this->bindOnProxy($proxy, $domain, $originIp, $token);
            $meta['ssl'] = 'done';
            unset($meta['ssl_error']);
        } catch (\Throwable $e) {
            $meta['ssl'] = 'pending';
            $meta['ssl_error'] = $e->getMessage();
            try {
                $this->bindOnProxy($proxy, $domain, $originIp, null);
            } catch (\Throwable $http) {
                $meta['ssl_error'] = $e->getMessage().' | http-bind: '.$http->getMessage();
            }
        }

        $meta['edge'] = 'proxy';
        $meta['edge_proxy_id'] = $proxy->id;
        $meta['edge_ip'] = $proxy->host;
        $meta['dns'] = 'pending';
        unset($meta['dns_error'], $meta['edge_error']);

        $offer->update([
            'infra_meta' => $meta,
            'infra_error' => null,
        ]);
    }

    /**
     * Restore orange-cloud Cloudflare in front of the origin.
     */
    public function detach(Offer $offer): void
    {
        $offer->loadMissing('user.settings');
        $settings = $offer->user?->settings;
        if ($settings === null) {
            throw new RuntimeException('Немає налаштувань користувача.');
        }

        $domain = $this->normalizedDomain($offer);
        $originIp = $this->origin->originIpForOffer($offer, $settings);
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $zoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));
        $token = CloudflareClient::normalizeApiToken((string) ($offer->cloudflare_api_token ?: $settings->cloudflare_api_token));
        $proxy = $this->proxyFromMeta($meta);

        if ($zoneId !== '' && $token !== '') {
            $this->cloudflare->setRootARecords($this->cfSettings($settings, $token), $zoneId, $domain, $originIp, true);
        }

        if ($proxy !== null) {
            try {
                $this->unbindOnProxy($proxy, $domain);
            } catch (\Throwable $e) {
                // DNS already back on CF; leftover nginx site is harmless.
            }
        }

        unset($meta['edge_proxy_id'], $meta['edge_ip'], $meta['dns_error'], $meta['edge_error']);
        $meta['edge'] = 'cloudflare';
        $meta['dns'] = 'pending';
        $offer->update([
            'infra_meta' => $meta,
            'infra_error' => null,
        ]);
    }

    /**
     * Origin moved while the offer stays on the proxy: update upstream only.
     */
    public function retargetOrigin(Offer $offer, string $originIp): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $proxy = $this->proxyFromMeta($meta);
        if ($proxy === null) {
            return;
        }

        $this->bindOnProxy($proxy, $this->normalizedDomain($offer), $originIp, null);
        $meta['deploy_host'] = $originIp;
        $offer->update(['infra_meta' => $meta]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function checkHealth(ProxyServer $proxy): array
    {
        if (! $proxy->hasSshCredentials()) {
            return ['ok' => false, 'message' => 'Немає SSH-креденшалів'];
        }

        try {
            $out = $this->ssh->exec(
                (string) $proxy->host,
                (int) ($proxy->port ?: 22),
                (string) $proxy->username,
                (string) $proxy->password,
                'offerra-edge health',
                25,
            );
            $ok = str_contains($out, 'RESULT: OK') && str_contains($out, 'nginx=active');

            return [
                'ok' => $ok,
                'message' => $ok ? 'nginx active' : trim(preg_replace('/\s+/', ' ', $out) ?? $out),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function dropSite(Offer $offer): void
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['edge'] ?? '') !== 'proxy') {
            return;
        }
        $proxy = $this->proxyFromMeta($meta);
        if ($proxy === null) {
            return;
        }
        try {
            $this->unbindOnProxy($proxy, $this->normalizedDomain($offer));
        } catch (\Throwable) {
        }
    }

    private function bindOnProxy(ProxyServer $proxy, string $domain, string $originIp, ?string $cfToken): void
    {
        $this->withProxyLock($proxy, function () use ($proxy, $domain, $originIp, $cfToken): void {
            $this->bindOnProxyUnlocked($proxy, $domain, $originIp, $cfToken);
        });
    }

    private function bindOnProxyUnlocked(ProxyServer $proxy, string $domain, string $originIp, ?string $cfToken): void
    {
        $this->assertProxy($proxy);
        $originIp = trim($originIp);
        if (filter_var($originIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Невалідний origin IP для проксі.');
        }

        $remoteIni = '';
        $cmd = 'offerra-edge bind '.escapeshellarg($domain).' '.escapeshellarg($originIp);

        if (filled($cfToken)) {
            $local = tempnam(sys_get_temp_dir(), 'offerra-cfini-');
            if ($local === false) {
                throw new RuntimeException('Не вдалося створити тимчасовий файл для Cloudflare token.');
            }
            file_put_contents($local, "dns_cloudflare_api_token = {$cfToken}\n");
            $remoteIni = '/tmp/offerra-acme-'.$domain.'.ini';
            try {
                $this->ssh->upload(
                    (string) $proxy->host,
                    (int) ($proxy->port ?: 22),
                    (string) $proxy->username,
                    (string) $proxy->password,
                    $local,
                    $remoteIni,
                    30,
                );
            } finally {
                @unlink($local);
            }
            $cmd .= ' '.escapeshellarg($remoteIni);
        }

        $rm = 'rm -f '.escapeshellarg($remoteIni !== '' ? $remoteIni : '/tmp/offerra-acme-none.ini');
        $out = $this->ssh->exec(
            (string) $proxy->host,
            (int) ($proxy->port ?: 22),
            (string) $proxy->username,
            (string) $proxy->password,
            $cmd.' 2>&1; ec=$?; '.$rm.'; exit $ec',
            240,
        );

        if (! str_contains($out, 'BOUND ')) {
            throw new RuntimeException('Проксі не підтвердив bind: '.trim($out));
        }
        // HTTP-only bind (no CF token) is supposed to print cert=0 so nginx
        // still serves the lander while Recheck retries Let's Encrypt.
        if (filled($cfToken) && preg_match('/cert=0\b/', $out)) {
            throw new RuntimeException('Let’s Encrypt не видав сертифікат: '.trim($out));
        }
    }

    private function unbindOnProxy(ProxyServer $proxy, string $domain): void
    {
        $this->withProxyLock($proxy, function () use ($proxy, $domain): void {
            $this->assertProxy($proxy);
            $this->ssh->exec(
                (string) $proxy->host,
                (int) ($proxy->port ?: 22),
                (string) $proxy->username,
                (string) $proxy->password,
                'offerra-edge unbind '.escapeshellarg($domain),
                40,
            );
        });
    }

    /**
     * Certbot and nginx -t cannot run two binds on the same box at once.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withProxyLock(ProxyServer $proxy, callable $callback): mixed
    {
        $lock = Cache::lock('edge-proxy-'.$proxy->id, 360);

        try {
            $lock->block(300);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Проксі зараз зайнятий іншим доменом — повторіть.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function assertProxy(ProxyServer $proxy): void
    {
        if (! $proxy->is_active) {
            throw new RuntimeException('Проксі вимкнений.');
        }
        if (! $proxy->hasSshCredentials()) {
            throw new RuntimeException('У проксі немає SSH-креденшалів.');
        }
    }

    private function proxyFromMeta(array $meta): ?ProxyServer
    {
        $id = (int) ($meta['edge_proxy_id'] ?? 0);
        if ($id > 0) {
            $row = ProxyServer::query()->find($id);
            if ($row) {
                return $row;
            }
        }
        $ip = trim((string) ($meta['edge_ip'] ?? ''));
        if ($ip === '') {
            return null;
        }

        return ProxyServer::query()->where('host', $ip)->first();
    }

    private function cfSettings(\App\Models\UserSetting $settings, string $token): \App\Models\UserSetting
    {
        $probe = $settings->replicate();
        $probe->cloudflare_api_token = $token;

        return $probe;
    }

    private function cloudflareSettingsFor(Offer $offer, \App\Models\UserSetting $settings): \App\Models\UserSetting
    {
        $cf = $settings->replicate();
        $token = CloudflareClient::normalizeApiToken((string) ($offer->cloudflare_api_token ?: $settings->cloudflare_api_token));
        $accountId = trim((string) ($offer->cloudflare_account_id ?: $settings->cloudflare_account_id));
        $accountName = trim((string) ($offer->cloudflare_account_name ?: $settings->cloudflare_account_name));
        $cf->cloudflare_api_token = $token;
        if ($accountId !== '') {
            $cf->cloudflare_account_id = $accountId;
        }
        if ($accountName !== '') {
            $cf->cloudflare_account_name = $accountName;
        }

        return $cf;
    }

    private function dynadotSettingsFor(Offer $offer, \App\Models\UserSetting $settings): \App\Models\UserSetting
    {
        if (! filled($offer->dynadot_api_key)) {
            return $settings;
        }

        $merged = $settings->replicate();
        $merged->dynadot_api_key = $offer->dynadot_api_key;
        if (filled($offer->dynadot_contact_id)) {
            $merged->dynadot_contact_id = $offer->dynadot_contact_id;
        }

        return $merged;
    }

    private function normalizedDomain(Offer $offer): string
    {
        $domain = strtolower(trim((string) $offer->domain));
        if ($domain === '' || ! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
            throw new RuntimeException('Невалідний домен для проксі.');
        }

        return $domain;
    }
}
