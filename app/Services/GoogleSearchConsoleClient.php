<?php

namespace App\Services;

use App\Models\GoogleAccount;
use App\Models\Offer;
use App\Models\UserSetting;
use App\Support\PublicDnsLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleSearchConsoleClient
{
    public const ACCOUNT_FULL_MESSAGE = 'Усі підключені Google-акаунти заповнені в Search Console (ліміт сайтів). Підключіть ще один у Settings і натисніть «Подати в GSC».';

    private const SITE_SOFT_LIMIT = 990;

    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly PublicDnsLookup $publicDns,
        private readonly CloudflareClient $cloudflare,
    ) {}

    /**
     * Add URL-prefix property, verify via HTML file, submit sitemap.
     *
     * @return array{site_url: string, sitemap_url: string, verified: bool, added: bool, sitemap_submitted: bool, email: string, google_account_id: int}
     */
    public function submitOffer(Offer $offer, UserSetting $settings): array
    {
        $domain = strtolower(trim((string) $offer->domain));
        if ($domain === '') {
            throw new RuntimeException('Offer has no domain.');
        }

        $siteUrl = 'https://'.$domain.'/';
        $sitemapUrl = 'https://'.$domain.'/sitemap.xml';
        $verificationFile = trim((string) ($settings->gsc_verification_filename ?? ''));
        if ($verificationFile === '') {
            throw new RuntimeException('GSC verification HTML file is missing in Settings.');
        }

        $accounts = $this->accountsForSubmit($settings);
        if ($accounts === []) {
            throw new RuntimeException('Google account is not connected.');
        }

        $this->assertVerificationFileLive($domain, $verificationFile);
        $this->assertHttpsLive($domain);

        $owner = null;
        foreach ($accounts as $candidate) {
            if ($this->findSiteOnAccount($candidate, $domain, $siteUrl)) {
                $owner = $candidate;
                break;
            }
        }

        $tried = $accounts;
        if ($owner !== null) {
            $tried = [$owner, ...array_values(array_filter(
                $accounts,
                static fn (GoogleAccount $account) => (int) $account->id !== (int) $owner->id,
            ))];
        }
        $sawFullAccount = false;
        $lastError = null;
        $primary = collect($accounts)->first(
            static fn (GoogleAccount $account) => $account->is_primary,
        ) ?? $accounts[0];

        foreach ($tried as $account) {
            $isExistingOwner = $owner !== null && (int) $account->id === (int) $owner->id;
            if (! $isExistingOwner && $this->accountLooksFull($account)) {
                $sawFullAccount = true;

                continue;
            }

            try {
                return $this->submitOfferOnAccount($account, $offer, $siteUrl, $sitemapUrl);
            } catch (RuntimeException $e) {
                if (GoogleOAuthService::isDeadTokenError($e->getMessage())) {
                    // New offers go to the primary. A dead primary must not
                    // silently fall through to a "перевірка" account — the UI
                    // would then blame the wrong email.
                    if (self::deadPrimaryBlocksSubmitFallback($account, $owner, $primary)) {
                        throw $e;
                    }

                    $lastError = $e;

                    continue;
                }

                if (! self::isAccountFullError($e->getMessage())) {
                    throw $e;
                }

                $this->rememberAccountFull($account);
                $sawFullAccount = true;
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            $sawFullAccount
                ? self::ACCOUNT_FULL_MESSAGE
                : ($lastError?->getMessage() ?? 'Google account is not connected.'),
        );
    }

    /**
     * @return array{site_url: string, sitemap_url: string, verified: bool, added: bool, sitemap_submitted: bool, email: string, google_account_id: int}
     */
    private function submitOfferOnAccount(GoogleAccount $account, Offer $offer, string $siteUrl, string $sitemapUrl): array
    {
        $accessToken = $this->oauth->accessTokenForAccount($account);

        // Search Console: add property first, then verify ownership, then sitemap.
        // Submitting the sitemap before ownership has propagated often yields HTTP 403.
        $added = $this->addSite($accessToken, $siteUrl);
        $verified = $this->verifySiteOwnership($account, $offer, $siteUrl);
        $sitemapSubmitted = $this->submitSitemap($accessToken, $siteUrl, $sitemapUrl);

        Cache::forget('gsc.sites.v2.'.$account->id);

        return [
            'site_url' => $siteUrl,
            'sitemap_url' => $sitemapUrl,
            'verified' => $verified,
            'added' => $added,
            'sitemap_submitted' => $sitemapSubmitted,
            'email' => (string) ($account->email ?? ''),
            'google_account_id' => $account->id,
        ];
    }

    /**
     * Remove URL-prefix property from Search Console (and drop sitemap if present).
     *
     * @return 'deleted'|'already_gone'
     */
    public function removeOffer(Offer $offer, UserSetting $settings): string
    {
        $domain = strtolower(trim((string) $offer->domain));
        if ($domain === '') {
            throw new RuntimeException('Offer has no domain.');
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];

        $siteUrl = trim((string) ($gsc['site_url'] ?? ''));
        if ($siteUrl === '') {
            $siteUrl = 'https://'.$domain.'/';
        }

        $sitemapUrl = trim((string) ($gsc['sitemap_url'] ?? ''));
        if ($sitemapUrl === '') {
            $sitemapUrl = 'https://'.$domain.'/sitemap.xml';
        }

        $deleted = false;
        $lastError = null;

        foreach ($this->accountsForOffer($offer, $settings) as $account) {
            try {
                $accessToken = $this->oauth->accessTokenForAccount($account);
                $this->deleteSitemap($accessToken, $siteUrl, $sitemapUrl);
                if ($this->deleteSite($accessToken, $siteUrl) === 'deleted') {
                    $deleted = true;
                }
            } catch (RuntimeException $e) {
                if ($this->isNotOwnerError($e)) {
                    continue;
                }

                $lastError = $e;
            }
        }

        if ($deleted) {
            return 'deleted';
        }

        if ($lastError) {
            throw $lastError;
        }

        return 'already_gone';
    }

    /**
     * URL Inspection: is this homepage on Google yet.
     *
     * Tries the stored property first, then URL-prefix / sc-domain variants, so
     * a later Google account in the pool can still inspect a site it owns.
     *
     * @return array{
     *     verdict: string,
     *     coverage: string,
     *     indexing_state: string,
     *     last_crawl_at: ?string,
     *     indexed: bool,
     *     site_url: string,
     *     google_canonical: ?string,
     *     user_canonical: ?string,
     *     referring_urls: list<string>
     * }
     */
    public function inspectUrl(Offer $offer, UserSetting|GoogleAccount $source, ?string $siteUrl = null): array
    {
        $domain = strtolower(trim((string) $offer->domain));
        if ($domain === '') {
            throw new RuntimeException('Offer has no domain.');
        }

        $accessToken = $source instanceof GoogleAccount
            ? $this->oauth->accessTokenForAccount($source)
            : $this->oauth->accessTokenFor($source);

        $candidates = $siteUrl !== null && $siteUrl !== ''
            ? [$siteUrl]
            : $this->inspectSiteUrlCandidates($offer, $source);

        $inspectionUrl = 'https://'.$domain.'/';
        $lastError = null;

        foreach ($candidates as $candidate) {
            try {
                $result = $this->inspectUrlAt($accessToken, $inspectionUrl, $candidate);
                $result['site_url'] = $candidate;

                return $result;
            } catch (RuntimeException $e) {
                if (! $this->isNotOwnerError($e)) {
                    throw $e;
                }
                $lastError = $e;
            }
        }

        throw $lastError ?? new RuntimeException('Search Console URL inspection failed: no site property to inspect.');
    }

    /**
     * Site properties this Google account can see, cached briefly so a
     * pending sweep does not list sites once per offer.
     *
     * @return list<array{site_url: string, permission: string}>
     */
    public function listSiteEntries(GoogleAccount $account): array
    {
        $cacheKey = 'gsc.sites.v2.'.$account->id;

        /** @var list<array{site_url: string, permission: string}> $entries */
        $entries = Cache::remember($cacheKey, 1800, function () use ($account): array {
            $accessToken = $this->oauth->accessTokenForAccount($account);
            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->acceptJson()
                ->get('https://www.googleapis.com/webmasters/v3/sites');

            if (! $response->successful()) {
                $this->throwIfRateLimited($response->status(), (string) $response->body(), 'sites.list');

                throw new RuntimeException(
                    'Search Console sites.list failed (HTTP '.$response->status().'): '.$this->shortError((string) $response->body()),
                );
            }

            $raw = $response->json('siteEntry') ?? [];
            $listed = [];
            foreach (is_array($raw) ? $raw : [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $url = trim((string) ($entry['siteUrl'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $listed[] = [
                    'site_url' => $url,
                    'permission' => trim((string) ($entry['permissionLevel'] ?? '')),
                ];
            }

            return $listed;
        });

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function listSiteUrls(GoogleAccount $account): array
    {
        return array_values(array_unique(array_map(
            fn (array $entry) => $entry['site_url'],
            $this->listSiteEntries($account),
        )));
    }

    public static function canInspectPermission(string $permission): bool
    {
        return in_array($permission, ['siteOwner', 'siteFullUser'], true);
    }

    /**
     * Which property on this account covers the offer domain, if any.
     *
     * @return array{site_url: string, permission: string}|null
     */
    public function findSiteOnAccount(GoogleAccount $account, string $domain, ?string $preferred = null): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        try {
            $listed = $this->listSiteEntries($account);
        } catch (RuntimeException $e) {
            if ($this->isNotOwnerError($e) || GoogleOAuthService::isDeadTokenError($e->getMessage())) {
                return null;
            }

            throw $e;
        }

        $candidates = self::siteUrlsForDomain($domain, $preferred);
        foreach ($candidates as $candidate) {
            foreach ($listed as $have) {
                if (strcasecmp($have['site_url'], $candidate) === 0) {
                    return $have;
                }
            }
        }

        foreach ($listed as $have) {
            if (self::siteUrlMatchesDomain($have['site_url'], $domain)) {
                return $have;
            }
        }

        return null;
    }

    /**
     * Inspectable property URL on this account, or null.
     */
    public function resolveSiteUrlForAccount(GoogleAccount $account, string $domain, ?string $preferred = null): ?string
    {
        $found = $this->findSiteOnAccount($account, $domain, $preferred);
        if (! $found || ! self::canInspectPermission($found['permission'])) {
            return null;
        }

        return $found['site_url'];
    }

    /**
     * @return list<string>
     */
    public static function siteUrlsForDomain(string $domain, ?string $preferred = null): array
    {
        $domain = strtolower(trim($domain));
        $urls = [];
        $preferred = trim((string) $preferred);
        if ($preferred !== '') {
            $urls[] = $preferred;
        }
        if ($domain !== '') {
            $urls[] = 'https://'.$domain.'/';
            $urls[] = 'sc-domain:'.$domain;
            $urls[] = 'https://www.'.$domain.'/';
        }

        return array_values(array_unique($urls));
    }

    public static function siteUrlMatchesDomain(string $siteUrl, string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $siteUrl = strtolower(trim($siteUrl));
        if ($domain === '' || $siteUrl === '') {
            return false;
        }

        if ($siteUrl === 'sc-domain:'.$domain) {
            return true;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === $domain;
    }

    /**
     * @return list<string>
     */
    private function inspectSiteUrlCandidates(Offer $offer, UserSetting|GoogleAccount $source): array
    {
        $domain = strtolower(trim((string) $offer->domain));
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];
        $stored = trim((string) ($gsc['site_url'] ?? ''));
        $pinnedId = (int) ($gsc['google_account_id'] ?? 0);
        $pinnedEmail = strtolower(trim((string) ($gsc['email'] ?? '')));

        $preferred = $stored;
        if ($source instanceof GoogleAccount) {
            $sameAccount = ($pinnedId > 0 && $source->id === $pinnedId)
                || ($pinnedEmail !== '' && strtolower((string) $source->email) === $pinnedEmail);
            if (! $sameAccount) {
                $preferred = null;
            }
        }

        return self::siteUrlsForDomain($domain, $preferred);
    }

    /**
     * @return array{
     *     verdict: string,
     *     coverage: string,
     *     indexing_state: string,
     *     last_crawl_at: ?string,
     *     indexed: bool,
     *     google_canonical: ?string,
     *     user_canonical: ?string,
     *     referring_urls: list<string>
     * }
     */
    private function inspectUrlAt(string $accessToken, string $inspectionUrl, string $siteUrl): array
    {
        $response = Http::timeout(30)
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $inspectionUrl,
                'siteUrl' => $siteUrl,
                'languageCode' => 'en-US',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Search Console URL inspection failed (HTTP '.$response->status().'): '.$this->shortError((string) $response->body()),
            );
        }

        $index = $response->json('inspectionResult.indexStatusResult') ?? [];
        $verdict = strtoupper(trim((string) ($index['verdict'] ?? '')));
        $coverage = trim((string) ($index['coverageState'] ?? ''));
        $indexingState = strtoupper(trim((string) ($index['indexingState'] ?? '')));
        $lastCrawl = trim((string) ($index['lastCrawlTime'] ?? ''));
        $googleCanonical = trim((string) ($index['googleCanonical'] ?? ''));
        $userCanonical = trim((string) ($index['userCanonical'] ?? ''));
        $referring = $index['referringUrls'] ?? [];
        $referringUrls = [];
        if (is_array($referring)) {
            foreach ($referring as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $referringUrls[] = trim($url);
                }
            }
        }

        return [
            'verdict' => $verdict,
            'coverage' => $coverage,
            'indexing_state' => $indexingState,
            'last_crawl_at' => $lastCrawl !== '' ? $lastCrawl : null,
            'indexed' => in_array($verdict, ['PASS', 'PARTIAL'], true),
            'google_canonical' => $googleCanonical !== '' ? $googleCanonical : null,
            'user_canonical' => $userCanonical !== '' ? $userCanonical : null,
            'referring_urls' => $referringUrls,
        ];
    }

    public function isNotOwnerError(RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'http 403')
            || str_contains($message, 'http 404')
            || str_contains($message, 'permission')
            || str_contains($message, 'does not have access')
            || str_contains($message, 'not found');
    }

    /**
     * @return list<GoogleAccount>
     */
    private function accountsForOffer(Offer $offer, UserSetting $settings): array
    {
        $accounts = $settings->googleAccountsForInspect();
        if ($accounts->isEmpty()) {
            $primary = $settings->primaryGoogleAccount();

            return $primary ? [$primary] : [];
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $gsc = is_array($meta['gsc'] ?? null) ? $meta['gsc'] : [];
        $pinnedId = (int) ($gsc['google_account_id'] ?? 0);
        $pinnedEmail = strtolower(trim((string) ($gsc['email'] ?? '')));

        return $accounts
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
            ->values()
            ->all();
    }

    public function assertHttpsLive(string $domain): void
    {
        $this->assertPublicDnsResolves($domain);

        if (! $this->httpsResponds($domain) && ! $this->httpsResponds('www.'.$domain)) {
            throw new RuntimeException('HTTPS is not live yet for '.$domain);
        }
    }

    public function assertVerificationFileLive(string $domain, string $filename): void
    {
        $this->assertPublicDnsResolves($domain);

        $filename = ltrim($filename, '/');
        $url = 'https://'.$domain.'/'.$filename;
        try {
            $response = Http::timeout(12)
                ->withOptions(['verify' => true, 'allow_redirects' => true])
                ->get($url);
        } catch (\Throwable $e) {
            throw new RuntimeException('Cannot fetch verification file: '.$e->getMessage());
        }

        if ($response->status() < 200 || $response->status() >= 400) {
            throw new RuntimeException('Verification file HTTP '.$response->status().' at '.$url);
        }

        $body = (string) $response->body();
        if (! str_contains($body, 'google-site-verification')) {
            throw new RuntimeException('Verification file does not look like a GSC HTML token: '.$url);
        }
    }

    /**
     * Google itself resolves public DNS — if the panel can't, GSC verify will fail too.
     */
    private function assertPublicDnsResolves(string $domain): void
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '') {
            throw new RuntimeException('Empty domain.');
        }

        $ips = $this->lookupARecords($domain);
        if ($ips !== []) {
            return;
        }

        $ns = $this->lookupNsRecords($domain);
        if ($ns === []) {
            throw new RuntimeException(
                "Домен {$domain} не резолвиться в публічному DNS (NXDOMAIN). ".
                'Перевір NS у Dynadot → Cloudflare (brianna/weston) і зачекай propagation, потім натисни GSC знову.',
            );
        }

        throw new RuntimeException(
            "Домен {$domain} має NS (".implode(', ', $ns).'), але A-запис ще не видно публічно. Зачекай DNS і спробуй GSC знову.',
        );
    }

    /**
     * @return list<string>
     */
    private function lookupARecords(string $domain): array
    {
        return $this->publicDns->aRecords($domain);
    }

    /**
     * @return list<string>
     */
    private function lookupNsRecords(string $domain): array
    {
        return $this->publicDns->nameservers($domain);
    }

    private function httpsResponds(string $host): bool
    {
        try {
            $response = Http::timeout(10)
                ->withOptions(['verify' => true, 'allow_redirects' => true])
                ->get('https://'.$host.'/');

            return $response->status() >= 200 && $response->status() < 400;
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifySiteFile(string $accessToken, string $siteUrl): bool
    {
        // Ask Google to verify ownership using the HTML file already on the site.
        $response = Http::timeout(30)
            ->withToken($accessToken)
            ->acceptJson()
            ->post('https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod=FILE', [
                'site' => [
                    'type' => 'SITE',
                    'identifier' => $siteUrl,
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        $body = (string) $response->body();
        // Already verified for this Google account.
        if ($response->status() === 400 && (
            str_contains($body, 'already verified')
            || str_contains($body, 'alreadyVerified')
            || str_contains($body, 'Site is already verified')
        )) {
            return true;
        }

        // Some accounts return 409 / ownership exists.
        if (in_array($response->status(), [409, 403], true) && str_contains(strtolower($body), 'already')) {
            return true;
        }

        Log::warning('GSC site verification failed', [
            'site' => $siteUrl,
            'status' => $response->status(),
            'body' => $body,
        ]);

        $this->throwIfRateLimited($response->status(), $body, 'siteVerification');

        throw new RuntimeException('Site Verification FILE failed (HTTP '.$response->status().'): '.$this->shortError($body));
    }

    /**
     * HTML file first. Orange-cloud + Always HTTPS + Browser Integrity Check
     * often hide that file from Google's crawler, so fall back to DNS TXT.
     */
    private function verifySiteOwnership(GoogleAccount $account, Offer $offer, string $siteUrl): bool
    {
        $accessToken = $this->oauth->accessTokenForAccount($account);

        try {
            return $this->verifySiteFile($accessToken, $siteUrl);
        } catch (RuntimeException $e) {
            if (! self::isMissingVerificationTokenError($e->getMessage())) {
                throw $e;
            }
        }

        return $this->verifySiteDnsTxt($account, $offer);
    }

    private function verifySiteDnsTxt(GoogleAccount $account, Offer $offer): bool
    {
        $domain = strtolower(rtrim(trim((string) $offer->domain), '.'));
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $zoneId = trim((string) ($meta['cloudflare_zone_id'] ?? ''));
        if ($domain === '' || $zoneId === '') {
            throw new RuntimeException(
                'GSC FILE-верифікація не пройшла, а DNS TXT неможливий: немає Cloudflare zone на офері.',
            );
        }

        $cfSettings = $this->cloudflareSettingsForOffer($offer);
        if ($cfSettings === null) {
            throw new RuntimeException(
                'GSC FILE-верифікація не пройшла, а DNS TXT неможливий: немає Cloudflare token на офері.',
            );
        }

        $accessToken = $this->oauth->accessTokenForAccount($account);
        $tokenResponse = Http::timeout(20)
            ->withToken($accessToken)
            ->acceptJson()
            ->post('https://www.googleapis.com/siteVerification/v1/token', [
                'verificationMethod' => 'DNS_TXT',
                'site' => [
                    'type' => 'INET_DOMAIN',
                    'identifier' => $domain,
                ],
            ]);

        if (! $tokenResponse->successful()) {
            $this->throwIfRateLimited($tokenResponse->status(), (string) $tokenResponse->body(), 'siteVerification.token');

            throw new RuntimeException(
                'Site Verification DNS token failed (HTTP '.$tokenResponse->status().'): '.$this->shortError((string) $tokenResponse->body()),
            );
        }

        $txt = trim((string) $tokenResponse->json('token'));
        if ($txt === '' || ! str_starts_with($txt, 'google-site-verification=')) {
            throw new RuntimeException('Site Verification DNS token is empty.');
        }

        $this->cloudflare->ensureTxtRecord($cfSettings, $zoneId, $domain, $txt);
        $this->waitForPublicTxt($domain, $txt);

        $response = Http::timeout(30)
            ->withToken($accessToken)
            ->acceptJson()
            ->post('https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod=DNS_TXT', [
                'site' => [
                    'type' => 'INET_DOMAIN',
                    'identifier' => $domain,
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        $body = (string) $response->body();
        if ($response->status() === 400 && (
            str_contains($body, 'already verified')
            || str_contains($body, 'alreadyVerified')
            || str_contains($body, 'Site is already verified')
        )) {
            return true;
        }

        if (in_array($response->status(), [409, 403], true) && str_contains(strtolower($body), 'already')) {
            return true;
        }

        Log::warning('GSC DNS TXT verification failed', [
            'domain' => $domain,
            'status' => $response->status(),
            'body' => $body,
        ]);

        $this->throwIfRateLimited($response->status(), $body, 'siteVerification.dns');

        throw new RuntimeException('Site Verification DNS_TXT failed (HTTP '.$response->status().'): '.$this->shortError($body));
    }

    private function waitForPublicTxt(string $domain, string $expected): void
    {
        $needle = strtolower(trim($expected, " \t\n\r\""));
        for ($i = 0; $i < 8; $i++) {
            foreach ($this->publicDns->txtRecords($domain) as $row) {
                if (str_contains($row, $needle)) {
                    return;
                }
            }

            usleep(1_500_000);
        }
    }

    private function cloudflareSettingsForOffer(Offer $offer): ?UserSetting
    {
        $offer->loadMissing('user.settings');
        $base = $offer->user?->settings;
        if (! $base instanceof UserSetting) {
            return null;
        }

        $token = CloudflareClient::normalizeApiToken(
            filled($offer->cloudflare_api_token) ? (string) $offer->cloudflare_api_token : (string) $base->cloudflare_api_token,
        );
        if ($token === '') {
            return null;
        }

        $clone = $base->replicate();
        $clone->cloudflare_api_token = $token;

        return $clone;
    }

    private function addSite(string $accessToken, string $siteUrl): bool
    {
        $encoded = rawurlencode($siteUrl);
        // sites.add must have an empty body — Laravel Http::put($url) defaults to JSON [].
        $response = $this->putWithoutBody(
            $accessToken,
            'https://www.googleapis.com/webmasters/v3/sites/'.$encoded,
        );

        if ($response->successful() || $response->status() === 204) {
            return true;
        }

        $body = (string) $response->body();
        if ($response->status() === 409 || str_contains(strtolower($body), 'already')) {
            return true;
        }

        $this->throwIfRateLimited($response->status(), $body, 'sites.add');

        if (self::isAccountFullError($body) || self::isAccountFullError((string) $response->status().' '.$body)) {
            throw new RuntimeException('Search Console account is full (sites.add): '.$this->shortError($body));
        }

        // Property may already exist in the account.
        if ($response->status() === 403 && str_contains(strtolower($body), 'permission')) {
            // Try GET — if we can list it, treat as added.
            $get = Http::timeout(20)
                ->withToken($accessToken)
                ->acceptJson()
                ->get('https://www.googleapis.com/webmasters/v3/sites/'.$encoded);
            if ($get->successful()) {
                return true;
            }
        }

        throw new RuntimeException('Search Console sites.add failed (HTTP '.$response->status().'): '.$this->shortError($body));
    }

    private function submitSitemap(string $accessToken, string $siteUrl, string $sitemapUrl): bool
    {
        $encodedSite = rawurlencode($siteUrl);
        $encodedFeed = rawurlencode($sitemapUrl);
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.$encodedSite.'/sitemaps/'.$encodedFeed;

        $response = $this->putWithoutBody($accessToken, $url);
        if ($response->successful() || $response->status() === 204) {
            return true;
        }

        $body = (string) $response->body();
        if ($response->status() === 409 || str_contains(strtolower($body), 'already')) {
            return true;
        }

        // Transient ownership race: property exists but sitemap API still returns 403.
        // Re-add + re-verify, brief wait, then one more submit.
        if ($response->status() === 403 && str_contains(strtolower($body), 'permission')) {
            Log::warning('GSC sitemap 403 — retrying after re-add/verify', [
                'site' => $siteUrl,
                'body' => $body,
            ]);
            $this->addSite($accessToken, $siteUrl);
            $this->verifySiteFile($accessToken, $siteUrl);
            usleep(750_000);

            $retry = $this->putWithoutBody($accessToken, $url);
            if ($retry->successful() || $retry->status() === 204) {
                return true;
            }

            $body = (string) $retry->body();
            if ($retry->status() === 409 || str_contains(strtolower($body), 'already')) {
                return true;
            }

            $this->throwIfRateLimited($retry->status(), $body, 'sitemaps.submit');

            throw new RuntimeException('Search Console sitemaps.submit failed (HTTP '.$retry->status().'): '.$this->shortError($body));
        }

        $this->throwIfRateLimited($response->status(), $body, 'sitemaps.submit');

        throw new RuntimeException('Search Console sitemaps.submit failed (HTTP '.$response->status().'): '.$this->shortError($body));
    }

    /**
     * Best-effort: missing property/sitemap is fine during archive.
     */
    private function deleteSitemap(string $accessToken, string $siteUrl, string $sitemapUrl): void
    {
        $encodedSite = rawurlencode($siteUrl);
        $encodedFeed = rawurlencode($sitemapUrl);
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.$encodedSite.'/sitemaps/'.$encodedFeed;

        try {
            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->acceptJson()
                ->delete($url);

            if ($response->successful() || in_array($response->status(), [204, 404], true)) {
                return;
            }

            Log::info('GSC sitemap delete skipped', [
                'site' => $siteUrl,
                'sitemap' => $sitemapUrl,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 240),
            ]);
        } catch (\Throwable $e) {
            Log::info('GSC sitemap delete skipped', [
                'site' => $siteUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return 'deleted'|'already_gone'
     */
    private function deleteSite(string $accessToken, string $siteUrl): string
    {
        $encoded = rawurlencode($siteUrl);
        $response = Http::timeout(30)
            ->withToken($accessToken)
            ->acceptJson()
            ->delete('https://www.googleapis.com/webmasters/v3/sites/'.$encoded);

        if ($response->successful() || $response->status() === 204) {
            return 'deleted';
        }

        if ($response->status() === 404) {
            return 'already_gone';
        }

        $body = (string) $response->body();
        if (str_contains(strtolower($body), 'not found') || str_contains(strtolower($body), 'does not exist')) {
            return 'already_gone';
        }

        throw new RuntimeException('Search Console sites.delete failed (HTTP '.$response->status().'): '.$this->shortError($body));
    }

    /**
     * Google webmasters PUT endpoints (sites.add / sitemaps.submit) reject any JSON body.
     */
    private function putWithoutBody(string $accessToken, string $url): \Illuminate\Http\Client\Response
    {
        return Http::timeout(30)
            ->withToken($accessToken)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Length' => '0',
            ])
            ->withBody('', 'application/octet-stream')
            ->send('PUT', $url);
    }

    private function throwIfRateLimited(int $status, string $body, string $action): void
    {
        if (self::isAccountFullError($body)) {
            return;
        }

        if ($status === 429 || str_contains(strtolower($body), 'quota exceeded')) {
            throw new RuntimeException('Search Console '.$action.' rate-limited (HTTP 429)');
        }
    }

    public static function isMissingVerificationTokenError(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'necessary verification token could not be found')
            || str_contains($m, 'verification token could not be found on your site');
    }

    public static function isAccountFullError(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'cannot add any more sites')
            || str_contains($m, 'account is full (sites.add)')
            || str_contains($m, 'заповнені в search console')
            || (str_contains($m, 'quotaexceeded') && str_contains($m, 'usagelimits') && str_contains($m, 'sites'));
    }

    /**
     * Settings copy: new offers submit to the primary Google account.
     * Fall through to a "перевірка" account only when that primary is full,
     * or when the site already lives on another connected account.
     */
    public static function deadPrimaryBlocksSubmitFallback(
        GoogleAccount $account,
        ?GoogleAccount $owner,
        GoogleAccount $primary,
    ): bool {
        if ((int) $account->id !== (int) $primary->id) {
            return false;
        }

        return $owner === null || (int) $owner->id === (int) $primary->id;
    }

    /**
     * @return list<GoogleAccount>
     */
    private function accountsForSubmit(UserSetting $settings): array
    {
        $accounts = $settings->googleAccountsForInspect();
        if ($accounts->isEmpty()) {
            $primary = $settings->primaryGoogleAccount();

            return $primary ? [$primary] : [];
        }

        return $accounts
            ->sortBy(fn (GoogleAccount $account) => $account->is_primary ? 0 : 1)
            ->values()
            ->all();
    }

    private function accountLooksFull(GoogleAccount $account): bool
    {
        if (Cache::get('gsc.account_full.'.$account->id)) {
            return true;
        }

        try {
            $count = count($this->listSiteEntries($account));
        } catch (RuntimeException) {
            return false;
        }

        if ($count >= self::SITE_SOFT_LIMIT) {
            $this->rememberAccountFull($account);

            return true;
        }

        return false;
    }

    private function rememberAccountFull(GoogleAccount $account): void
    {
        Cache::put('gsc.account_full.'.$account->id, 1, 86400);
        Cache::forget('gsc.sites.v2.'.$account->id);
    }

    private function shortError(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return substr($body, 0, 240);
    }
}
