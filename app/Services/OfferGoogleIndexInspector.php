<?php

namespace App\Services;

use App\Jobs\InspectOfferGoogleIndexJob;
use App\Models\GoogleAccount;
use App\Models\Offer;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfferGoogleIndexInspector
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_NOT_INDEXED = 'not_indexed';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_ERROR = 'error';

    public const FIRST_CHECK_HOURS = 24;

    public const RECHECK_HOURS = 24;

    public const TIMEOUT_DAYS = 7;

    public function __construct(
        private readonly GoogleSearchConsoleClient $gsc,
    ) {}

    public function queueFirstCheck(Offer $offer): void
    {
        if ($offer->google_indexed_at) {
            return;
        }

        // Do not inspect during the day: first check lands at 03:00 Kyiv after the 24h wait.
        InspectOfferGoogleIndexJob::dispatch($offer->id)
            ->delay($this->nextInspectAt());
    }

    /**
     * Next 03:00 Europe/Kyiv that is still at least FIRST_CHECK_HOURS from now.
     */
    public function nextInspectAt(?Carbon $notBefore = null): Carbon
    {
        $tz = (string) config('app.timezone', 'Europe/Kyiv');
        $ready = ($notBefore ?? now()->addHours(self::FIRST_CHECK_HOURS))->copy()->timezone($tz);
        $run = $ready->copy()->setTime(3, 0, 0);
        if ($run->lt($ready)) {
            $run->addDay();
        }

        return $run;
    }

    /**
     * Offers due for a GSC inspection (or timeout stamp).
     */
    public function dueQuery(): Builder
    {
        return Offer::query()
            ->where('submitted_for_indexing', true)
            ->whereNull('google_indexed_at')
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->whereNotNull('indexed_at')
            ->where('indexed_at', '<=', now()->subHours(self::FIRST_CHECK_HOURS))
            ->where(function (Builder $q): void {
                $q->whereNull('google_index_status')
                    ->orWhereIn('google_index_status', [
                        self::STATUS_WAITING,
                        self::STATUS_ERROR,
                        self::STATUS_TIMEOUT,
                        self::STATUS_DUPLICATE,
                        self::STATUS_UNKNOWN,
                        self::STATUS_NOT_INDEXED,
                    ]);
            })
            ->where(function (Builder $q): void {
                $q->whereNull('google_index_checked_at')
                    ->orWhere('google_index_checked_at', '<=', now()->subHours(self::RECHECK_HOURS - 1))
                    ->orWhereExists(function ($sub): void {
                        $sub->from('google_accounts')
                            ->whereColumn('google_accounts.user_id', 'offers.user_id')
                            ->where(function ($when): void {
                                $when->whereNull('offers.google_index_checked_at')
                                    ->orWhereColumn('google_accounts.connected_at', '>', 'offers.google_index_checked_at');
                            });
                    });
            });
    }

    /**
     * Timed-out offers that were submitted but never confirmed in the index.
     */
    public function timeoutQuery(): Builder
    {
        return Offer::query()
            ->where('submitted_for_indexing', true)
            ->whereNull('google_indexed_at')
            ->where('google_index_status', self::STATUS_TIMEOUT)
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed']);
    }

    /**
     * @return array{status: string, indexed: bool, coverage: ?string, canonical: ?string}
     */
    public function inspect(Offer $offer, bool $force = false): array
    {
        if (in_array($offer->status, ['archived', 'archiving', 'purging', 'teardown_failed'], true)) {
            return [
                'status' => (string) ($offer->google_index_status ?? ''),
                'indexed' => (bool) $offer->google_indexed_at,
                'coverage' => $offer->google_index_coverage,
                'canonical' => $offer->google_index_canonical,
            ];
        }

        if (! $force && $offer->google_indexed_at) {
            return [
                'status' => self::STATUS_INDEXED,
                'indexed' => true,
                'coverage' => $offer->google_index_coverage,
                'canonical' => $offer->google_index_canonical,
            ];
        }

        $accounts = $this->inspectAccounts($offer);
        if ($accounts->isEmpty()) {
            $this->stamp($offer, self::STATUS_ERROR, null, 'Немає Google Connect');

            return [
                'status' => self::STATUS_ERROR,
                'indexed' => false,
                'coverage' => 'Немає Google Connect',
                'canonical' => null,
            ];
        }

        $ownedResult = null;
        $ownedAccount = null;
        $errors = [];
        $unverified = [];
        $tried = 0;
        $storedSiteUrl = $this->storedSiteUrl($offer);

        foreach ($accounts as $account) {
            $tried++;
            $found = null;
            try {
                $found = $this->gsc->findSiteOnAccount($account, (string) $offer->domain, $storedSiteUrl);
            } catch (RuntimeException $e) {
                Log::warning('GSC sites.list failed', [
                    'offer' => $offer->id,
                    'domain' => $offer->domain,
                    'email' => $account->email,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $account->email.': '.$this->shortAccountError($e);
                continue;
            }

            if (! $found) {
                $errors[] = $account->email.': немає property';
                continue;
            }

            if (! GoogleSearchConsoleClient::canInspectPermission($found['permission'])) {
                $unverified[] = $account->email;
                continue;
            }

            $siteUrl = $found['site_url'];

            try {
                $result = $this->gsc->inspectUrl($offer, $account, $siteUrl);
            } catch (RuntimeException $e) {
                Log::warning('GSC inspect failed', [
                    'offer' => $offer->id,
                    'domain' => $offer->domain,
                    'email' => $account->email,
                    'error' => $e->getMessage(),
                ]);

                if ($this->gsc->isNotOwnerError($e)) {
                    $errors[] = $account->email.': не власник property';
                    continue;
                }

                $errors[] = $account->email.': '.$this->shortAccountError($e);
                continue;
            }

            if (self::isIndexedResult((string) $offer->domain, $result)) {
                $other = self::chosenHost((string) $offer->domain, $result);
                if ($other) {
                    $coverage = 'Indexed · canonical '.$other;
                } elseif (str_contains(strtolower((string) ($result['coverage'] ?? '')), 'duplicate')) {
                    $coverage = $this->coverageLabel([
                        'coverage' => 'Submitted and indexed',
                        'verdict' => (string) ($result['verdict'] ?: 'PASS'),
                    ], $account);
                } else {
                    $coverage = $this->coverageLabel($result, $account);
                }
                $this->stamp($offer, self::STATUS_INDEXED, now(), $coverage, $account, $result['site_url'] ?? $siteUrl, null);

                return [
                    'status' => self::STATUS_INDEXED,
                    'indexed' => true,
                    'coverage' => $coverage,
                    'canonical' => null,
                ];
            }

            if (! $ownedResult) {
                $ownedResult = $result;
                $ownedAccount = $account;
            }
        }

        if ($ownedResult && $ownedAccount) {
            $siteUrl = (string) ($ownedResult['site_url'] ?? '');
            $classified = self::classifyInspection((string) $offer->domain, $ownedResult);
            $status = $classified['status'];
            if (in_array($status, [self::STATUS_UNKNOWN, self::STATUS_NOT_INDEXED], true) && $this->pastTimeout($offer)) {
                $status = self::STATUS_TIMEOUT;
            }
            $this->stamp(
                $offer,
                $status,
                null,
                $classified['coverage'],
                $ownedAccount,
                $siteUrl !== '' ? $siteUrl : null,
                $classified['canonical'],
            );

            return [
                'status' => $status,
                'indexed' => false,
                'coverage' => $classified['coverage'],
                'canonical' => $classified['canonical'],
            ];
        }

        if ($unverified !== []) {
            $who = $this->shortEmailList($unverified);
            $coverage = 'Сайт у GSC не підтверджений ('.$who.'). Підключіть Google, який верифікував.';
            $this->stamp($offer, self::STATUS_ERROR, null, substr($coverage, 0, 190));

            return [
                'status' => self::STATUS_ERROR,
                'indexed' => false,
                'coverage' => substr($coverage, 0, 190),
                'canonical' => null,
            ];
        }

        $coverage = $tried > 0
            ? 'Жоден з '.$tried.' Google не має цей сайт у Search Console'
            : 'Жоден підключений Google не бачить цей домен';
        if ($this->pastTimeout($offer)) {
            $this->stamp($offer, self::STATUS_TIMEOUT, null, 'Не в індексі за '.self::TIMEOUT_DAYS.' днів після подачі');

            return [
                'status' => self::STATUS_TIMEOUT,
                'indexed' => false,
                'coverage' => 'Не в індексі за '.self::TIMEOUT_DAYS.' днів після подачі',
                'canonical' => null,
            ];
        }
        $this->stamp($offer, self::STATUS_ERROR, null, $coverage);

        return [
            'status' => self::STATUS_ERROR,
            'indexed' => false,
            'coverage' => $coverage,
            'canonical' => null,
        ];
    }

    /**
     * Map a GSC URL Inspection payload to the panel status — never a generic 7-day timeout.
     *
     * @param  array{verdict?: string, coverage?: string, indexing_state?: string, google_canonical?: ?string, user_canonical?: ?string, referring_urls?: list<string>}  $result
     * @return array{status: string, coverage: string, canonical: ?string}
     */
    /**
     * GSC "indexed" plus duplicates that still appear in site: search.
     * referringUrls are ignored — they are inbound links (Gridinsoft, blogs), not the canonical.
     *
     * @param  array{indexed?: bool, coverage?: string, indexing_state?: string, last_crawl_at?: ?string, google_canonical?: ?string}  $result
     */
    public static function isIndexedResult(string $offerDomain, array $result): bool
    {
        if (! empty($result['indexed'])) {
            return true;
        }

        $offerHost = self::hostFromUrl($offerDomain) ?? strtolower(trim($offerDomain));
        $googleHost = self::hostFromUrl((string) ($result['google_canonical'] ?? ''));
        if ($googleHost !== null && $googleHost === $offerHost) {
            return true;
        }

        $coverage = strtolower(trim((string) ($result['coverage'] ?? '')));
        $state = strtoupper(trim((string) ($result['indexing_state'] ?? '')));
        $crawled = trim((string) ($result['last_crawl_at'] ?? '')) !== '';

        return $state === 'INDEXING_ALLOWED'
            && $crawled
            && str_contains($coverage, 'duplicate');
    }

    public static function classifyInspection(string $offerDomain, array $result): array
    {
        $canonical = self::chosenHost($offerDomain, $result);
        $coverageRaw = trim((string) ($result['coverage'] ?? ''));
        $isDuplicate = $canonical !== null
            || str_contains(strtolower($coverageRaw), 'duplicate');

        if ($isDuplicate) {
            $coverage = $canonical !== null
                ? 'Google індексує '.$canonical.' замість цього'
                : 'Google вважає цей URL дублем';

            return [
                'status' => self::STATUS_DUPLICATE,
                'coverage' => substr($coverage, 0, 190),
                'canonical' => $canonical,
            ];
        }

        if ($coverageRaw === '' || str_contains(strtolower($coverageRaw), 'unknown to google')) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'coverage' => 'Google ще не бачив цей URL',
                'canonical' => null,
            ];
        }

        $coverage = $coverageRaw !== '' ? $coverageRaw : 'Прокраулено, ще не в індексі';

        return [
            'status' => self::STATUS_NOT_INDEXED,
            'coverage' => substr($coverage, 0, 190),
            'canonical' => null,
        ];
    }

    /**
     * Host Google actually indexed instead of this offer, if any.
     * Only googleCanonical — referringUrls are inbound links, not the chosen URL.
     *
     * @param  array{google_canonical?: ?string}  $result
     */
    public static function chosenHost(string $offerDomain, array $result): ?string
    {
        $offerHost = self::hostFromUrl($offerDomain) ?? strtolower(trim($offerDomain));
        $googleHost = self::hostFromUrl((string) ($result['google_canonical'] ?? ''));
        if ($googleHost !== null && $googleHost !== $offerHost) {
            return $googleHost;
        }

        return null;
    }

    public static function hostFromUrl(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            if (str_contains($value, '/') || str_contains($value, ':')) {
                return null;
            }
            $host = $value;
        }

        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $host = rtrim($host, '.');

        return $host !== '' ? $host : null;
    }

    public function pastTimeout(Offer $offer): bool
    {
        $submitted = $offer->indexed_at;
        if (! $submitted) {
            return false;
        }

        return $submitted->lte(now()->subDays(self::TIMEOUT_DAYS));
    }

    /**
     * Submitted, not yet indexed, still inside the 7-day wait — including
     * "unknown to Google" / crawled-not-indexed from the first GSC check.
     */
    public static function applyPendingFilter(Builder $query): void
    {
        $cutoff = now()->subDays(self::TIMEOUT_DAYS);

        $query->whereNull('google_indexed_at')
            ->where(function (Builder $builder): void {
                $builder->where('submitted_for_indexing', true)
                    ->orWhereIn('google_index_status', [self::STATUS_WAITING, self::STATUS_ERROR]);
            })
            ->where(function (Builder $builder) use ($cutoff): void {
                $builder->whereNull('google_index_status')
                    ->orWhereIn('google_index_status', [self::STATUS_WAITING, self::STATUS_ERROR])
                    ->orWhere(function (Builder $open) use ($cutoff): void {
                        $open->whereIn('google_index_status', [self::STATUS_UNKNOWN, self::STATUS_NOT_INDEXED])
                            ->where(function (Builder $fresh) use ($cutoff): void {
                                $fresh->whereNull('indexed_at')
                                    ->orWhere('indexed_at', '>', $cutoff);
                            });
                    });
            });
    }

    /**
     * Terminal miss: duplicate, 7-day timeout, or unknown/not_indexed past the wait.
     */
    public static function applyMissFilter(Builder $query): void
    {
        $cutoff = now()->subDays(self::TIMEOUT_DAYS);

        $query->whereNull('google_indexed_at')
            ->where(function (Builder $builder) use ($cutoff): void {
                $builder->whereIn('google_index_status', [self::STATUS_DUPLICATE, self::STATUS_TIMEOUT])
                    ->orWhere(function (Builder $stale) use ($cutoff): void {
                        $stale->whereIn('google_index_status', [self::STATUS_UNKNOWN, self::STATUS_NOT_INDEXED])
                            ->whereNotNull('indexed_at')
                            ->where('indexed_at', '<=', $cutoff);
                    });
            });
    }

    public static function isMiss(Offer $offer): bool
    {
        if ($offer->google_indexed_at) {
            return false;
        }

        $status = (string) $offer->google_index_status;
        if (in_array($status, [self::STATUS_DUPLICATE, self::STATUS_TIMEOUT], true)) {
            return true;
        }

        if (! in_array($status, [self::STATUS_UNKNOWN, self::STATUS_NOT_INDEXED], true) || ! $offer->indexed_at) {
            return false;
        }

        return $offer->indexed_at->lte(now()->subDays(self::TIMEOUT_DAYS));
    }

    /**
     * @param  array{verdict: string, coverage: string, indexing_state: string, last_crawl_at: ?string, indexed: bool, site_url?: string}  $result
     */
    private function coverageLabel(array $result, ?GoogleAccount $account = null): string
    {
        $parts = array_filter([
            $result['coverage'] !== '' ? $result['coverage'] : null,
            $result['verdict'] !== '' ? $result['verdict'] : null,
            $account?->email,
        ]);

        return substr(implode(' · ', $parts), 0, 190);
    }

    private function stamp(
        Offer $offer,
        string $status,
        mixed $indexedAt,
        ?string $coverage,
        ?GoogleAccount $account = null,
        ?string $siteUrl = null,
        ?string $canonical = null,
    ): void {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if ($account) {
            $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];
            $gsc['email'] = $account->email;
            $gsc['google_account_id'] = $account->id;
            if (is_string($siteUrl) && $siteUrl !== '') {
                $gsc['site_url'] = $siteUrl;
            }
            $meta['gsc'] = $gsc;
        }

        $offer->forceFill([
            'google_index_status' => $status,
            'google_indexed_at' => $indexedAt,
            'google_index_checked_at' => now(),
            'google_index_coverage' => $coverage,
            'google_index_canonical' => $canonical,
            'infra_meta' => $meta,
        ])->save();
    }

    private function storedSiteUrl(Offer $offer): ?string
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];
        $siteUrl = trim((string) ($gsc['site_url'] ?? ''));

        return $siteUrl !== '' ? $siteUrl : null;
    }

    private function shortAccountError(RuntimeException $e): string
    {
        return substr($e->getMessage(), 0, 80);
    }

    /**
     * @param  list<string>  $emails
     */
    private function shortEmailList(array $emails): string
    {
        $short = array_map(function (string $email): string {
            $at = strpos($email, '@');

            return $at === false ? $email : substr($email, 0, $at);
        }, $emails);

        return substr(implode(', ', $short), 0, 80);
    }

    /**
     * Owner accounts first (pinned / primary), then every other connected Google
     * in the panel — offers are often verified in Admin GSC even when the row is EGO.
     *
     * @return Collection<int, GoogleAccount>
     */
    private function inspectAccounts(Offer $offer): Collection
    {
        $offer->loadMissing(['user.googleAccounts', 'user.settings']);
        $owned = $this->sortOwnerAccounts($offer, $offer->user?->googleAccounts ?? collect());
        if ($owned->isEmpty()) {
            $settings = $offer->user?->settings;
            $primary = $settings instanceof UserSetting ? $settings->primaryGoogleAccount() : null;
            $owned = $primary ? collect([$primary]) : collect();
        }

        $extra = GoogleAccount::query()
            ->whereNotNull('refresh_token')
            ->when($owned->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $owned->pluck('id')->all()))
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (GoogleAccount $account) => filled($account->refresh_token));

        return $owned->concat($extra)->values();
    }

    /**
     * @param  Collection<int, GoogleAccount>|iterable<int, GoogleAccount>  $accounts
     * @return Collection<int, GoogleAccount>
     */
    private function sortOwnerAccounts(Offer $offer, mixed $accounts): Collection
    {
        $collection = $accounts instanceof Collection ? $accounts : collect($accounts);
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];
        $pinnedId = (int) ($gsc['google_account_id'] ?? 0);
        $pinnedEmail = strtolower(trim((string) ($gsc['email'] ?? '')));

        return $collection
            ->filter(fn (GoogleAccount $account) => filled($account->refresh_token))
            ->sortBy(function (GoogleAccount $account) use ($pinnedId, $pinnedEmail) {
                if ($pinnedId > 0 && $account->id === $pinnedId) {
                    return 0;
                }
                if ($pinnedEmail !== '' && strtolower((string) $account->email) === $pinnedEmail) {
                    return 1;
                }
                if ($account->is_primary) {
                    return 2;
                }

                return 3;
            })
            ->values();
    }
}
