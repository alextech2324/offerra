<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OriginHealthMonitor
{
    /** Keep counting consecutive fails for UI/debug; alerts use time below. */
    public const FAIL_STREAK_ALERT = 3;

    public const DEGRADED_STREAK_ALERT = 2;

    /** Only alert after the origin stays down/degraded this long (ignores short blips). */
    public const DOWN_ALERT_AFTER_MINUTES = 3;

    public const DEGRADED_ALERT_AFTER_MINUTES = 3;

    public function __construct(
        private readonly OriginHealthProbe $probe,
        private readonly TelegramNotifier $telegram,
        private readonly OriginServerSync $sync,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(UserSetting $settings, bool $alert = true, ?string $password = null): array
    {
        $result = $this->probe->probe($settings, $password);

        return $this->applyToSettings($settings, $result, $alert);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkServer(OriginServer $server, bool $alert = true, ?string $password = null): array
    {
        if ($server->hasSshCredentials() || filled($password)) {
            $result = $this->probe->probeServer($server, $password);
        } else {
            $result = $this->probe->probeHttpOnly((string) $server->host);
        }

        return $this->applyToServer($server, $result, $alert);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function applyToSettings(UserSetting $settings, array $result, bool $alert = true): array
    {
        $health = $this->buildHealthState(
            is_array($settings->origin_health) ? $settings->origin_health : [],
            $result,
            $alert,
        );

        $alertKind = $health['_alert_kind'] ?? null;
        unset($health['_alert_kind']);

        if ($alertKind !== null && $this->shouldSendAlertsForSettings($settings)) {
            $this->telegram->send($settings, $this->alertTextForSettings($settings, $result, $alertKind));
            $health['last_alert_at'] = now()->toIso8601String();
            $health['last_alert_kind'] = $alertKind;
        }

        $settings->origin_health = $health;
        $settings->save();

        $result['origin_health'] = $settings->originHealthForPanel();
        $result['alert'] = $alertKind;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function applyToServer(OriginServer $server, array $result, bool $alert = true): array
    {
        $health = $this->buildHealthState(
            is_array($server->health) ? $server->health : [],
            $result,
            $alert,
        );

        $alertKind = $health['_alert_kind'] ?? null;
        unset($health['_alert_kind']);

        if ($alertKind !== null && $alert && ($server->alerts_enabled ?? true)) {
            $this->notifyServerAlert($server, $result, $alertKind);
            $health['last_alert_at'] = now()->toIso8601String();
            $health['last_alert_kind'] = $alertKind;
        }

        $server->health = $health;
        $server->save();

        // Mirror into matching user settings (default deploy host) for Settings UI.
        $this->mirrorHealthToMatchingSettings($server, $health);

        $result['origin_health'] = $server->healthForPanel();
        $result['alert'] = $alertKind;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildHealthState(array $previous, array $result, bool $alert): array
    {
        $prevStatus = (string) ($previous['status'] ?? 'unchecked');
        $failStreak = (int) ($previous['fail_streak'] ?? 0);
        $degradedStreak = (int) ($previous['degraded_streak'] ?? 0);
        $status = (string) ($result['status'] ?? 'down');
        $now = now();

        $downSince = null;
        $degradedSince = null;

        if ($status === 'down') {
            $failStreak++;
            $degradedStreak = 0;
            $downSince = $this->unresolvedSince($previous, $prevStatus, 'down', $now);
        } elseif ($status === 'degraded') {
            $degradedStreak++;
            $failStreak = 0;
            $degradedSince = $this->unresolvedSince($previous, $prevStatus, 'degraded', $now);
        } else {
            $failStreak = 0;
            $degradedStreak = 0;
        }

        $alertKind = $alert
            ? $this->decideAlert(
                $prevStatus,
                $status,
                $failStreak,
                $degradedStreak,
                $previous,
                $now,
                $downSince,
                $degradedSince,
            )
            : null;

        $autoEvac = $status === 'down'
            ? [
                'auto_evac_confirm_fails' => (int) ($previous['auto_evac_confirm_fails'] ?? 0),
                'auto_evac_last_confirm_at' => $previous['auto_evac_last_confirm_at'] ?? null,
                'auto_evac_at' => $previous['auto_evac_at'] ?? null,
                'auto_evac_error' => $previous['auto_evac_error'] ?? null,
            ]
            : [
                'auto_evac_confirm_fails' => 0,
                'auto_evac_last_confirm_at' => null,
                'auto_evac_at' => null,
                'auto_evac_error' => null,
            ];

        return [
            'status' => $status,
            'checked_at' => $now->toIso8601String(),
            'fail_streak' => $failStreak,
            'degraded_streak' => $degradedStreak,
            'down_since' => $downSince?->toIso8601String(),
            'degraded_since' => $degradedSince?->toIso8601String(),
            'message' => $result['message'] ?? null,
            'metrics' => $this->panelMetrics($result),
            'last_alert_at' => $previous['last_alert_at'] ?? null,
            'last_alert_kind' => $previous['last_alert_kind'] ?? null,
            ...$autoEvac,
            '_alert_kind' => $alertKind,
        ];
    }

    /**
     * First moment the current bad status started (sticky across checks).
     *
     * @param  array<string, mixed>  $previous
     */
    private function unresolvedSince(array $previous, string $prevStatus, string $status, Carbon $now): Carbon
    {
        $key = $status === 'down' ? 'down_since' : 'degraded_since';

        if (! empty($previous[$key])) {
            return Carbon::parse((string) $previous[$key]);
        }

        // Already in this status but without a stamp (legacy health rows).
        if ($prevStatus === $status && ! empty($previous['checked_at'])) {
            return Carbon::parse((string) $previous['checked_at']);
        }

        return $now->copy();
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    public function decideAlert(
        string $prevStatus,
        string $status,
        int $failStreak,
        int $degradedStreak,
        array $previous,
        ?Carbon $now = null,
        ?Carbon $downSince = null,
        ?Carbon $degradedSince = null,
    ): ?string {
        $now ??= now();
        $lastAlertKind = (string) ($previous['last_alert_kind'] ?? '');

        if ($status === 'ok' && in_array($prevStatus, ['down', 'degraded'], true)) {
            if (in_array($lastAlertKind, ['down', 'degraded'], true)) {
                return 'recovery';
            }

            return null;
        }

        // One Telegram per incident. Repeats stay silent until recovery, then a new outage.
        if ($status === 'down' && $lastAlertKind === 'down') {
            return null;
        }

        if ($status === 'degraded' && in_array($lastAlertKind, ['degraded', 'down'], true)) {
            return null;
        }

        if ($status === 'down') {
            $since = $downSince ?? (! empty($previous['down_since']) ? Carbon::parse((string) $previous['down_since']) : null);
            if ($since !== null && $since->lte($now->copy()->subMinutes(self::DOWN_ALERT_AFTER_MINUTES))) {
                return 'down';
            }

            // Fallback for callers/tests that only pass fail_streak (legacy).
            if ($since === null && $failStreak >= self::FAIL_STREAK_ALERT) {
                return 'down';
            }
        }

        if ($status === 'degraded') {
            $since = $degradedSince ?? (! empty($previous['degraded_since']) ? Carbon::parse((string) $previous['degraded_since']) : null);
            if ($since !== null && $since->lte($now->copy()->subMinutes(self::DEGRADED_ALERT_AFTER_MINUTES))) {
                return 'degraded';
            }

            if ($since === null && $degradedStreak >= self::DEGRADED_STREAK_ALERT) {
                return 'degraded';
            }
        }

        return null;
    }

    /**
     * Keep Settings UI health in sync when host matches.
     *
     * @param  array<string, mixed>  $health
     */
    private function mirrorHealthToMatchingSettings(OriginServer $server, array $health): void
    {
        $host = $this->sync->normalizeHost((string) $server->host);

        UserSetting::query()
            ->whereNotNull('deploy_host')
            ->where('deploy_host', '!=', '')
            ->get()
            ->each(function (UserSetting $settings) use ($host, $health): void {
                if ($this->sync->normalizeHost((string) $settings->deploy_host) !== $host) {
                    return;
                }
                $settings->origin_health = $health;
                $settings->save();
            });
    }

    private function shouldSendAlertsForSettings(UserSetting $settings): bool
    {
        if ($settings->origin_health_alerts === false) {
            return false;
        }

        return $this->settingsHasTelegram($settings);
    }

    private function settingsHasTelegram(UserSetting $settings): bool
    {
        if (! filled($settings->tg_bot_token)) {
            return false;
        }

        return filled($settings->tg_chat_id) || filled($settings->tg_group_chat_id);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyServerAlert(OriginServer $server, array $result, string $kind): void
    {
        $text = $this->alertTextForServer($server, $result, $kind);
        $sent = false;

        $admins = User::query()->where('role', User::ROLE_ADMIN)->with('settings')->get();
        foreach ($admins as $admin) {
            $settings = $admin->settings;
            if (! $settings || ! $this->settingsHasTelegram($settings)) {
                continue;
            }
            $this->telegram->send($settings, $text);
            $sent = true;
        }

        if (! $sent) {
            Log::warning('Origin health alert had no admin Telegram to send to', [
                'host' => $server->host,
                'kind' => $kind,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function alertTextForSettings(UserSetting $settings, array $result, string $kind): string
    {
        $settings->loadMissing('user');

        $buyer = trim((string) ($settings->user?->name ?? '—'));
        $host = trim((string) $settings->deploy_host);
        $panel = trim((string) ($settings->deploy_panel_name ?: $host ?: 'server'));
        $offers = $this->offerCountOnHost($host, $settings->user_id);
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $detail = $issues !== [] ? implode(', ', $issues) : (string) ($result['message'] ?? '');

        return match ($kind) {
            'down' => implode("\n", array_filter([
                '🔴 Origin недоступний',
                "Баєр: {$buyer}",
                "Сервер: {$host}".($panel !== '' && $panel !== $host ? " ({$panel})" : ''),
                $detail !== '' ? $detail : 'SSH не відповідає',
                "Оферів на цьому хості: {$offers}",
                '→ Перевірте сервер або змініть origin у налаштуваннях.',
            ])),
            'degraded' => implode("\n", array_filter([
                '⚠️ Origin з проблемами',
                "Баєр: {$buyer}",
                "Сервер: {$host}".($panel !== '' && $panel !== $host ? " ({$panel})" : ''),
                $detail !== '' ? $detail : 'Сервер живий, але є проблеми',
                "Оферів на цьому хості: {$offers}",
                '→ Перевірте диск, nginx/php-fpm або змініть origin.',
            ])),
            default => implode("\n", [
                '✅ Origin знову доступний',
                "Баєр: {$buyer}",
                "Сервер: {$host}".($panel !== '' && $panel !== $host ? " ({$panel})" : ''),
                "Оферів на цьому хості: {$offers}",
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function alertTextForServer(OriginServer $server, array $result, string $kind): string
    {
        $host = trim((string) $server->host);
        $label = $server->displayLabel();
        $offers = $this->offerCountOnHost($host);
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $detail = $issues !== [] ? implode(', ', $issues) : (string) ($result['message'] ?? '');
        $identity = $this->serverIdentityLines($server);

        return match ($kind) {
            'down' => implode("\n", array_filter([
                '🔴 Origin недоступний',
                "Сервер: {$host}".($label !== $host ? " ({$label})" : ''),
                ...$identity,
                $detail !== '' ? $detail : 'SSH/HTTP не відповідає',
                "Оферів на цьому хості: {$offers}",
                $offers > 0 ? 'Якщо SSH не оживе за 10 хв — автоматична евакуація.' : null,
                '→ Перевірте сервер у розділі Origin-сервери.',
            ])),
            'degraded' => implode("\n", array_filter([
                '⚠️ Origin з проблемами',
                "Сервер: {$host}".($label !== $host ? " ({$label})" : ''),
                ...$identity,
                $detail !== '' ? $detail : 'Сервер живий, але є проблеми',
                "Оферів на цьому хості: {$offers}",
                '→ Перевірте диск, nginx/php-fpm або SSH-креди.',
            ])),
            default => implode("\n", array_filter([
                '✅ Origin знову доступний',
                "Сервер: {$host}".($label !== $host ? " ({$label})" : ''),
                ...$identity,
                "Оферів на цьому хості: {$offers}",
            ])),
        };
    }

    /**
     * Hoster / purchase / paid-until so a down alert is identifiable without opening the panel.
     *
     * @return list<string>
     */
    public function formatServerIdentity(OriginServer $server): array
    {
        return $this->serverIdentityLines($server);
    }

    /**
     * @return list<string>
     */
    private function serverIdentityLines(OriginServer $server): array
    {
        $hoster = trim((string) ($server->hoster ?? ''));
        $price = trim((string) ($server->price ?? ''));
        $bought = $server->created_at?->timezone(config('app.timezone'))->format('d.m.Y');
        $until = $server->paid_until?->format('d.m.Y');
        $days = $server->daysUntilPaid();
        $paidLine = null;

        if ($until) {
            $when = match (true) {
                $days === 1 => 'завтра',
                $days === 0 => 'сьогодні',
                is_int($days) && $days < 0 => 'прострочено '.abs($days).' дн.',
                is_int($days) => 'ще '.$days.' дн.',
                default => null,
            };
            $paidLine = "Оплачено до: {$until}".($when ? " ({$when})" : '');
        }

        $role = match ($server->role()) {
            OriginServer::ROLE_SPARE => 'запасний',
            OriginServer::ROLE_DRAIN => 'звільняється',
            default => 'пул',
        };

        return array_values(array_filter([
            $hoster !== '' ? "Хостер: {$hoster}" : null,
            $bought ? "Куплений: {$bought}" : null,
            $paidLine,
            "Роль: {$role}",
            $price !== '' ? "Ціна: {$price}" : null,
        ]));
    }

    private function offerCountOnHost(string $host, ?int $userId = null): int
    {
        $host = $this->sync->normalizeHost($host);

        if ($host === '') {
            return 0;
        }

        $query = Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->where(function ($q) use ($host) {
                $q->where('infra_meta->deploy_host', $host)
                    ->orWhere('deploy_panel_name', $host);
            });

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->count();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function panelMetrics(array $result): array
    {
        $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        $ssh = is_array($result['ssh'] ?? null) ? $result['ssh'] : [];
        $http = is_array($result['http'] ?? null) ? $result['http'] : [];

        return array_merge($metrics, [
            'ssh_ok' => (bool) ($ssh['ok'] ?? false),
            'ssh_ms' => $ssh['latency_ms'] ?? null,
            'http_ok' => (bool) ($http['ok'] ?? false),
            'http_ms' => $http['latency_ms'] ?? null,
            'http_status' => $http['status'] ?? null,
        ]);
    }
}
