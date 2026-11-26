<?php

namespace App\Services;

use App\Models\OriginServer;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Overnight SSH death should not leave landers on a dead IP until morning.
 *
 * After a host has been down for 10 minutes, probe SSH three more times
 * (one minute apart). If all three fail, evacuate onto the rest of the pool.
 */
class OriginAutoEvacuateService
{
    public const WAIT_MINUTES = 10;

    public const CONFIRM_TRIES = 3;

    public const CONFIRM_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly OriginHealthProbe $probe,
        private readonly OriginHealthMonitor $monitor,
        private readonly OriginEvacuationService $evacuation,
        private readonly OriginPool $pool,
        private readonly OriginServerSync $sync,
        private readonly TelegramNotifier $telegram,
    ) {}

    /**
     * @return array{skipped: int, waiting: int, confirming: int, recovered: int, evacuated: int, blocked: int}
     */
    public function tick(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $stats = [
            'skipped' => 0,
            'waiting' => 0,
            'confirming' => 0,
            'recovered' => 0,
            'evacuated' => 0,
            'blocked' => 0,
        ];

        foreach (OriginServer::query()->orderBy('id')->get() as $server) {
            $stats[$this->consider($server, $now)]++;
        }

        return $stats;
    }

    /**
     * @return 'skipped'|'waiting'|'confirming'|'recovered'|'evacuated'|'blocked'
     */
    private function consider(OriginServer $server, Carbon $now): string
    {
        if (! ($server->alerts_enabled ?? true) || ! $server->hasSshCredentials()) {
            return 'skipped';
        }

        $health = is_array($server->health) ? $server->health : [];
        if ((string) ($health['status'] ?? '') !== 'down') {
            return 'skipped';
        }

        if (! empty($health['auto_evac_at'])) {
            return 'skipped';
        }

        $offerIds = $this->evacuation->offerIdsOn($server);
        if ($offerIds === []) {
            return 'skipped';
        }

        $downSince = $this->parseTime($health['down_since'] ?? $health['checked_at'] ?? null);
        if ($downSince === null || $downSince->gt($now->copy()->subMinutes(self::WAIT_MINUTES))) {
            return 'waiting';
        }

        $fails = (int) ($health['auto_evac_confirm_fails'] ?? 0);
        $lastConfirm = $this->parseTime($health['auto_evac_last_confirm_at'] ?? null);
        $result = null;

        if ($fails < self::CONFIRM_TRIES) {
            if ($lastConfirm !== null && $lastConfirm->gt($now->copy()->subSeconds(self::CONFIRM_INTERVAL_SECONDS))) {
                return 'waiting';
            }

            $result = $this->probe->probeServer($server);
            if ((string) ($result['status'] ?? '') !== 'down') {
                $this->monitor->applyToServer($server, $result, true);

                return 'recovered';
            }

            $this->monitor->applyToServer($server, $result, true);
            $server->refresh();
            $fails++;
            $this->patchHealth($server, [
                'auto_evac_confirm_fails' => $fails,
                'auto_evac_last_confirm_at' => $now->toIso8601String(),
                'auto_evac_error' => null,
            ]);

            if ($fails < self::CONFIRM_TRIES) {
                Log::info('Origin auto-evac confirm failed', [
                    'host' => $server->host,
                    'fails' => $fails,
                    'need' => self::CONFIRM_TRIES,
                ]);

                return 'confirming';
            }
        }

        if (! $this->hasMoveTargets($server)) {
            $alreadyBlocked = ($health['auto_evac_error'] ?? '') === 'no_targets';
            $this->patchHealth($server, [
                'auto_evac_error' => 'no_targets',
                'auto_evac_last_confirm_at' => $now->toIso8601String(),
            ]);
            if (! $alreadyBlocked) {
                $this->notify($this->blockedText($server, count($offerIds), $result));
            }

            return 'blocked';
        }

        try {
            $moved = $this->evacuation->evacuate($server);
        } catch (Throwable $e) {
            Log::error('Origin auto-evac failed', [
                'host' => $server->host,
                'error' => $e->getMessage(),
            ]);
            $this->patchHealth($server, [
                'auto_evac_error' => $e->getMessage(),
                'auto_evac_last_confirm_at' => $now->toIso8601String(),
            ]);
            $this->notify($this->failedText($server, count($offerIds), $e->getMessage(), $result ?? null));

            return 'blocked';
        }

        $this->patchHealth($server->fresh() ?? $server, [
            'auto_evac_at' => $now->toIso8601String(),
            'auto_evac_error' => null,
            'auto_evac_confirm_fails' => self::CONFIRM_TRIES,
        ]);
        $this->notify($this->evacuatedText($server, $moved, $result ?? null));

        Log::info('Origin auto-evac queued', [
            'host' => $server->host,
            'offers' => $moved['offers'] ?? 0,
            'targets' => $moved['targets'] ?? [],
        ]);

        return 'evacuated';
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchHealth(OriginServer $server, array $patch): void
    {
        $health = is_array($server->health) ? $server->health : [];
        $server->health = array_merge($health, $patch);
        $server->save();
    }

    private function hasMoveTargets(OriginServer $from): bool
    {
        $host = $this->sync->normalizeHost((string) $from->host);

        foreach ($this->pool->candidates() as $row) {
            if ($this->sync->normalizeHost((string) $row['server']->host) !== $host) {
                return true;
            }
        }

        return false;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function evacuatedText(OriginServer $server, array $moved, ?array $result): string
    {
        $offers = (int) ($moved['offers'] ?? 0);
        $spread = collect($moved['targets'] ?? [])
            ->map(fn (int $count, string $host) => "{$host}: {$count}")
            ->implode(', ');
        $detail = $this->probeDetail($result);

        return implode("\n", array_filter([
            '🚑 Автоевакуація origin',
            "Сервер: {$server->host}".($server->displayLabel() !== $server->host ? " ({$server->displayLabel()})" : ''),
            ...$this->monitor->formatServerIdentity($server),
            $detail,
            'SSH мертвий 10+ хв, 3 додаткові спроби не пройшли.',
            "Оферів у черзі: {$offers}".($spread !== '' ? " → {$spread}" : ''),
            'Сервер позначено «звільняється».',
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function blockedText(OriginServer $server, int $offers, ?array $result): string
    {
        return implode("\n", array_filter([
            '🚑 Автоевакуація не вдалась',
            "Сервер: {$server->host}",
            ...$this->monitor->formatServerIdentity($server),
            $this->probeDetail($result),
            "Оферів на хості: {$offers}",
            'Немає інших живих серверів у пулі, куди перенести офери.',
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function failedText(OriginServer $server, int $offers, string $error, ?array $result): string
    {
        return implode("\n", array_filter([
            '🚑 Автоевакуація не вдалась',
            "Сервер: {$server->host}",
            ...$this->monitor->formatServerIdentity($server),
            $this->probeDetail($result),
            "Оферів на хості: {$offers}",
            $error !== '' ? $error : null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function probeDetail(?array $result): ?string
    {
        if ($result === null) {
            return null;
        }

        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $detail = $issues !== [] ? implode(', ', $issues) : (string) ($result['message'] ?? '');

        return $detail !== '' ? $detail : null;
    }

    private function notify(string $text): void
    {
        $sent = false;
        $admins = User::query()->where('role', User::ROLE_ADMIN)->with('settings')->get();
        foreach ($admins as $admin) {
            $settings = $admin->settings;
            if (! $settings instanceof UserSetting) {
                continue;
            }
            if (! filled($settings->tg_bot_token)) {
                continue;
            }
            if (! filled($settings->tg_chat_id) && ! filled($settings->tg_group_chat_id)) {
                continue;
            }
            if ($this->telegram->send($settings, $text)) {
                $sent = true;
            }
        }

        if (! $sent) {
            Log::warning('Origin auto-evac had no admin Telegram to send to');
        }
    }
}
