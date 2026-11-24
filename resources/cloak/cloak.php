<?php
/**
 * Origin cloak gate. Cloudflare and the self-hosted proxy both send
 * CF-Connecting-IP + CF-IPCountry, and origin nginx rewrites REMOTE_ADDR
 * from that header when the peer is trusted. This file does not care
 * which edge forwarded the request.
 *
 * Offer: Googlebot (official CIDRs) + matching GEO + panel IP.
 * Everyone else gets the white. Sitemap / robots / GSC HTML always pass.
 */
declare(strict_types=1);

if (! function_exists('offerra_cloak_root')) {
    function offerra_cloak_root(): string
    {
        $override = $GLOBALS['OFFERRA_CLOAK_ROOT'] ?? null;
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/\\');
        }

        return __DIR__;
    }

    function offerra_cloak_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    function offerra_cloak_visitor_ip(): string
    {
        // REMOTE_ADDR is already the visitor when nginx real_ip trusted the edge.
        // Do not prefer spoofable request headers over it.
        foreach (['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP'] as $key) {
            $raw = trim((string) ($_SERVER[$key] ?? ''));
            if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }

        return '';
    }

    function offerra_cloak_country(): string
    {
        $code = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
    }

    /** @return array{countries: list<string>, panel_ips: list<string>, entry: string} */
    function offerra_cloak_config(): array
    {
        $path = offerra_cloak_root().'/cloak.json';
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $countries = [];
        $panel = [];
        $entry = 'index.html';

        if (is_array($raw)) {
            foreach ((array) ($raw['countries'] ?? []) as $code) {
                $code = strtoupper(trim((string) $code));
                if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' && ! in_array($code, $countries, true)) {
                    $countries[] = $code;
                }
            }
            foreach ((array) ($raw['panel_ips'] ?? []) as $ip) {
                $ip = trim((string) $ip);
                if (filter_var($ip, FILTER_VALIDATE_IP) && ! in_array($ip, $panel, true)) {
                    $panel[] = $ip;
                }
            }
            $entryFile = trim((string) ($raw['entry'] ?? ''));
            if ($entryFile !== '' && ! str_contains($entryFile, '..')) {
                $entry = $entryFile;
            }
        }

        return [
            'countries' => $countries,
            'panel_ips' => $panel,
            'entry' => $entry,
        ];
    }

    function offerra_cloak_ip_in_cidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = isset($parts[1]) ? (int) $parts[1] : $maxBits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remain = $bits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
            return false;
        }
        if ($remain === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remain)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subBin[$fullBytes]) & $mask);
    }

    /** @param list<string> $ips */
    function offerra_cloak_ip_in_list(string $ip, array $ips): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach ($ips as $allowed) {
            if ($ip === $allowed || offerra_cloak_ip_in_cidr($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    function offerra_cloak_is_googlebot(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $path = offerra_cloak_root().'/googlebot.json';
        if (! is_file($path)) {
            return false;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return false;
        }

        foreach ((array) ($raw['prefixes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cidr = (string) ($row['ipv4Prefix'] ?? $row['ipv6Prefix'] ?? '');
            if ($cidr !== '' && offerra_cloak_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    function offerra_cloak_is_passthrough(string $path): bool
    {
        $path = '/'.ltrim(strtolower($path), '/');

        if (str_starts_with($path, '/white/') || $path === '/white') {
            return true;
        }
        if (str_starts_with($path, '/.well-known/')) {
            return true;
        }
        if (preg_match('#^/(sitemap|robots)\.(xml|txt|php)$#', $path)) {
            return true;
        }

        return (bool) preg_match('#^/google[a-z0-9]+\.html$#', $path);
    }

    function offerra_cloak_allows(): bool
    {
        $path = offerra_cloak_path();
        if (offerra_cloak_is_passthrough($path)) {
            return true;
        }

        $ip = offerra_cloak_visitor_ip();
        $cfg = offerra_cloak_config();

        if (offerra_cloak_ip_in_list($ip, $cfg['panel_ips'])) {
            return true;
        }
        if (offerra_cloak_is_googlebot($ip)) {
            return true;
        }

        $country = offerra_cloak_country();
        $countries = $cfg['countries'];
        if ($countries === []) {
            return $country !== '' && $country !== 'XX';
        }

        return in_array($country, $countries, true);
    }

    function offerra_cloak_serve_white(): void
    {
        $cfg = offerra_cloak_config();
        $white = offerra_cloak_root().'/white';
        $file = $white.'/'.ltrim($cfg['entry'], '/');

        header('X-Robots-Tag: noindex, nofollow');
        header('Vary: CF-IPCountry');
        header('Cache-Control: private, no-store');

        if (! is_file($file)) {
            http_response_code(404);
            echo 'Not Found';
            exit;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php' || $ext === 'phtml') {
            chdir($white);
            require $file;
            exit;
        }

        $html = (string) file_get_contents($file);
        if (! preg_match('/<base\s/i', $html)) {
            $html = preg_replace('/<head([^>]*)>/i', '<head$1><base href="/white/">', $html, 1) ?? $html;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    function offerra_cloak_boot(): void
    {
        if (defined('OFFERRA_CLOAK_DONE')) {
            return;
        }
        define('OFFERRA_CLOAK_DONE', true);

        if (offerra_cloak_allows()) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (! in_array($method, ['GET', 'HEAD', ''], true)) {
            http_response_code(204);
            exit;
        }

        offerra_cloak_serve_white();
    }
}

if (! defined('OFFERRA_CLOAK_TEST')) {
    offerra_cloak_boot();
}
