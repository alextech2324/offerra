<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EgoNewBrandAlertService
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
    ) {}

    public function maybeNotify(Offer $offer): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $offer->loadMissing('user');

        if (! $this->isEgoUser($offer->user)) {
            return false;
        }

        if (! $this->isFirstAppearanceOfBrand($offer)) {
            return false;
        }

        return $this->sendBrand(
            (string) $offer->brand,
            [[
                'geo' => (string) $offer->geo,
                'lang' => (string) $offer->lang,
            ]],
        );
    }

    /**
     * Brands EGO created in the window that never existed on this panel before.
     *
     * @return array{sent: int, skipped: int, brands: list<string>}
     */
    public function notifyNewSince(Carbon $since): array
    {
        if (! $this->configured()) {
            return ['sent' => 0, 'skipped' => 0, 'brands' => []];
        }

        $ego = $this->egoUser();
        if ($ego === null) {
            Log::warning('EGO new-brand alert: user not found', [
                'name' => $this->egoUserName(),
            ]);

            return ['sent' => 0, 'skipped' => 0, 'brands' => []];
        }

        $offers = Offer::query()
            ->where('user_id', $ego->id)
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get(['id', 'brand', 'geo', 'lang', 'created_at']);

        $byBrand = [];
        foreach ($offers as $offer) {
            $key = $this->brandKey((string) $offer->brand);
            if ($key === '') {
                continue;
            }
            $byBrand[$key]['label'] = (string) $offer->brand;
            $byBrand[$key]['rows'][] = $offer;
        }

        $sent = 0;
        $skipped = 0;
        $brands = [];

        foreach ($byBrand as $key => $group) {
            if ($this->brandExistedBefore($key, $since)) {
                $skipped++;
                continue;
            }

            $combos = [];
            $seen = [];
            foreach ($group['rows'] as $row) {
                $comboKey = strtoupper(trim((string) $row->geo)).'|'.strtolower(trim((string) $row->lang));
                if (isset($seen[$comboKey])) {
                    continue;
                }
                $seen[$comboKey] = true;
                $combos[] = [
                    'geo' => (string) $row->geo,
                    'lang' => (string) $row->lang,
                ];
            }

            if ($this->sendBrand((string) $group['label'], $combos)) {
                $sent++;
                $brands[] = (string) $group['label'];
            } else {
                $skipped++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'brands' => $brands];
    }

    public function configured(): bool
    {
        return $this->botToken() !== '' && $this->chatId() !== '';
    }

    /**
     * @param  list<array{geo: string, lang: string}>  $combos
     */
    public function sendBrand(string $brand, array $combos): bool
    {
        $token = $this->botToken();
        $chatId = $this->chatId();
        if ($token === '' || $chatId === '' || $brand === '' || $combos === []) {
            return false;
        }

        $safeBrand = htmlspecialchars($brand, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [
            '🆕 Новий бренд EGO',
            '',
            "Brand: <b>{$safeBrand}</b>",
        ];

        foreach ($combos as $combo) {
            $geo = htmlspecialchars(strtoupper(trim($combo['geo'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lang = htmlspecialchars(strtolower(trim($combo['lang'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = "GEO: <code>{$geo}</code> · lang: <code>{$lang}</code>";
        }

        $result = $this->telegram->sendRawResult($token, [$chatId], implode("\n", $lines), 'HTML');

        if (! ($result['ok'] ?? false)) {
            Log::warning('EGO new-brand telegram failed', [
                'brand' => $brand,
                'error' => $result['error'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    public function isEgoUser(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return mb_strtolower(trim((string) $user->name)) === mb_strtolower($this->egoUserName());
    }

    private function isFirstAppearanceOfBrand(Offer $offer): bool
    {
        $key = $this->brandKey((string) $offer->brand);
        if ($key === '') {
            return false;
        }

        return ! Offer::query()
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$key])
            ->where('id', '!=', $offer->id)
            ->exists();
    }

    private function brandExistedBefore(string $brandKey, Carbon $since): bool
    {
        return Offer::query()
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$brandKey])
            ->where('created_at', '<', $since)
            ->exists();
    }

    private function egoUser(): ?User
    {
        $name = $this->egoUserName();

        return User::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function egoUserName(): string
    {
        $name = trim((string) config('offerra.ego_brand_alert.user', 'EGO'));

        return $name !== '' ? $name : 'EGO';
    }

    private function botToken(): string
    {
        return trim((string) config('offerra.ego_brand_alert.bot_token', ''));
    }

    private function chatId(): string
    {
        return trim((string) config('offerra.ego_brand_alert.chat_id', ''));
    }

    private function brandKey(string $brand): string
    {
        return mb_strtolower(trim($brand));
    }
}
