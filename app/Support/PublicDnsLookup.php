<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * What the public internet sees — not the panel's recursive cache.
 *
 * php dns_get_record() uses glibc / systemd-resolved, which can keep a
 * dead NS pair after a Cloudflare cutover. Asking 1.1.1.1 / 8.8.8.8 is
 * better, but a single recursive cache can itself be stale (old parking
 * A, empty NS) while the other resolver already has the live zone.
 * Query both and prefer the answer that still matches Cloudflare.
 */
class PublicDnsLookup
{
    /** @var list<string> */
    private const ENDPOINTS = [
        'https://cloudflare-dns.com/dns-query',
        'https://dns.google/resolve',
    ];

    /**
     * @var array<string, array{ns: list<string>, a: list<string>, sawPublic: bool}>
     */
    private array $resolved = [];

    /**
     * @return list<string>
     */
    public function nameservers(string $domain): array
    {
        $resolved = $this->resolve($domain);
        if ($resolved['ns'] !== [] || $resolved['sawPublic']) {
            return $resolved['ns'];
        }

        return $this->localNs($domain);
    }

    /**
     * @return list<string>
     */
    public function txtRecords(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '') {
            return [];
        }

        $out = [];
        foreach (self::ENDPOINTS as $url) {
            $fetch = $this->fetch($url, $domain, 'TXT');
            if ($fetch === null) {
                continue;
            }

            foreach ($fetch['records'] as $row) {
                $clean = strtolower(trim((string) $row, " \t\n\r\""));
                if ($clean !== '') {
                    $out[] = $clean;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function aRecords(string $domain): array
    {
        $resolved = $this->resolve($domain);
        if ($resolved['a'] !== [] || $resolved['sawPublic']) {
            return $resolved['a'];
        }

        return InfrastructureDnsReadiness::preferCloudflareProxyIps($this->localA($domain));
    }

    /**
     * @return array{ns: list<string>, a: list<string>, sawPublic: bool}
     */
    private function resolve(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['ns' => [], 'a' => [], 'sawPublic' => true];
        }

        if (isset($this->resolved[$domain])) {
            return $this->resolved[$domain];
        }

        $candidates = [];
        $sawPublic = false;
        foreach (self::ENDPOINTS as $url) {
            $nsFetch = $this->fetch($url, $domain, 'NS');
            $aFetch = $this->fetch($url, $domain, 'A');
            if ($nsFetch === null && $aFetch === null) {
                continue;
            }

            $sawPublic = true;
            $candidates[] = [
                'ns' => $this->normalizeNs($nsFetch['records'] ?? []),
                'a' => $this->normalizeA($aFetch['records'] ?? []),
            ];
        }

        $picked = $this->pickCandidate($candidates);

        return $this->resolved[$domain] = [
            'ns' => $picked['ns'],
            'a' => InfrastructureDnsReadiness::preferCloudflareProxyIps($picked['a']),
            'sawPublic' => $sawPublic,
        ];
    }

    /**
     * @param  list<array{ns: list<string>, a: list<string>}>  $candidates
     * @return array{ns: list<string>, a: list<string>}
     */
    private function pickCandidate(array $candidates): array
    {
        if ($candidates === []) {
            return ['ns' => [], 'a' => []];
        }

        $best = $candidates[0];
        $bestScore = -1;
        $allA = [];
        $cfNsFingerprints = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate['a'] as $ip) {
                $allA[] = $ip;
            }
            $fp = $this->cloudflareNsFingerprint($candidate['ns']);
            if ($fp !== null) {
                $cfNsFingerprints[$fp] = true;
            }

            $score = 0;
            if ($candidate['ns'] !== []) {
                $score += 10;
                foreach ($candidate['ns'] as $ns) {
                    if (InfrastructureDnsReadiness::isCloudflareNameserver($ns)) {
                        $score += 5;
                        break;
                    }
                }
            }
            $proxy = false;
            foreach ($candidate['a'] as $ip) {
                if (InfrastructureDnsReadiness::isCloudflareProxyIp($ip)) {
                    $proxy = true;
                    break;
                }
            }
            if ($proxy) {
                $score += 20;
            } elseif ($candidate['a'] !== []) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        $mergedA = $allA !== [] ? $allA : $best['a'];

        // Cloudflare DoH often keeps the previous account's NS pair after a
        // zone cutover while Google already has the new pair. Both score the
        // same (CF NS + proxy A), so the first endpoint would win and the
        // panel would keep «Очікується DNS» / probe the deleted zone.
        if (count($cfNsFingerprints) > 1) {
            for ($i = count($candidates) - 1; $i >= 0; $i--) {
                if ($this->cloudflareNsFingerprint($candidates[$i]['ns']) !== null) {
                    return [
                        'ns' => $candidates[$i]['ns'],
                        'a' => $mergedA,
                    ];
                }
            }
        }

        return [
            'ns' => $best['ns'],
            'a' => $mergedA,
        ];
    }

    /**
     * @param  list<string>  $ns
     */
    private function cloudflareNsFingerprint(array $ns): ?string
    {
        $cf = [];
        foreach ($ns as $name) {
            if (InfrastructureDnsReadiness::isCloudflareNameserver($name)) {
                $cf[] = strtolower(rtrim(trim($name), '.'));
            }
        }

        if ($cf === []) {
            return null;
        }

        sort($cf);

        return implode(' ', $cf);
    }

    /**
     * @return array{records: list<string>}|null null = transport failure
     */
    private function fetch(string $url, string $domain, string $type): ?array
    {
        try {
            $response = Http::timeout(4)
                ->accept('application/dns-json')
                ->get($url, [
                    'name' => $domain,
                    'type' => $type,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || ! isset($json['Status'])) {
                return null;
            }

            $status = (int) $json['Status'];
            // 0 NOERROR, 3 NXDOMAIN — both are answers.
            if ($status !== 0 && $status !== 3) {
                return null;
            }

            $answers = $json['Answer'] ?? [];
            if (! is_array($answers)) {
                return ['records' => []];
            }

            $out = [];
            foreach ($answers as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $data = trim((string) ($row['data'] ?? ''));
                if ($data !== '') {
                    $out[] = $data;
                }
            }

            return ['records' => $out];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function normalizeNs(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $ns = strtolower(rtrim(trim((string) $value), '.'));
            if ($ns !== '') {
                $out[] = $ns;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function normalizeA(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $ip = trim((string) $value);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $out[] = $ip;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function localNs(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);
        if (! is_array($records) || $records === []) {
            return [];
        }

        return $this->normalizeNs(array_map(
            static fn (array $record) => (string) ($record['target'] ?? ''),
            $records,
        ));
    }

    /**
     * @return list<string>
     */
    private function localA(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A);
        if (! is_array($records) || $records === []) {
            return [];
        }

        return $this->normalizeA(array_map(
            static fn (array $record) => (string) ($record['ip'] ?? ''),
            $records,
        ));
    }
}
