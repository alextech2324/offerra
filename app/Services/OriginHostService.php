<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\UserSetting;
use App\Support\DeployDriver;
use App\Support\OriginSsh;
use RuntimeException;

class OriginHostService
{
    public function ensureWebRoot(UserSetting $settings, string $domain): string
    {
        $path = $this->publicHtmlPath($settings, $domain);
        $quoted = $this->quote($path);
        $parent = $this->quote(dirname($path));

        $this->exec(
            $settings,
            "mkdir -p /var/www/offers && mkdir -p {$quoted} && chown www-data:www-data /var/www/offers {$parent} {$quoted} && chmod 775 /var/www/offers {$parent} {$quoted}",
        );

        return $path;
    }

    public function deleteWebRoot(UserSetting $settings, string $domain): void
    {
        $path = $this->offerRootPath($settings, $domain);
        $quoted = $this->quote($path);

        $this->exec($settings, "rm -rf {$quoted}");
    }

    /**
     * Return deployed offer domains on this origin whose head.php calls
     * canonical_url() while helpers.php no longer defines it — the exact state
     * that makes a lander return HTTP 500. Checks the base includes/ and every
     * langs/<lang>/includes/. The trailing `true` keeps the exit code at 0 so an
     * empty (no-match) result is not treated as a failure.
     *
     * @return list<string>
     */
    public function findBrokenLanders(UserSetting $settings, int $timeout = 120): array
    {
        $scan = <<<'SH'
for d in /var/www/offers/*/public_html; do
  dom=$(basename "$(dirname "$d")"); broken=0
  for inc in "$d/includes" "$d"/langs/*/includes; do
    [ -f "$inc/head.php" ] || continue
    if grep -q 'canonical_url' "$inc/head.php" 2>/dev/null; then
      grep -q 'function canonical_url' "$inc/helpers.php" 2>/dev/null || { broken=1; break; }
    fi
  done
  [ "$broken" = "1" ] && echo "$dom"
done
true
SH;

        $output = $this->exec($settings, $scan, $timeout);

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    /**
     * Pack the local offer folder into tar.gz, upload once, unpack on origin.
     *
     * @param  list<string>  $skipFiles
     */
    public function deployArchive(UserSetting $settings, string $domain, string $localPath, array $skipFiles = []): string
    {
        if (! is_dir($localPath) || ! is_file($localPath.'/index.php')) {
            throw new RuntimeException('Локальна папка оффера не готова для архіву.');
        }

        $remotePath = $this->ensureWebRoot($settings, $domain);
        $safeDomain = $this->normalizeDomain($domain);
        $archive = sys_get_temp_dir().'/offerra-'.$safeDomain.'-'.bin2hex(random_bytes(3)).'.tar.gz';
        $remoteArchive = '/tmp/offerra-'.$safeDomain.'.tgz';

        try {
            $this->buildTar($localPath, $archive, $skipFiles);
            $this->uploadFile($settings, $archive, $remoteArchive);

            $root = $this->quote($remotePath);
            $pack = $this->quote($remoteArchive);
            $this->exec(
                $settings,
                $this->ensureTarCommand()
                ." && find {$root} -mindepth 1 -delete && tar -xzf {$pack} -C {$root} && chown -R www-data:www-data {$root} && chmod -R u+rwX,g+rX,o+rX {$root} && test -f {$root}/index.php && rm -f {$pack}",
                180,
            );
        } finally {
            @unlink($archive);
        }

        return $remotePath;
    }

    public function originIp(UserSetting $settings): string
    {
        return $this->resolveHostToIp(
            trim((string) $settings->deploy_host),
            'Заповніть SSH host (IP сервера) у налаштуваннях деплою.',
        );
    }

    /**
     * Resolve the origin IP that should back Cloudflare A for this offer.
     *
     * Priority:
     * 1) Bound host (infra_meta / panel) if it actually has lander files
     * 2) Any known OriginServer (or Settings host) that has the files
     * 3) Bound host / Settings even without a file probe (new offers)
     *
     * @param  list<string>  $extraCandidates  Optional IPs (e.g. current CF A content)
     */
    public function originIpForOffer(Offer $offer, UserSetting $settings, array $extraCandidates = []): string
    {
        $domain = strtolower(trim((string) $offer->domain));
        $bound = $this->boundCandidateIps($offer, $settings, $extraCandidates);

        if ($domain !== '' && $bound !== []) {
            $withFiles = $this->originsThatHaveOfferFiles($domain, $bound);

            foreach ($bound as $ip) {
                if (isset($withFiles[$ip])) {
                    return $ip;
                }
            }

            // Bound meta may be stale (e.g. CF switch once wrote Settings IP).
            // Prefer any other known origin that still has the lander.
            $discovered = $this->discoverOfferOriginIp($domain, $bound);
            if ($discovered !== null) {
                return $discovered;
            }
        } elseif ($domain !== '') {
            $discovered = $this->discoverOfferOriginIp($domain, []);
            if ($discovered !== null) {
                return $discovered;
            }
        }

        if ($bound !== []) {
            return $bound[0];
        }

        $fallback = trim((string) $settings->deploy_host);
        if ($fallback !== '' && $this->isLivePoolHost($fallback)) {
            return $this->originIp($settings);
        }

        throw new RuntimeException(
            'Немає живого origin-сервера в пулі для цього офера. Старий IP видалено — офер треба переприв’язати.',
        );
    }

    /**
     * @param  list<string>  $extraCandidates
     * @return list<string>
     */
    private function boundCandidateIps(Offer $offer, UserSetting $settings, array $extraCandidates = []): array
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $raw = [
            ...$extraCandidates,
            trim((string) ($meta['deploy_host'] ?? '')),
            trim((string) ($offer->deploy_panel_name ?? '')),
            trim((string) $settings->deploy_host),
        ];

        $ips = [];
        foreach ($raw as $host) {
            $host = trim((string) $host);
            if ($host === '') {
                continue;
            }
            if (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
                continue;
            }
            try {
                $ip = $this->resolveHostToIp($host, '');
            } catch (RuntimeException) {
                continue;
            }
            if (! $this->isLivePoolHost($ip)) {
                continue;
            }
            if (! in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * Scan live pool origins for lander files; skip IPs already checked.
     *
     * @param  list<string>  $alreadyChecked
     */
    private function discoverOfferOriginIp(string $domain, array $alreadyChecked): ?string
    {
        $hosts = [];
        foreach (OriginServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            if ($server->healthStatus() === 'down' || ! $server->hasSshCredentials()) {
                continue;
            }
            $host = trim((string) $server->host);
            if ($host === '' || in_array($host, $alreadyChecked, true)) {
                continue;
            }
            $hosts[$host] = [
                'host' => $host,
                'port' => (int) ($server->port ?: 22),
                'user' => trim((string) $server->username),
                'pass' => (string) $server->password,
            ];
        }

        foreach ($hosts as $host => $creds) {
            if ($this->remoteHasOfferIndex($creds, $domain)) {
                try {
                    return $this->resolveHostToIp($host, '');
                } catch (RuntimeException) {
                    return $host;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, true> ip => true
     */
    private function originsThatHaveOfferFiles(string $domain, array $ips): array
    {
        $found = [];
        foreach ($ips as $ip) {
            $creds = $this->sshCredsForHost($ip);
            if ($creds === null) {
                continue;
            }
            if ($this->remoteHasOfferIndex($creds, $domain)) {
                $found[$ip] = true;
            }
        }

        return $found;
    }

    /**
     * @return array{host: string, port: int, user: string, pass: string}|null
     */
    private function sshCredsForHost(string $host): ?array
    {
        $host = trim($host);
        if ($host === '') {
            return null;
        }

        $server = OriginServer::query()
            ->where('host', $host)
            ->first();

        if ($server && $server->is_active && $server->hasSshCredentials() && $server->healthStatus() !== 'down') {
            return [
                'host' => trim((string) $server->host),
                'port' => (int) ($server->port ?: 22),
                'user' => trim((string) $server->username),
                'pass' => (string) $server->password,
            ];
        }

        return null;
    }

    public function isLivePoolHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        $server = OriginServer::query()->where('host', $host)->first();

        return $server !== null
            && $server->is_active
            && $server->hasSshCredentials()
            && $server->healthStatus() !== 'down';
    }

    public function hostHasOfferIndex(string $host, string $domain): bool
    {
        $creds = $this->sshCredsForHost($host);
        if ($creds === null) {
            return false;
        }

        return $this->remoteHasOfferIndex($creds, $domain);
    }

    /**
     * @param  array{host: string, port: int, user: string, pass: string}  $creds
     */
    private function remoteHasOfferIndex(array $creds, string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        $path = '/var/www/offers/'.$domain.'/public_html';

        try {
            $out = trim(app(OriginSsh::class)->exec(
                $creds['host'],
                $creds['port'],
                $creds['user'],
                $creds['pass'],
                'if [ -f '.escapeshellarg($path.'/index.php').' ] || [ -f '.escapeshellarg($path.'/index.html').' ]; '
                .'then echo YES; else echo NO; fi',
                12,
            ));

            return str_starts_with($out, 'YES');
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveHostToIp(string $host, string $emptyMessage): string
    {
        if ($host === '') {
            throw new RuntimeException(
                $emptyMessage !== ''
                    ? $emptyMessage
                    : 'Немає host для A-запису.',
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $resolved = gethostbyname($host);

        if ($resolved === $host || ! filter_var($resolved, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Не вдалося визначити IP сервера для A-запису.');
        }

        return $resolved;
    }

    public function publicHtmlPath(UserSetting $settings, string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        $template = $settings->deploy_path_template ?: DeployDriver::defaultPath($settings->deploy_driver);
        $username = trim((string) $settings->deploy_username);

        return str_replace(
            ['{user}', '{domain}'],
            [$username, $domain],
            $template,
        );
    }

    public function offerRootPath(UserSetting $settings, string $domain): string
    {
        return dirname($this->publicHtmlPath($settings, $domain));
    }

    /**
     * @param  list<string>  $skipFiles
     */
    private function buildTar(string $localPath, string $archive, array $skipFiles): void
    {
        $command = ['tar', '-czf', $archive, '-C', $localPath];

        foreach ($skipFiles as $skip) {
            $skip = trim($skip);

            if ($skip !== '') {
                $command[] = '--exclude='.$skip;
            }
        }

        $command[] = '.';
        $line = implode(' ', array_map('escapeshellarg', $command));
        $output = [];
        $code = 0;
        exec($line.' 2>&1', $output, $code);

        if ($code !== 0 || ! is_file($archive) || filesize($archive) < 32) {
            throw new RuntimeException('Не вдалося зібрати tar.gz: '.trim(implode("\n", $output)));
        }
    }

    private function uploadFile(UserSetting $settings, string $localFile, string $remoteFile): void
    {
        [$host, $port, $username, $password] = $this->credentials($settings);
        app(OriginSsh::class)->upload($host, $port, $username, $password, $localFile, $remoteFile, 90);
    }

    /**
     * Upload a single local file into the offer public_html (relative path).
     */
    public function uploadOfferRelativeFile(UserSetting $settings, string $domain, string $localFile, string $relativeRemote): void
    {
        if (! is_file($localFile)) {
            throw new RuntimeException('Local file missing: '.$localFile);
        }

        $remoteRoot = $this->publicHtmlPath($settings, $domain);
        $relativeRemote = ltrim(str_replace('\\', '/', $relativeRemote), '/');
        $remoteFile = rtrim($remoteRoot, '/').'/'.$relativeRemote;
        $remoteDir = dirname($remoteFile);

        $this->exec(
            $settings,
            'mkdir -p '.$this->quote($remoteDir),
            30,
        );

        $this->uploadFile($settings, $localFile, $remoteFile);
        $this->exec(
            $settings,
            'chown www-data:www-data '.$this->quote($remoteFile).' && chmod 644 '.$this->quote($remoteFile),
            20,
        );
    }

    private function exec(UserSetting $settings, string $command, int $timeout = 20): string
    {
        [$host, $port, $username, $password] = $this->credentials($settings);
        $output = app(OriginSsh::class)->exec(
            $host,
            $port,
            $username,
            $password,
            $command.'; echo __EC__:$?',
            $timeout,
        );

        if (! preg_match('/__EC__:(\d+)/', $output, $match)) {
            throw new RuntimeException('SSH команда не повернула код виходу.');
        }

        if ((int) $match[1] !== 0) {
            throw new RuntimeException('SSH команда завершилась з помилкою: '.trim(str_replace($match[0], '', $output)));
        }

        return trim(str_replace($match[0], '', $output));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || ! preg_match('/^[a-z0-9.-]+$/', $domain)) {
            throw new RuntimeException('Некоректний домен для origin-сервера.');
        }

        return $domain;
    }

    /**
     * Minimal Alma/RHEL images ship without tar; deploy unpacks landers with tar -xzf.
     */
    private function ensureTarCommand(): string
    {
        return 'if ! command -v tar >/dev/null 2>&1; then '
            .'(command -v dnf >/dev/null 2>&1 && dnf -y install tar gzip) '
            .'|| (command -v yum >/dev/null 2>&1 && yum -y install tar gzip) '
            .'|| (command -v apt-get >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y tar gzip) '
            .'|| { echo tar-missing; exit 127; }; fi';
    }

    private function quote(string $path): string
    {
        return "'".str_replace("'", "'\\''", $path)."'";
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: string}
     */
    private function credentials(UserSetting $settings): array
    {
        $host = trim((string) $settings->deploy_host);
        $port = (int) ($settings->deploy_port ?: 22);
        $username = trim((string) $settings->deploy_username);
        $password = (string) ($settings->deploy_password ?? '');

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('Заповніть host, користувача і пароль SSH.');
        }

        return [$host, $port, $username, $password];
    }
}
