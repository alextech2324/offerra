<?php

namespace App\Services;

use App\Jobs\ArchiveCfAbuseOfferJob;
use App\Models\CloudflareAbuseReport;
use App\Models\CloudflareAccount;
use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CloudflareAbusePollService
{
    /** @var list<string> */
    public const NOTIFY_TYPES = ['PHISH', 'THREAT', 'GEN'];

    public const AUTO_ARCHIVE_AFTER_MINUTES = 3;

    /** @var list<string> */
    private const INACTIVE_STATUSES = ['archived', 'archiving', 'purging', 'teardown_failed'];

    public function __construct(
        private readonly CloudflareClient $cloudflare,
        private readonly TelegramNotifier $telegram,
        private readonly OfferTeardownService $teardown,
    ) {}

    /**
     * @return array{accounts: int, fetched: int, new: int, notified: int, archived: int, forbidden: int, errors: int}
     */
    public function poll(): array
    {
        $stats = [
            'accounts' => 0,
            'fetched' => 0,
            'new' => 0,
            'notified' => 0,
            'archived' => 0,
            'forbidden' => 0,
            'errors' => 0,
        ];

        foreach ($this->uniqueCredentials() as $creds) {
            $stats['accounts']++;
            $result = $this->pollAccount($creds['token'], $creds['account_id'], $creds['name']);
            $stats['fetched'] += $result['fetched'];
            $stats['new'] += $result['new'];
            $stats['notified'] += $result['notified'];
            $stats['forbidden'] += $result['forbidden'] ? 1 : 0;
            $stats['errors'] += $result['error'] ? 1 : 0;
        }

        $stats['archived'] = $this->archiveDueOffers();

        return $stats;
    }

    /**
     * @return array{fetched: int, new: int, notified: int, forbidden: bool, error: bool}
     */
    private function pollAccount(string $token, string $accountId, string $name): array
    {
        $empty = [
            'fetched' => 0,
            'new' => 0,
            'notified' => 0,
            'forbidden' => false,
            'error' => false,
        ];

        $response = $this->cloudflare->listAbuseReports($token, $accountId, [
            'page' => 1,
            'per_page' => 50,
        ]);

        if ($response['forbidden']) {
            $this->logForbiddenOnce($accountId, $name, $response['status']);

            return [...$empty, 'forbidden' => true];
        }

        if (! $response['ok']) {
            Log::warning('cf_abuse.poll_failed', [
                'account_id' => $accountId,
                'name' => $name,
                'status' => $response['status'],
                'error' => $response['error'] ?? '',
            ]);

            return [...$empty, 'error' => true];
        }

        $new = 0;
        $notified = 0;

        foreach ($response['reports'] as $report) {
            if (! is_array($report)) {
                continue;
            }

            $outcome = $this->ingest($accountId, $report);
            if ($outcome['created']) {
                $new++;
            }
            if ($outcome['notified']) {
                $notified++;
            }
        }

        return [
            'fetched' => count($response['reports']),
            'new' => $new,
            'notified' => $notified,
            'forbidden' => false,
            'error' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{created: bool, notified: bool}
     */
    private function ingest(string $accountId, array $report): array
    {
        $cfReportId = trim((string) ($report['id'] ?? ''));
        if ($cfReportId === '') {
            return ['created' => false, 'notified' => false];
        }

        $existing = CloudflareAbuseReport::query()
            ->where('cloudflare_account_id', $accountId)
            ->where('cf_report_id', $cfReportId)
            ->first();

        if ($existing) {
            if ($existing->notified_at === null) {
                return ['created' => false, 'notified' => $this->notifyIfNeeded($existing)];
            }

            return ['created' => false, 'notified' => false];
        }

        $domain = $this->normalizeDomain((string) ($report['domain'] ?? ''));
        $type = strtoupper(trim((string) ($report['type'] ?? '')));
        $status = strtolower(trim((string) ($report['status'] ?? '')));
        $offer = $this->matchOffer($domain);

        try {
            $row = CloudflareAbuseReport::query()->create([
                'cloudflare_account_id' => $accountId,
                'cf_report_id' => $cfReportId,
                'domain' => $domain !== '' ? $domain : null,
                'type' => $type !== '' ? $type : null,
                'status' => $status !== '' ? $status : null,
                'offer_id' => $offer?->id,
                'user_id' => $offer?->user_id,
                'payload' => $this->compactPayload($report),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['created' => false, 'notified' => false];
        }

        if ($offer && in_array($type, self::NOTIFY_TYPES, true)) {
            $this->markLiveOfferAbused($offer);
        }

        return [
            'created' => true,
            'notified' => $this->notifyIfNeeded($row, $offer),
        ];
    }

    private function notifyIfNeeded(CloudflareAbuseReport $row, ?Offer $offer = null): bool
    {
        if ($row->notified_at !== null) {
            return false;
        }

        $type = strtoupper(trim((string) ($row->type ?? '')));
        if (! in_array($type, self::NOTIFY_TYPES, true)) {
            $row->forceFill(['notified_at' => now()])->save();

            return false;
        }

        $offer ??= $row->offer_id
            ? Offer::query()->with(['user.settings'])->find($row->offer_id)
            : $this->matchOffer((string) ($row->domain ?? ''));

        if (! $offer || in_array($offer->status, self::INACTIVE_STATUSES, true)) {
            $row->forceFill(['notified_at' => now()])->save();

            return false;
        }

        $this->markLiveOfferAbused($offer);

        $sent = $this->notifyTelegram($offer, $row);

        if (! $sent) {
            Log::warning('cf_abuse.telegram_failed', [
                'offer_id' => $offer->id,
                'domain' => $offer->domain,
                'cf_report_id' => $row->cf_report_id,
            ]);
        }

        $row->forceFill([
            'offer_id' => $row->offer_id ?: $offer->id,
            'user_id' => $row->user_id ?: $offer->user_id,
            'notified_at' => now(),
        ])->save();

        return $sent;
    }

    private function markLiveOfferAbused(Offer $offer): void
    {
        if (in_array($offer->status, self::INACTIVE_STATUSES, true)) {
            return;
        }

        if ($offer->cf_abuse_at !== null) {
            return;
        }

        $offer->forceFill(['cf_abuse_at' => now()])->save();
        $this->scheduleAutoArchive($offer);
    }

    public function archiveDueOffers(): int
    {
        $cutoff = now()->subMinutes(self::AUTO_ARCHIVE_AFTER_MINUTES);
        $offers = Offer::query()
            ->with('user')
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->whereNotNull('cf_abuse_at')
            ->where('cf_abuse_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        $archived = 0;
        foreach ($offers as $offer) {
            if ($this->archiveOfferNow($offer)) {
                $archived++;
            }
        }

        return $archived;
    }

    public function archiveOfferNow(Offer $offer): bool
    {
        $offer->refresh();
        if (in_array($offer->status, self::INACTIVE_STATUSES, true)) {
            return false;
        }

        $actor = $offer->user
            ?? User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        if (! $actor) {
            Log::warning('cf_abuse.auto_archive_no_actor', [
                'offer_id' => $offer->id,
                'domain' => $offer->domain,
            ]);

            return false;
        }

        try {
            $this->teardown->enqueueArchive($offer, $actor, Offer::ARCHIVE_REASON_CF_ABUSE);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'вже архівується') || str_contains($msg, 'в архіві')) {
                return false;
            }

            Log::warning('cf_abuse.auto_archive_failed', [
                'offer_id' => $offer->id,
                'domain' => $offer->domain,
                'error' => $msg,
            ]);

            return false;
        }

        return true;
    }

    private function scheduleAutoArchive(Offer $offer): void
    {
        ArchiveCfAbuseOfferJob::dispatch($offer->id)
            ->delay(now()->addMinutes(self::AUTO_ARCHIVE_AFTER_MINUTES));
    }

    private function notifyTelegram(Offer $offer, CloudflareAbuseReport $row): bool
    {
        $text = $this->telegramText($offer, $row);
        $sent = false;
        $seen = [];

        $offer->loadMissing('user.settings');
        $candidates = [];
        if ($offer->user?->settings instanceof UserSetting) {
            $candidates[] = $offer->user->settings;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->with('settings')->get();
        foreach ($admins as $admin) {
            if ($admin->settings instanceof UserSetting) {
                $candidates[] = $admin->settings;
            }
        }

        foreach ($candidates as $settings) {
            $id = (int) $settings->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->telegram->send($settings, $text)) {
                $sent = true;
            }
        }

        return $sent;
    }

    private function matchOffer(string $domain): ?Offer
    {
        if ($domain === '') {
            return null;
        }

        return Offer::query()
            ->with(['user.settings'])
            ->whereRaw('LOWER(domain) = ?', [$domain])
            ->orderByRaw("CASE WHEN status IN ('archived','archiving','purging','teardown_failed') THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->first();
    }

    private function normalizeDomain(string $raw): string
    {
        $domain = strtolower(trim($raw));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = rtrim($domain, '.');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function compactPayload(array $report): array
    {
        unset($report['justification'], $report['original_work'], $report['submitter']);

        return $report;
    }

    private function telegramText(Offer $offer, CloudflareAbuseReport $row): string
    {
        $offer->loadMissing('user');
        $domain = $offer->domain;
        $url = route('offers.index', ['domain' => $domain], true);
        $user = $offer->user;
        $userLine = null;
        if ($user) {
            $name = trim((string) $user->name);
            $email = trim((string) $user->email);
            $userLine = 'Юзер: '.($name !== '' ? $name : $email);
            if ($name !== '' && $email !== '' && strcasecmp($name, $email) !== 0) {
                $userLine .= ' ('.$email.')';
            }
        }

        $templateId = trim((string) ($offer->template ?? ''));
        $templateLine = null;
        if ($templateId !== '') {
            $label = app(TemplateCatalog::class)->label($templateId);
            $templateLine = 'Шаблон: '.$label.($label !== $templateId ? ' ('.$templateId.')' : '');
        }

        $created = $offer->created_at?->timezone('Europe/Kyiv')->format('d.m.Y H:i');
        $geo = strtoupper(trim((string) ($offer->geo ?? '')));
        $lang = strtolower(trim((string) ($offer->lang ?? '')));
        $geoLine = $geo !== '' ? 'GEO: '.$geo.($lang !== '' ? ' / '.$lang : '') : null;

        return implode("\n", array_filter([
            '⚠️ Cloudflare abuse',
            '',
            'Домен: '.$domain,
            'Тип: '.strtoupper((string) $row->type),
            $userLine,
            trim((string) $offer->brand) !== '' ? 'Бренд: '.$offer->brand : null,
            $geoLine,
            $templateLine,
            $created !== null ? 'Створено: '.$created : null,
            '',
            'Автоматично заархівується через '.self::AUTO_ARCHIVE_AFTER_MINUTES.' хв, щоб хостер не вимкнув origin.',
            $url,
        ], static fn ($line) => $line !== null));
    }

    private function logForbiddenOnce(string $accountId, string $name, int $status): void
    {
        $key = 'cf_abuse.forbidden.'.$accountId;
        if (! Cache::add($key, true, now()->addHours(6))) {
            return;
        }

        Log::warning('cf_abuse.forbidden', [
            'account_id' => $accountId,
            'name' => $name,
            'status' => $status,
            'hint' => 'Cloudflare API token needs Account → Trust and Safety → Read',
        ]);
    }

    /**
     * @return list<array{token: string, account_id: string, name: string}>
     */
    private function uniqueCredentials(): array
    {
        $seen = [];
        $out = [];

        foreach (UserSetting::query()->get() as $settings) {
            foreach (['primary', 'backup'] as $slot) {
                $creds = $settings->cloudflareSlotCredentials($slot);
                $this->pushCredential($out, $seen, $creds);
            }
        }

        foreach (CloudflareAccount::query()->where('is_active', true)->get() as $account) {
            if (! $account->isUsable()) {
                continue;
            }
            $this->pushCredential($out, $seen, $account->credentials());
        }

        return $out;
    }

    /**
     * @param  list<array{token: string, account_id: string, name: string}>  $out
     * @param  array<string, true>  $seen
     * @param  array{token: string, account_id: string, name: string}  $creds
     */
    private function pushCredential(array &$out, array &$seen, array $creds): void
    {
        $token = $creds['token'] ?? '';
        $accountId = $creds['account_id'] ?? '';
        if ($token === '' || $accountId === '') {
            return;
        }

        $key = $accountId.'|'.hash('sha256', $token);
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $out[] = [
            'token' => $token,
            'account_id' => $accountId,
            'name' => (string) ($creds['name'] ?? ''),
        ];
    }
}
