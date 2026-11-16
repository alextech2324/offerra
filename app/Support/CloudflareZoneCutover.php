<?php

namespace App\Support;

/**
 * Cloudflare account switch is two clocks: the new zone can be ready while
 * the registrar still points at the previous NS pair. Retiring the old zone
 * or treating Dynadot set_ns as done before that cutover leaves a live
 * nameserver pair with no A records.
 */
final class CloudflareZoneCutover
{
    /**
     * @param  list<string>  $liveNs
     * @param  list<string>  $expectedNs
     */
    public static function registrarNsMatch(array $liveNs, array $expectedNs): bool
    {
        return InfrastructureDnsReadiness::nameserversAcceptable($liveNs, $expectedNs);
    }

    /**
     * Ask Dynadot again when public NS are still the previous Cloudflare pair,
     * even if a earlier set_ns call returned success.
     *
     * @param  list<string>  $liveNs
     * @param  list<string>  $expectedNs
     */
    public static function shouldRetrySetNs(array $liveNs, array $expectedNs): bool
    {
        if ($expectedNs === []) {
            return false;
        }

        return ! self::registrarNsMatch($liveNs, $expectedNs);
    }

    /**
     * @param  list<string>  $liveNs
     * @param  list<string>  $expectedNs
     */
    public static function shouldDeleteOldZone(array $liveNs, array $expectedNs): bool
    {
        return self::registrarNsMatch($liveNs, $expectedNs);
    }

    public static function slotFromMeta(array $meta): string
    {
        return match ((string) ($meta['cloudflare_slot'] ?? '')) {
            'backup' => 'backup',
            'spare' => 'spare',
            default => 'primary',
        };
    }

    /**
     * First throw waits on registry publication. The hold hint is only useful
     * after a Cloudflare account switch, when Dynadot already has the new NS
     * but the old zone is gone and the registry still serves NXDOMAIN.
     */
    public static function isAccountSwitch(array $meta): bool
    {
        return isset($meta['cloudflare_old_pool_id'])
            || isset($meta['cloudflare_old_zone_delete'])
            || filled($meta['cloudflare_old_zone_id'] ?? null);
    }

    public static function nxdomainWaitError(array $meta): ?string
    {
        if (! self::isAccountSwitch($meta)) {
            return null;
        }

        return 'Реєстр не публікує домен (NXDOMAIN). NS у Dynadot уже Cloudflare — часто server hold, API його не знімає.';
    }
}
