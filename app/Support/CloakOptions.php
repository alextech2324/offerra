<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\User;
use App\Models\WhiteLanding;
use InvalidArgumentException;

class CloakOptions
{
    /**
     * Same rule on every cloaked offer: Googlebot IPs and the offer GEO
     * see the lander; everyone else gets the white. Not per-offer checkboxes.
     *
     * @var list<string>
     */
    public const DEFAULT_FILTERS = ['googlebot', 'geo'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, white_landing_id: int|null}
     */
    public static function fromInput(array $input): array
    {
        $enabled = filter_var($input['cloak_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $whiteId = (int) ($input['white_landing_id'] ?? 0);

        if (! $enabled || $whiteId <= 0) {
            return [
                'enabled' => false,
                'white_landing_id' => null,
            ];
        }

        return [
            'enabled' => true,
            'white_landing_id' => $whiteId,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, white_landing_id: int|null}
     */
    public static function resolveForUser(User $user, array $input): array
    {
        $cloak = self::fromInput($input);

        if (! $cloak['enabled']) {
            return $cloak;
        }

        $owned = WhiteLanding::query()
            ->where('user_id', $user->id)
            ->whereKey($cloak['white_landing_id'])
            ->exists();

        if (! $owned) {
            throw new InvalidArgumentException('Оберіть вайт зі своїх лендінгів.');
        }

        return $cloak;
    }

    /**
     * @param  array<string, mixed>|null  $infraMeta
     * @param  array{enabled: bool, white_landing_id: int|null}  $cloak
     * @return array<string, mixed>|null
     */
    public static function mergeIntoInfraMeta(?array $infraMeta, array $cloak): ?array
    {
        if (! ($cloak['enabled'] ?? false) || empty($cloak['white_landing_id'])) {
            if (is_array($infraMeta)) {
                unset($infraMeta['cloak']);
            }

            return $infraMeta === [] ? null : $infraMeta;
        }

        $infraMeta ??= [];
        $infraMeta['cloak'] = [
            'enabled' => true,
            'white_landing_id' => (int) $cloak['white_landing_id'],
            'filters' => self::DEFAULT_FILTERS,
        ];

        return $infraMeta;
    }

    /**
     * @return array{enabled: bool, white_landing_id: int|null}
     */
    public static function fromOffer(Offer $offer): array
    {
        $whiteId = (int) ($offer->white_landing_id ?? 0);

        if (! $offer->cloak_enabled || $whiteId <= 0) {
            return [
                'enabled' => false,
                'white_landing_id' => null,
            ];
        }

        return [
            'enabled' => true,
            'white_landing_id' => $whiteId,
        ];
    }
}
