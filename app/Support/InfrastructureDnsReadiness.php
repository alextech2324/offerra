<?php

namespace App\Support;

/**
 * Public DNS is ready when live NS match the expected pair.
 * Another Cloudflare pair is the previous account — not a cutover.
 */
final class InfrastructureDnsReadiness
{
    /**
     * @param  list<string>  $liveNs
     * @param  list<string>  $expectedNs
     */
    public static function nameserversAcceptable(array $liveNs, array $expectedNs): bool
    {
        $live = self::normalize($liveNs);
        $expected = self::normalize($expectedNs);

        if ($expected === []) {
            return true;
        }

        if ($live === []) {
            return false;
        }

        $liveSet = array_fill_keys($live, true);
        $matched = 0;
        foreach ($expected as $ns) {
            if (isset($liveSet[$ns])) {
                $matched++;
            }
        }

        // Account switch assigns a new CF NS pair. Another Cloudflare pair
        // means the old zone is still answering — cutover is not done.
        return $matched >= min(2, count($expected));
    }

    public static function isCloudflareNameserver(string $ns): bool
    {
        $ns = strtolower(rtrim(trim($ns), '.'));

        return str_ends_with($ns, '.ns.cloudflare.com');
    }

    /**
     * Orange-cloud anycast. Broad prefixes on purpose — enough to drop
     * parking / old-origin A that a recursive cache still serves.
     */
    public static function isCloudflareProxyIp(string $ip): bool
    {
        $ip = trim($ip);

        return str_starts_with($ip, '104.')
            || str_starts_with($ip, '172.64.')
            || str_starts_with($ip, '172.65.')
            || str_starts_with($ip, '172.66.')
            || str_starts_with($ip, '172.67.')
            || str_starts_with($ip, '188.114.');
    }

    /**
     * When resolvers disagree, keep CF proxy IPs and drop stale parking/origin.
     * Grey-cloud origin-only answers stay as-is.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function preferCloudflareProxyIps(array $ips): array
    {
        $valid = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $valid[] = $ip;
            }
        }
        $valid = array_values(array_unique($valid));

        $proxy = array_values(array_filter(
            $valid,
            static fn (string $ip): bool => self::isCloudflareProxyIp($ip),
        ));

        return $proxy !== [] ? $proxy : $valid;
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function normalize(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $ns = strtolower(rtrim(trim((string) $host), '.'));
            if ($ns !== '') {
                $out[] = $ns;
            }
        }

        return array_values(array_unique($out));
    }
}
