<?php

namespace App\Support;

use App\Models\Offer;

class InfrastructureOptions
{
    public const INPUT_KEYS = [
        'infra_cloudflare_zone',
        'infra_cloudflare_dns',
        'infra_dynadot_ns',
        'infra_cloudflare_ssl',
        'infra_cloudflare_https',
        'infra_cloudflare_www_redirect',
    ];

    public const META_KEYS = [
        'cloudflare_zone',
        'cloudflare_dns',
        'dynadot_ns',
        'cloudflare_ssl',
        'cloudflare_https',
        'cloudflare_www_redirect',
    ];

    public const EDGE_CLOUDFLARE = 'cloudflare';

    public const EDGE_PROXY = 'proxy';

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return array_fill_keys(self::META_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function fromInput(array $input): array
    {
        $map = [
            'infra_cloudflare_zone' => 'cloudflare_zone',
            'infra_cloudflare_dns' => 'cloudflare_dns',
            'infra_dynadot_ns' => 'dynadot_ns',
            'infra_cloudflare_ssl' => 'cloudflare_ssl',
            'infra_cloudflare_https' => 'cloudflare_https',
            'infra_cloudflare_www_redirect' => 'cloudflare_www_redirect',
        ];

        $options = [];

        foreach ($map as $inputKey => $metaKey) {
            $options[$metaKey] = (bool) ($input[$inputKey] ?? false);
        }

        $edgeRaw = trim((string) ($input['infra_edge'] ?? self::EDGE_CLOUDFLARE));
        if (preg_match('/^proxy:(\d+)$/', $edgeRaw, $m) && (int) $m[1] > 0) {
            $options['edge'] = self::EDGE_PROXY;
            $options['edge_proxy_id'] = (int) $m[1];
            $options['cloudflare_zone'] = true;
            $options['cloudflare_dns'] = false;
            $options['dynadot_ns'] = true;
            $options['cloudflare_ssl'] = false;
            $options['cloudflare_https'] = false;
            $options['cloudflare_www_redirect'] = false;
        } else {
            $options['edge'] = self::EDGE_CLOUDFLARE;
            $options['edge_proxy_id'] = 0;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, bool>
     */
    public static function fromMeta(?array $meta): array
    {
        $stored = is_array($meta['options'] ?? null) ? $meta['options'] : [];
        $options = self::defaults();

        foreach (self::META_KEYS as $key) {
            if (array_key_exists($key, $stored)) {
                $options[$key] = (bool) $stored[$key];
            }
        }

        $edge = (string) ($stored['edge'] ?? self::EDGE_CLOUDFLARE);
        $options['edge'] = $edge === self::EDGE_PROXY ? self::EDGE_PROXY : self::EDGE_CLOUDFLARE;
        $options['edge_proxy_id'] = (int) ($stored['edge_proxy_id'] ?? 0);

        return $options;
    }

    public static function forOffer(Offer $offer): array
    {
        if (! $offer->provision_infrastructure) {
            return array_fill_keys(self::META_KEYS, false);
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        if ($meta === [] || ! isset($meta['options'])) {
            return self::defaults();
        }

        return self::fromMeta($meta);
    }

    /**
     * @param  array<string, bool>  $options
     */
    public static function anyEnabled(array $options): bool
    {
        if (self::wantsProxy($options)) {
            return true;
        }

        foreach (self::META_KEYS as $key) {
            if ($options[$key] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function wantsProxy(array $options): bool
    {
        return ($options['edge'] ?? '') === self::EDGE_PROXY
            && (int) ($options['edge_proxy_id'] ?? 0) > 0;
    }

    /**
     * @param  array<string, bool>  $options
     */
    public static function needsCloudflareZone(array $options): bool
    {
        return self::wantsProxy($options)
            || ($options['cloudflare_zone'] ?? false)
            || ($options['cloudflare_dns'] ?? false)
            || ($options['cloudflare_ssl'] ?? false)
            || ($options['cloudflare_https'] ?? false)
            || ($options['cloudflare_www_redirect'] ?? false)
            || ($options['dynadot_ns'] ?? false);
    }

    /**
     * @param  array<string, bool>  $options
     */
    public static function needsDnsWait(array $options): bool
    {
        return self::wantsProxy($options)
            || ($options['cloudflare_zone'] ?? false)
            || ($options['dynadot_ns'] ?? false);
    }

    /**
     * @param  array<string, bool>  $options
     */
    public static function needsCloudflareEdge(array $options): bool
    {
        return ($options['cloudflare_ssl'] ?? false)
            || ($options['cloudflare_https'] ?? false)
            || ($options['cloudflare_www_redirect'] ?? false);
    }
}
