<?php

namespace App\Services;

use App\Models\OriginServer;
use App\Models\ProxyServer;
use App\Support\OriginSsh;
use RuntimeException;

/**
 * Origins only rewrite REMOTE_ADDR from CF-Connecting-IP when the peer is trusted.
 * Cloudflare ranges are already in cloudflare-realip.conf; proxy boxes must be listed too
 * or CRM/logs see the proxy IP instead of the visitor.
 */
class OriginProxyRealIpService
{
    public const REMOTE_CONF = '/etc/nginx/conf.d/offerra-proxy-realip.conf';

    public function __construct(
        private readonly OriginSsh $ssh,
    ) {}

    public function confContents(): string
    {
        $lines = [
            '# Managed by Offerra. Trust edge-proxy IPs for CF-Connecting-IP.',
        ];

        $hosts = ProxyServer::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('host');

        foreach ($hosts as $host) {
            $ip = trim((string) $host);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $lines[] = 'set_real_ip_from '.$ip.';';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{host: string, ok: bool, message: string}>
     */
    public function syncAll(): array
    {
        $results = [];
        $body = $this->confContents();

        foreach (OriginServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            $results[] = $this->syncOrigin($server, $body);
        }

        return $results;
    }

    /**
     * @return array{host: string, ok: bool, message: string}
     */
    public function syncOrigin(OriginServer $server, ?string $body = null): array
    {
        $host = (string) $server->host;
        $body ??= $this->confContents();

        if (! $server->hasSshCredentials()) {
            return ['host' => $host, 'ok' => false, 'message' => 'Немає SSH-креденшалів'];
        }

        $local = tempnam(sys_get_temp_dir(), 'offerra-realip-');
        if ($local === false) {
            throw new RuntimeException('Не вдалося створити тимчасовий realip файл.');
        }

        try {
            file_put_contents($local, $body);
            $this->ssh->upload(
                $host,
                (int) ($server->port ?: 22),
                (string) $server->username,
                (string) $server->password,
                $local,
                self::REMOTE_CONF,
                20,
            );
            $out = $this->ssh->exec(
                $host,
                (int) ($server->port ?: 22),
                (string) $server->username,
                (string) $server->password,
                'nginx -t >/tmp/offerra-nginx-t.out 2>&1; ec=$?; echo NGINX_T:$ec; cat /tmp/offerra-nginx-t.out; if [ $ec -eq 0 ]; then systemctl reload nginx; echo RELOAD:$?; else echo RELOAD:skip; fi',
                25,
            );
        } catch (\Throwable $e) {
            return ['host' => $host, 'ok' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($local);
        }

        $ok = str_contains($out, 'NGINX_T:0') && str_contains($out, 'RELOAD:0');

        return [
            'host' => $host,
            'ok' => $ok,
            'message' => $ok ? 'ok' : trim(preg_replace('/\s+/', ' ', $out) ?? $out),
        ];
    }
}
