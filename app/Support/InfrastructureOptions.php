<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\UserSetting;

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

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            ...array_fill_keys(self::META_KEYS, true),
            'edge' => self::EDGE_CLOUDFLARE,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
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

        $options = ['edge' => self::EDGE_CLOUDFLARE];

        foreach ($map as $inputKey => $metaKey) {
            $options[$metaKey] = (bool) ($input[$inputKey] ?? false);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromSettingsAndInput(?UserSetting $settings, array $input): array
    {
        return self::fromInput($input);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPreferredEdge(string $edge = self::EDGE_CLOUDFLARE): array
    {
        return self::defaults();
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
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

        $options['edge'] = self::EDGE_CLOUDFLARE;

        return $options;
    }

    public static function forOffer(Offer $offer): array
    {
        if (! $offer->provision_infrastructure) {
            return [
                ...array_fill_keys(self::META_KEYS, false),
                'edge' => self::EDGE_CLOUDFLARE,
            ];
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        if ($meta === [] || ! isset($meta['options'])) {
            return self::defaults();
        }

        return self::fromMeta($meta);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function anyEnabled(array $options): bool
    {
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
    public static function needsCloudflareZone(array $options): bool
    {
        return ($options['cloudflare_zone'] ?? false)
            || ($options['cloudflare_dns'] ?? false)
            || ($options['cloudflare_ssl'] ?? false)
            || ($options['cloudflare_https'] ?? false)
            || ($options['cloudflare_www_redirect'] ?? false)
            || ($options['dynadot_ns'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function needsDnsWait(array $options): bool
    {
        return ($options['cloudflare_zone'] ?? false)
            || ($options['dynadot_ns'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function needsCloudflareEdge(array $options): bool
    {
        return ($options['cloudflare_ssl'] ?? false)
            || ($options['cloudflare_https'] ?? false)
            || ($options['cloudflare_www_redirect'] ?? false);
    }
}
