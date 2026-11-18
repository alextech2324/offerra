<?php

namespace App\Services;

use App\Models\OriginServer;
use App\Models\UserSetting;
use App\Support\DeployDriver;
use App\Support\OriginSsh;
use Illuminate\Support\Facades\Http;

class OriginHealthProbe
{
    public const DISK_WARN_PERCENT = 90;

    /**
     * @return array<string, mixed>
     */
    public function probe(UserSetting $settings, ?string $password = null): array
    {
        return $this->probeTarget(
            host: trim((string) $settings->deploy_host),
            port: (int) ($settings->deploy_port ?: 22),
            username: trim((string) $settings->deploy_username),
            password: $password ?? (string) ($settings->deploy_password ?? ''),
            pathTemplate: $settings->deploy_path_template ?: DeployDriver::defaultPath($settings->deploy_driver),
            driver: DeployDriver::normalize($settings->deploy_driver),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function probeServer(OriginServer $server, ?string $password = null): array
    {
        return $this->probeTarget(
            host: trim((string) $server->host),
            port: (int) ($server->port ?: 22),
            username: trim((string) ($server->username ?? '')),
            password: $password ?? (string) ($server->password ?? ''),
            pathTemplate: $server->deploy_path_template ?: DeployDriver::defaultPath($server->deploy_driver),
            driver: DeployDriver::normalize($server->deploy_driver),
        );
    }

    /**
     * Lightweight check for hosts without SSH credentials (orphans).
     *
     * @return array<string, mixed>
     */
    public function probeHttpOnly(string $host): array
    {
        $host = trim($host);
        $http = $this->checkHttp($host);
        $tcp80 = $this->checkTcp($host, 80);
        $tcp22 = $this->checkTcp($host, 22);

        $issues = [];
        if (! ($tcp22['ok'] ?? false)) {
            $issues[] = 'SSH :22 закритий';
        }
        if (! ($tcp80['ok'] ?? false)) {
            $issues[] = 'HTTP :80 закритий';
        }
        if (! ($http['ok'] ?? false)) {
            $issues[] = 'HTTP origin не відповідає';
        }

        $sshStub = [
            'ok' => false,
            'latency_ms' => $tcp22['latency_ms'] ?? null,
            'error' => ($tcp22['ok'] ?? false) ? 'Немає SSH-креденшалів у реєстрі' : 'TCP :22 недоступний',
        ];

        if ($issues !== []) {
            return $this->result('down', implode('; ', $issues), $sshStub, $http, [
                'tcp22' => $tcp22['ok'] ?? false,
                'tcp80' => $tcp80['ok'] ?? false,
                'orphan' => true,
            ], $issues);
        }

        return $this->result('degraded', 'HTTP живий, але SSH-креденшалів немає — повний моніторинг недоступний', $sshStub, $http, [
            'tcp22' => true,
            'tcp80' => true,
            'orphan' => true,
            'nginx' => 'unknown',
            'php_fpm' => 'unknown',
            'web' => 'unknown',
        ], ['немає SSH у реєстрі origin_servers']);
    }

    /**
     * @return array<string, mixed>
     */
    public function probeTarget(
        string $host,
        int $port,
        string $username,
        string $password,
        ?string $pathTemplate = null,
        ?string $driver = null,
    ): array {
        $host = trim($host);
        $username = trim($username);
        $password = (string) $password;

        if ($host === '' || $username === '' || $password === '') {
            return $this->evaluate(
                ['ok' => false, 'latency_ms' => null, 'error' => 'Не заповнені host, користувач або пароль SSH.'],
                ['ok' => false, 'status' => null, 'latency_ms' => null, 'error' => 'skipped'],
                [],
            );
        }

        $pathTemplate = $pathTemplate ?: DeployDriver::defaultPath($driver);
        $ssh = $this->checkSsh($host, $port, $username, $password, $pathTemplate);
        $http = $this->checkHttp($host);

        return $this->evaluate($ssh, $http, is_array($ssh['metrics'] ?? null) ? $ssh['metrics'] : []);
    }

    /**
     * @param  array{ok: bool, latency_ms: ?int, error: ?string, metrics?: array<string, mixed>}  $ssh
     * @param  array{ok: bool, status: ?int, latency_ms: ?int, error: ?string}  $http
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function evaluate(array $ssh, array $http, array $metrics): array
    {
        $issues = [];

        if (! ($ssh['ok'] ?? false)) {
            $message = (string) ($ssh['error'] ?? 'SSH не відповідає');

            return $this->result('down', $message, $ssh, $http, $metrics, [$message]);
        }

        $diskRoot = (int) ($metrics['disk_root_pct'] ?? 0);
        $diskWww = (int) ($metrics['disk_www_pct'] ?? 0);
        $nginx = (string) ($metrics['nginx'] ?? 'unknown');
        $phpFpm = (string) ($metrics['php_fpm'] ?? 'unknown');
        $web = (string) ($metrics['web'] ?? $nginx);

        if ($diskRoot >= self::DISK_WARN_PERCENT) {
            $issues[] = "диск / {$diskRoot}%";
        }

        if ($diskWww >= self::DISK_WARN_PERCENT) {
            $issues[] = "диск /var/www {$diskWww}%";
        }

        if ($web === 'down' && $nginx === 'down') {
            $issues[] = 'nginx/apache не запущені';
        }

        if ($phpFpm === 'down') {
            $issues[] = 'php-fpm не запущений';
        }

        if (($metrics['writable'] ?? true) === false) {
            $issues[] = 'немає прав на шлях деплою';
        }

        if (! ($http['ok'] ?? false)) {
            $issues[] = 'HTTP origin не відповідає';
        }

        if ($issues !== []) {
            return $this->result('degraded', implode('; ', $issues), $ssh, $http, $metrics, $issues);
        }

        return $this->result('ok', 'Сервер відповідає', $ssh, $http, $metrics, []);
    }

    /**
     * @param  array<string, mixed>  $ssh
     * @param  array<string, mixed>  $http
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function result(string $status, string $message, array $ssh, array $http, array $metrics, array $issues): array
    {
        return [
            'ok' => $status === 'ok',
            'status' => $status,
            'message' => $message,
            'issues' => $issues,
            'ssh' => [
                'ok' => (bool) ($ssh['ok'] ?? false),
                'latency_ms' => $ssh['latency_ms'] ?? null,
                'error' => $ssh['error'] ?? null,
            ],
            'http' => [
                'ok' => (bool) ($http['ok'] ?? false),
                'status' => $http['status'] ?? null,
                'latency_ms' => $http['latency_ms'] ?? null,
                'error' => $http['error'] ?? null,
            ],
            'metrics' => [
                'load' => $metrics['load'] ?? null,
                'ram_used_mb' => $metrics['ram_used_mb'] ?? null,
                'ram_total_mb' => $metrics['ram_total_mb'] ?? null,
                'ram_pct' => $metrics['ram_pct'] ?? null,
                'disk_root_pct' => $metrics['disk_root_pct'] ?? null,
                'disk_root_free_gb' => $metrics['disk_root_free_gb'] ?? null,
                'disk_www_pct' => $metrics['disk_www_pct'] ?? null,
                'disk_www_free_gb' => $metrics['disk_www_free_gb'] ?? null,
                'nginx' => $metrics['nginx'] ?? 'unknown',
                'php_fpm' => $metrics['php_fpm'] ?? 'unknown',
                'web' => $metrics['web'] ?? ($metrics['nginx'] ?? 'unknown'),
                'writable' => $metrics['writable'] ?? null,
                'tcp22' => $metrics['tcp22'] ?? null,
                'tcp80' => $metrics['tcp80'] ?? null,
                'orphan' => $metrics['orphan'] ?? null,
            ],
        ];
    }

    /**
     * @return array{ok: bool, latency_ms: ?int, error: ?string, metrics?: array<string, mixed>}
     */
    private function checkSsh(string $host, int $port, string $username, string $password, string $pathTemplate): array
    {
        $started = microtime(true);

        try {
            $probePath = $this->probePath($pathTemplate, $username);
            $safePath = preg_replace('#[^a-zA-Z0-9/._-]#', '', $probePath) ?: '/tmp/offerra_health';
            $output = app(OriginSsh::class)->exec(
                $host,
                $port,
                $username,
                $password,
                $this->metricsScript($safePath),
                15,
            );

            $metrics = $this->parseMetrics($output);

            if ($metrics === null) {
                return [
                    'ok' => true,
                    'latency_ms' => $this->elapsedMs($started),
                    'error' => null,
                    'metrics' => [
                        'nginx' => 'unknown',
                        'php_fpm' => 'unknown',
                        'web' => 'unknown',
                        'parse_error' => trim($output) !== '' ? mb_substr(trim($output), 0, 180) : 'empty',
                    ],
                ];
            }

            return [
                'ok' => true,
                'latency_ms' => $this->elapsedMs($started),
                'error' => null,
                'metrics' => $metrics,
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            return [
                'ok' => false,
                'latency_ms' => $this->elapsedMs($started),
                'error' => str_starts_with($message, 'SSH:') ? $message : 'SSH: '.$message,
            ];
        }
    }

    /**
     * @return array{ok: bool, status: ?int, latency_ms: ?int, error: ?string}
     */
    private function checkHttp(string $host): array
    {
        $started = microtime(true);
        $url = 'http://'.$host.'/';

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->withOptions(['verify' => false, 'http_errors' => false])
                ->withHeaders(['User-Agent' => 'Offerra-OriginHealth/1'])
                ->get($url);

            $status = $response->status();

            return [
                'ok' => $status > 0 && $status < 500,
                'status' => $status,
                'latency_ms' => $this->elapsedMs($started),
                'error' => $status >= 500 ? 'HTTP '.$status : null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'latency_ms' => $this->elapsedMs($started),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, latency_ms: ?int}
     */
    private function checkTcp(string $host, int $port): array
    {
        $started = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if (is_resource($fp)) {
            fclose($fp);

            return ['ok' => true, 'latency_ms' => $this->elapsedMs($started)];
        }

        return ['ok' => false, 'latency_ms' => $this->elapsedMs($started)];
    }

    private function probePath(string $pathTemplate, string $username): string
    {
        return str_replace(
            ['{user}', '{domain}'],
            [$username, '_offerra_health'],
            $pathTemplate,
        );
    }

    private function metricsScript(string $probePath): string
    {
        return 'PROBE=\''.$probePath."'\n".<<<'SH'
set +e
if [ ! -e /etc/resolv.conf ] || ! grep -q '^nameserver' /etc/resolv.conf 2>/dev/null; then
  chattr -i /etc/resolv.conf 2>/dev/null
  rm -f /etc/resolv.conf
  printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf
  chattr +i /etc/resolv.conf 2>/dev/null
fi
LOAD=$(awk '{print $1" "$2" "$3}' /proc/loadavg 2>/dev/null)
MEM_TOTAL=$(awk '/MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null)
MEM_AVAIL=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo 2>/dev/null)
DISK_ROOT_PCT=$(df -P / 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')
DISK_ROOT_FREE=$(df -P / 2>/dev/null | awk 'NR==2 {print $4}')
DISK_WWW_PCT=$(df -P /var/www 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')
DISK_WWW_FREE=$(df -P /var/www 2>/dev/null | awk 'NR==2 {print $4}')
NGINX=down
APACHE=down
PHP=down
pgrep -x nginx >/dev/null 2>&1 && NGINX=up
pgrep -x apache2 >/dev/null 2>&1 && APACHE=up
pgrep -x httpd >/dev/null 2>&1 && APACHE=up
pgrep -f 'php-fpm' >/dev/null 2>&1 && PHP=up
WEB=down
if [ "$NGINX" = up ] || [ "$APACHE" = up ]; then WEB=up; fi
WRITABLE=0
if [ -n "$PROBE" ]; then
  mkdir -p "$PROBE" 2>/dev/null && touch "$PROBE/.w" 2>/dev/null && WRITABLE=1
  rm -rf "$PROBE" 2>/dev/null
fi
MEM_USED=0
if [ -n "$MEM_TOTAL" ] && [ -n "$MEM_AVAIL" ]; then
  MEM_USED=$((MEM_TOTAL-MEM_AVAIL))
fi
printf '{"load":"%s","mem_total_kb":%s,"mem_used_kb":%s,"disk_root_pct":%s,"disk_root_free_kb":%s,"disk_www_pct":%s,"disk_www_free_kb":%s,"nginx":"%s","php_fpm":"%s","web":"%s","writable":%s}\n' \
  "${LOAD:-}" "${MEM_TOTAL:-0}" "${MEM_USED:-0}" "${DISK_ROOT_PCT:-0}" "${DISK_ROOT_FREE:-0}" "${DISK_WWW_PCT:-0}" "${DISK_WWW_FREE:-0}" "$NGINX" "$PHP" "$WEB" "$WRITABLE"
SH;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseMetrics(string $output): ?array
    {
        $json = null;

        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $line = trim((string) $line);

            if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                $json = $line;
                break;
            }
        }

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $loadRaw = trim((string) ($data['load'] ?? ''));
        $loadParts = preg_split('/\s+/', $loadRaw) ?: [];
        $memTotalKb = (int) ($data['mem_total_kb'] ?? 0);
        $memUsedKb = (int) ($data['mem_used_kb'] ?? 0);

        return [
            'load' => [
                '1' => isset($loadParts[0]) ? (float) $loadParts[0] : null,
                '5' => isset($loadParts[1]) ? (float) $loadParts[1] : null,
                '15' => isset($loadParts[2]) ? (float) $loadParts[2] : null,
            ],
            'ram_used_mb' => $memUsedKb > 0 ? (int) round($memUsedKb / 1024) : null,
            'ram_total_mb' => $memTotalKb > 0 ? (int) round($memTotalKb / 1024) : null,
            'ram_pct' => $memTotalKb > 0 ? (int) round(($memUsedKb / $memTotalKb) * 100) : null,
            'disk_root_pct' => (int) ($data['disk_root_pct'] ?? 0),
            'disk_root_free_gb' => round(((int) ($data['disk_root_free_kb'] ?? 0)) / 1024 / 1024, 1),
            'disk_www_pct' => (int) ($data['disk_www_pct'] ?? 0),
            'disk_www_free_gb' => round(((int) ($data['disk_www_free_kb'] ?? 0)) / 1024 / 1024, 1),
            'nginx' => (string) ($data['nginx'] ?? 'unknown'),
            'php_fpm' => (string) ($data['php_fpm'] ?? 'unknown'),
            'web' => (string) ($data['web'] ?? 'unknown'),
            'writable' => ((int) ($data['writable'] ?? 0)) === 1,
        ];
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
