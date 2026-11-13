<?php

namespace App\Services;

use App\Models\UserSetting;
use App\Support\DomainName;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DynadotClient
{
    private const REQUEST_GAP_MICROSECONDS = 180_000;

    /** Prefer these when picking which account credit to spend. */
    private const PAYMENT_CURRENCY_PRIORITY = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];

    /** Minimum credit considered usable for domain purchase / price display. */
    private const MIN_USABLE_BALANCE = 1.0;

    /** Warn in UI when spendable credit is below this (same units as payment currency). */
    private const LOW_BALANCE_THRESHOLD = 15.0;

    /** Dynadot parking / default NS — used when an offer is archived. */
    public const PARKING_NAMESERVERS = [
        'ns1.dynadot.com',
        'ns2.dynadot.com',
    ];

    /**
     * CentralNic TLDs drop the name from the TLD zone when NS move to Dynadot
     * parking, and a later set_ns back to Cloudflare often stays NXDOMAIN
     * (server hold). Archive must not park these — deleting the CF zone is
     * enough to take the lander down, and restore can recreate the zone on
     * the same NS.
     *
     * @var list<string>
     */
    private const PARKING_DROPS_TLDS = ['.online', '.cfd'];

    public static function parkingDropsFromTld(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        foreach (self::PARKING_DROPS_TLDS as $tld) {
            if (str_ends_with($domain, $tld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>
     */
    public function search(UserSetting $settings, string $query): array
    {
        $apiKey = trim((string) $settings->dynadot_api_key);

        if ($apiKey === '') {
            throw new RuntimeException('Збережіть Dynadot API key у налаштуваннях.');
        }

        $domains = $this->expandQuery($query);

        if ($domains === []) {
            return [];
        }

        $currency = $this->resolvePaymentCurrency($settings);

        if (count($domains) === 1) {
            $row = $this->searchOne($settings, $apiKey, $domains[0], $currency);
            $this->throwOnGlobalApiError($row, (bool) $settings->dynadot_sandbox);

            return $this->sortSearchResultsByPriceDesc([$row]);
        }

        $batch = $this->searchBatch($settings, $apiKey, $domains, $currency);
        if ($batch !== null) {
            return $this->sortSearchResultsByPriceDesc($batch);
        }

        return $this->sortSearchResultsByPriceDesc(
            $this->searchMany($settings, $apiKey, $domains, $currency),
        );
    }

    /**
     * Sort by registration price descending; rows without a parseable price go last.
     *
     * @param  list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>  $results
     * @return list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>
     */
    public function sortSearchResultsByPriceDesc(array $results): array
    {
        $tldOrder = [];
        foreach (config('offerra.domain_search_tlds', []) as $index => $tld) {
            $tldOrder[ltrim((string) $tld, '.')] = $index;
        }

        usort($results, function (array $a, array $b) use ($tldOrder): int {
            $priceA = $this->parsePriceAmount($a['price'] ?? null);
            $priceB = $this->parsePriceAmount($b['price'] ?? null);

            if ($priceA === null && $priceB === null) {
                return $this->compareDomainsByTldPreference(
                    (string) ($a['domain'] ?? ''),
                    (string) ($b['domain'] ?? ''),
                    $tldOrder,
                );
            }

            if ($priceA === null) {
                return 1;
            }

            if ($priceB === null) {
                return -1;
            }

            if ($priceA === $priceB) {
                return $this->compareDomainsByTldPreference(
                    (string) ($a['domain'] ?? ''),
                    (string) ($b['domain'] ?? ''),
                    $tldOrder,
                );
            }

            return $priceB <=> $priceA;
        });

        return array_values($results);
    }

    /**
     * @param  array<string, int>  $tldOrder
     */
    private function compareDomainsByTldPreference(string $domainA, string $domainB, array $tldOrder): int
    {
        $rankA = $this->tldPreferenceRank($domainA, $tldOrder);
        $rankB = $this->tldPreferenceRank($domainB, $tldOrder);

        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strcmp($domainA, $domainB);
    }

    /**
     * @param  array<string, int>  $tldOrder
     */
    private function tldPreferenceRank(string $domain, array $tldOrder): int
    {
        $domain = strtolower(trim($domain));
        $dot = strrpos($domain, '.');
        if ($dot === false) {
            return PHP_INT_MAX;
        }

        $tld = substr($domain, $dot + 1);

        return $tldOrder[$tld] ?? (1000 + ord($tld[0] ?? 'z'));
    }

    public function parsePriceAmount(?string $price): ?float
    {
        if ($price === null || trim($price) === '') {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)/', $price, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /**
     * @return array{
     *     balances: list<array{currency: string, amount: string}>,
     *     usd: ?float,
     *     payment_currency: string,
     *     payment_amount: ?float,
     *     low_balance: bool
     * }
     */
    public function getAccountBalance(UserSetting $settings): array
    {
        $apiKey = $this->requireApiKey($settings);
        $payload = $this->apiGet($settings, $apiKey, ['command' => 'get_account_balance']);

        return $this->parseAccountBalance($payload, (bool) $settings->dynadot_sandbox);
    }

    /**
     * @return array{
     *     ok: bool,
     *     domain: string,
     *     status: string,
     *     expiration: ?int,
     *     message: ?string,
     *     price: ?string,
     *     auto_renew_disabled: bool
     * }
     */
    public function register(UserSetting $settings, string $domain, ?int $years = null): array
    {
        $apiKey = $this->requireApiKey($settings);
        $domain = DomainName::normalize($domain);

        if ($domain === '') {
            throw new RuntimeException('Невірний домен.');
        }

        $duration = 1;
        $contactId = self::normalizeContactId((string) ($settings->dynadot_contact_id ?? ''));

        if ($contactId === '') {
            throw new RuntimeException('Вкажіть Dynadot Contact ID у налаштуваннях (Мои домены → Контактные записи).');
        }

        $currency = $this->resolvePaymentCurrency($settings);
        $availability = $this->searchOne($settings, $apiKey, $domain, $currency);
        $this->throwOnGlobalApiError($availability, (bool) $settings->dynadot_sandbox);

        if (! $availability['available']) {
            return [
                'ok' => false,
                'domain' => $domain,
                'status' => $availability['status'],
                'expiration' => null,
                'message' => $availability['status'] === 'taken'
                    ? 'Домен уже зайнятий.'
                    : ($availability['message'] ?? 'Домен недоступний для реєстрації.'),
                'price' => $availability['price'],
                'auto_renew_disabled' => false,
            ];
        }

        $params = [
            'command' => 'register',
            'domain' => $domain,
            'duration' => $duration,
            'currency' => $currency,
            'registrant_contact' => $contactId,
            'admin_contact' => $contactId,
            'technical_contact' => $contactId,
            'billing_contact' => $contactId,
        ];

        $payload = $this->apiGet($settings, $apiKey, $params);

        $result = $this->parseRegisterResult($domain, $payload, (bool) $settings->dynadot_sandbox, $availability['price']);

        if (! $result['ok']) {
            return $result;
        }

        $autoRenewDisabled = $this->disableAutoRenew($settings, $apiKey, $result['domain']);
        $result['auto_renew_disabled'] = $autoRenewDisabled;

        if (! $autoRenewDisabled) {
            $result['message'] = 'Домен зареєстровано, але не вдалося вимкнути автопродовження в Dynadot. Перевірте вручну.';
        }

        return $result;
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(UserSetting $settings, string $domain, array $nameservers): void
    {
        $apiKey = $this->requireApiKey($settings);
        $domain = DomainName::normalize($domain);
        $nameservers = array_values(array_filter(array_map(
            static fn (string $ns) => strtolower(trim($ns)),
            $nameservers,
        )));

        if ($domain === '' || $nameservers === []) {
            throw new RuntimeException('Немає nameservers для оновлення Dynadot.');
        }

        $lock = Cache::lock('dynadot-account-'.md5($apiKey), 90);

        try {
            // Must stay below RecheckInfrastructureDnsJob timeout, otherwise the
            // waiting worker is killed and the unique lock sits for uniqueFor.
            $lock->block(25);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Dynadot зайнятий — спробуйте ще раз');
        }

        try {
            $this->setNameserversUnlocked($settings, $apiKey, $domain, $nameservers);
        } finally {
            $lock->release();
        }
    }

    /**
     * Point the domain at Dynadot parking so leftover Cloudflare NS stop answering.
     */
    public function parkNameservers(UserSetting $settings, string $domain): void
    {
        $this->setNameservers($settings, $domain, self::PARKING_NAMESERVERS);
    }

    /**
     * @param  list<string>  $nameservers
     */
    private function setNameserversUnlocked(
        UserSetting $settings,
        string $apiKey,
        string $domain,
        array $nameservers,
    ): void {
        $params = [
            'command' => 'set_ns',
            'domain' => $domain,
        ];

        foreach ($nameservers as $index => $nameserver) {
            $params["ns{$index}"] = $nameserver;
        }

        $lastError = 'Dynadot set_ns error';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $payload = $this->apiGet($settings, $apiKey, $params);
            $apiError = $this->extractPayloadError($payload, (bool) $settings->dynadot_sandbox);

            if ($apiError !== null) {
                $lastError = $apiError;

                if ($this->shouldRetrySetNs($apiError, $attempt)) {
                    $this->waitBeforeSetNsRetry($apiError, $attempt);
                    continue;
                }

                throw new RuntimeException($apiError);
            }

            $response = $payload['SetNsResponse'] ?? $payload['setNsResponse'] ?? null;

            if (! is_array($response)) {
                throw new RuntimeException('Неочікувана відповідь Dynadot (set_ns)');
            }

            $responseCode = (string) ($response['ResponseCode'] ?? $response['SuccessCode'] ?? '');
            $status = strtolower((string) ($response['Status'] ?? ''));

            if ($responseCode === '0' || $status === 'success') {
                return;
            }

            $lastError = $this->humanizeApiError(
                (string) ($response['Error'] ?? 'Dynadot set_ns error'),
                (bool) $settings->dynadot_sandbox,
            );

            if ($this->shouldRetrySetNs($lastError, $attempt)) {
                $this->waitBeforeSetNsRetry($lastError, $attempt);
                continue;
            }

            throw new RuntimeException($lastError);
        }

        throw new RuntimeException($lastError);
    }

    public static function isNsNotReadyError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'initialization is still in progress')
            || str_contains($normalized, 'domain initialization');
    }

    /**
     * Domain cannot have NS changed (disabled / expired / not in the account).
     * Archive should skip parking instead of staying in teardown_failed.
     */
    public static function isUnmanageableDomainError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'was disabled')
            || str_contains($normalized, 'is disabled')
            || str_contains($normalized, 'has been disabled')
            || str_contains($normalized, 'вимкнено в dynadot')
            || str_contains($normalized, 'not in your account')
            || str_contains($normalized, 'no such domain')
            || (str_contains($normalized, 'expired') && str_contains($normalized, 'domain'));
    }

    public static function isTransientTransportError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'curl error 28')
            || str_contains($normalized, 'curl error 35')
            || str_contains($normalized, 'curl error 52')
            || str_contains($normalized, 'curl error 56')
            || str_contains($normalized, 'unexpected eof')
            || str_contains($normalized, 'connection reset')
            || str_contains($normalized, 'connection timed out')
            || str_contains($normalized, 'ssl routines')
            || str_contains($normalized, 'tls connect')
            || str_contains($normalized, 'тимчасово недоступний');
    }

    /**
     * set_ns can wait — do not fail the whole infra run for a Dynadot blip.
     */
    public static function isRetryableNsError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return self::isNsNotReadyError($message)
            || self::isTransientTransportError($message)
            || str_contains($normalized, 'зайнятий')
            || str_contains($normalized, 'system_busy')
            || str_contains($normalized, 'currently processing');
    }

    public static function redactSecrets(string $message): string
    {
        $message = preg_replace('/([?&]key=)[^&\s]+/i', '$1REDACTED', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key["\'\s:=]+)[^\s&"\']+/i', '$1REDACTED', $message) ?? $message;

        return $message;
    }

    private function shouldRetrySetNs(string $message, int $attempt): bool
    {
        // Fresh regs become settable within a minute or two — the DNS recheck
        // loop covers that. Five long sleeps here block the whole default queue.
        if (self::isNsNotReadyError($message)) {
            return $attempt < 2;
        }

        if ($attempt >= 5) {
            return false;
        }

        return $this->isBusyApiError($message);
    }

    private function waitBeforeSetNsRetry(string $message, int $attempt): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (self::isNsNotReadyError($message)) {
            sleep(3);

            return;
        }

        usleep(600_000 * $attempt);
    }

    private function disableAutoRenew(UserSetting $settings, string $apiKey, string $domain): bool
    {
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($this->setRenewOption($settings, $apiKey, $domain, 'donot')) {
                    return true;
                }
            } catch (RuntimeException) {
                // Retry below when Dynadot is busy.
            }

            if ($attempt < $maxAttempts) {
                usleep(450_000 * $attempt);
            }
        }

        return false;
    }

    private function setRenewOption(UserSetting $settings, string $apiKey, string $domain, string $option): bool
    {
        $payload = $this->apiGet($settings, $apiKey, [
            'command' => 'set_renew_option',
            'domain' => $domain,
            'renew_option' => $option,
        ]);

        return $this->parseSetRenewOptionResult($payload, (bool) $settings->dynadot_sandbox);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseSetRenewOptionResult(array $payload, bool $sandbox): bool
    {
        $apiError = $this->extractPayloadError($payload, $sandbox);

        if ($apiError !== null) {
            if ($this->isBusyApiError($apiError)) {
                throw new RuntimeException($apiError);
            }

            return false;
        }

        $response = $payload['SetRenewOptionResponse'] ?? $payload['setRenewOptionResponse'] ?? null;

        if (! is_array($response)) {
            return false;
        }

        $responseCode = (string) ($response['ResponseCode'] ?? $response['SuccessCode'] ?? '');
        $status = strtolower((string) ($response['Status'] ?? ''));

        return $responseCode === '0' || $status === 'success';
    }

    private function requireApiKey(UserSetting $settings): string
    {
        $apiKey = trim((string) $settings->dynadot_api_key);

        if ($apiKey === '') {
            throw new RuntimeException('Збережіть Dynadot API key у налаштуваннях.');
        }

        return $apiKey;
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    private function apiGet(UserSetting $settings, string $apiKey, array $params): array
    {
        return $this->requestJson($settings, [
            'key' => $apiKey,
            ...$params,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    private function requestJson(UserSetting $settings, array $params, int $timeout = 25): array
    {
        $url = $this->apiBaseUrl((bool) $settings->dynadot_sandbox);
        $lastError = 'Dynadot HTTP error';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(8)
                    ->get($url, $params);

                if ($response->failed()) {
                    $lastError = 'Dynadot HTTP '.$response->status();
                    if ($response->serverError() && $attempt < 4) {
                        $this->sleepBeforeRetry($attempt);
                        continue;
                    }

                    throw new RuntimeException($lastError);
                }

                /** @var array<string, mixed> $payload */
                $payload = $response->json() ?? [];

                if ($payload === []) {
                    throw new RuntimeException('Порожня відповідь Dynadot');
                }

                return $payload;
            } catch (RuntimeException $e) {
                throw $e;
            } catch (ConnectionException|\Throwable $e) {
                $lastError = $this->transportErrorMessage($e);
                if ($attempt < 4 && self::isTransientTransportError($e->getMessage())) {
                    $this->sleepBeforeRetry($attempt);
                    continue;
                }

                throw new RuntimeException($lastError);
            }
        }

        throw new RuntimeException($lastError);
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        if (app()->environment('testing')) {
            return;
        }

        usleep(400_000 * $attempt);
    }

    private function transportErrorMessage(\Throwable $e): string
    {
        $raw = self::redactSecrets($e->getMessage());

        if (self::isTransientTransportError($raw)) {
            return 'Dynadot API тимчасово недоступний (TLS/мережа). Повтор через хвилину.';
        }

        return 'Dynadot API: '.mb_substr($raw, 0, 180);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     balances: list<array{currency: string, amount: string}>,
     *     usd: ?float,
     *     payment_currency: string,
     *     payment_amount: ?float,
     *     low_balance: bool
     * }
     */
    private function parseAccountBalance(array $payload, bool $sandbox): array
    {
        $apiError = $this->extractPayloadError($payload, $sandbox);

        if ($apiError !== null) {
            throw new RuntimeException($apiError);
        }

        $balanceResponse = $payload['GetAccountBalanceResponse']
            ?? $payload['getAccountBalanceResponse']
            ?? null;

        if (! is_array($balanceResponse)) {
            throw new RuntimeException('Неочікувана відповідь Dynadot (balance)');
        }

        $responseCode = (string) ($balanceResponse['ResponseCode'] ?? $balanceResponse['SuccessCode'] ?? '');
        $status = strtolower((string) ($balanceResponse['Status'] ?? ''));

        if ($responseCode !== '' && $responseCode !== '0' && $status === 'error') {
            throw new RuntimeException($this->humanizeApiError(
                (string) ($balanceResponse['Error'] ?? 'Dynadot balance error'),
                $sandbox,
            ));
        }

        $balances = [];
        $balanceList = $balanceResponse['BalanceList'] ?? $balanceResponse['balanceList'] ?? [];

        if (is_array($balanceList)) {
            $rows = isset($balanceList['Currency']) || isset($balanceList['Amount'])
                ? [$balanceList]
                : $balanceList;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $currency = strtoupper(trim((string) ($row['Currency'] ?? $row['currency'] ?? '')));
                $amount = trim((string) ($row['Amount'] ?? $row['amount'] ?? ''));

                if ($currency !== '' && $amount !== '') {
                    $balances[] = [
                        'currency' => $currency,
                        'amount' => $amount,
                    ];
                }
            }
        }

        $amounts = $this->balanceAmountsByCurrency($balances);
        $usd = $amounts['USD'] ?? null;
        $paymentCurrency = $this->pickPaymentCurrency($balances);
        $paymentAmount = $amounts[$paymentCurrency] ?? null;

        return [
            'balances' => $balances,
            'usd' => $usd,
            'payment_currency' => $paymentCurrency,
            'payment_amount' => $paymentAmount,
            'low_balance' => $paymentAmount === null || $paymentAmount < self::LOW_BALANCE_THRESHOLD,
        ];
    }

    /**
     * Currency used for search prices + register charges (matches available account credit).
     */
    public function resolvePaymentCurrency(UserSetting $settings): string
    {
        try {
            return $this->getAccountBalance($settings)['payment_currency'];
        } catch (\Throwable) {
            return 'USD';
        }
    }

    /**
     * @param  list<array{currency: string, amount: string}>  $balances
     */
    public function pickPaymentCurrency(array $balances): string
    {
        $amounts = $this->balanceAmountsByCurrency($balances);

        foreach (self::PAYMENT_CURRENCY_PRIORITY as $code) {
            if (($amounts[$code] ?? 0.0) >= self::MIN_USABLE_BALANCE) {
                return $code;
            }
        }

        foreach ($amounts as $code => $amount) {
            if ($amount >= self::MIN_USABLE_BALANCE) {
                return $code;
            }
        }

        return 'USD';
    }

    /**
     * @param  list<array{currency: string, amount: string}>  $balances
     * @return array<string, float>
     */
    private function balanceAmountsByCurrency(array $balances): array
    {
        $amounts = [];

        foreach ($balances as $balance) {
            $currency = strtoupper(trim((string) ($balance['currency'] ?? '')));
            if ($currency === '') {
                continue;
            }

            $amounts[$currency] = (float) str_replace(',', '', (string) ($balance['amount'] ?? '0'));
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     ok: bool,
     *     domain: string,
     *     status: string,
     *     expiration: ?int,
     *     message: ?string,
     *     price: ?string,
     *     auto_renew_disabled: bool
     * }
     */
    private function parseRegisterResult(string $domain, array $payload, bool $sandbox, ?string $price): array
    {
        $apiError = $this->extractPayloadError($payload, $sandbox);

        if ($apiError !== null) {
            return [
                'ok' => false,
                'domain' => $domain,
                'status' => 'error',
                'expiration' => null,
                'message' => $apiError,
                'price' => $price,
                'auto_renew_disabled' => false,
            ];
        }

        $registerResponse = $payload['RegisterResponse'] ?? $payload['registerResponse'] ?? null;

        if (! is_array($registerResponse)) {
            return [
                'ok' => false,
                'domain' => $domain,
                'status' => 'error',
                'expiration' => null,
                'message' => 'Неочікувана відповідь Dynadot (register)',
                'price' => $price,
                'auto_renew_disabled' => false,
            ];
        }

        $responseCode = (string) ($registerResponse['ResponseCode'] ?? $registerResponse['SuccessCode'] ?? '');
        $status = strtolower((string) ($registerResponse['Status'] ?? ''));

        if ($responseCode === '0' || $status === 'success') {
            $expiration = $registerResponse['Expiration'] ?? $registerResponse['expiration'] ?? null;

            return [
                'ok' => true,
                'domain' => strtolower((string) ($registerResponse['DomainName'] ?? $domain)),
                'status' => 'success',
                'expiration' => is_numeric($expiration) ? (int) $expiration : null,
                'message' => null,
                'price' => $price,
                'auto_renew_disabled' => false,
            ];
        }

        $error = (string) ($registerResponse['Error'] ?? $registerResponse['Message'] ?? 'Помилка реєстрації');

        return [
            'ok' => false,
            'domain' => $domain,
            'status' => $status !== '' ? $status : 'error',
            'expiration' => null,
            'message' => $this->humanizeRegisterError($error),
            'price' => $price,
            'auto_renew_disabled' => false,
        ];
    }

    public static function normalizeContactId(string $contactId): string
    {
        $contactId = trim($contactId);

        if ($contactId === '') {
            return '';
        }

        if (preg_match('/^c-(\d+)$/i', $contactId, $matches) === 1) {
            return $matches[1];
        }

        return $contactId;
    }

    private function humanizeRegisterError(string $message): string
    {
        $normalized = strtolower(trim($message));

        return match (true) {
            str_contains($normalized, 'invalid registrant_contact'),
            str_contains($normalized, 'invalid admin_contact'),
            str_contains($normalized, 'invalid contact') => 'Невірний Contact ID. Вставте лише цифри (наприклад 1885528), без префікса C-.',
            str_contains($normalized, 'insufficient') => 'Недостатньо коштів на балансі Dynadot. Поповніть акаунт у тій же валюті, що й ціна домену (USD/EUR).',
            str_contains($normalized, 'currency') && str_contains($normalized, 'match') => 'Валюта балансу Dynadot не збігається з валютою замовлення. У Account Settings оберіть EUR/USD відповідно до балансу.',
            str_contains($normalized, 'not_available'),
            str_contains($normalized, 'not available') => 'Домен уже зайнятий.',
            str_contains($normalized, 'invalid contact') => 'Невірний Contact ID у налаштуваннях Dynadot.',
            default => $message,
        };
    }

    /**
     * @return list<string>
     */
    public function expandQuery(string $query): array
    {
        $raw = strtolower(trim($query));
        $raw = preg_replace('#^https?://#i', '', $raw) ?? $raw;
        $raw = preg_replace('~[/?#].*$~', '', $raw) ?? $raw;
        $raw = rtrim($raw, '.');

        if ($raw === '') {
            return [];
        }

        $tlds = config('offerra.domain_search_tlds', ['com', 'org', 'net']);
        $domains = [];

        if (str_contains($raw, '.')) {
            $ascii = DomainName::normalize($raw);
            $ascii = preg_replace('/^www\./', '', $ascii) ?? $ascii;

            return $ascii !== '' ? [$ascii] : [];
        } else {
            $slug = preg_replace('/[^a-z0-9-]/', '', $raw) ?? '';

            foreach ($tlds as $tld) {
                if ($slug !== '') {
                    $domains[] = $slug.'.'.ltrim($tld, '.');
                }
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * @param  list<string>  $domains
     * @return list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>|null
     */
    private function searchBatch(UserSetting $settings, string $apiKey, array $domains, string $currency = 'USD'): ?array
    {
        $sandbox = (bool) $settings->dynadot_sandbox;
        $params = [
            'key' => $apiKey,
            'command' => 'search',
            'show_price' => 1,
            'currency' => $currency,
        ];

        foreach (array_values($domains) as $index => $domain) {
            $params["domain{$index}"] = $domain;
        }

        try {
            $payload = $this->requestJson($settings, $params, 25);
        } catch (RuntimeException) {
            return null;
        }

        if ($payload === []) {
            return null;
        }

        return $this->parseBatchSearchResult($domains, $payload, $sandbox);
    }

    /**
     * @param  list<string>  $domains
     * @return list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>
     */
    private function searchMany(UserSetting $settings, string $apiKey, array $domains, string $currency = 'USD'): array
    {
        $sandbox = (bool) $settings->dynadot_sandbox;
        $results = [];

        foreach ($domains as $index => $domain) {
            if ($index > 0) {
                usleep(self::REQUEST_GAP_MICROSECONDS);
            }

            $row = $this->searchOneWithRetry($settings, $apiKey, $domain, $currency);
            $this->throwOnGlobalApiError($row, $sandbox);
            $results[] = $row;
        }

        return $results;
    }

    /**
     * @return array{domain: string, available: bool, price: ?string, status: string, message: ?string}
     */
    private function searchOneWithRetry(UserSetting $settings, string $apiKey, string $domain, string $currency = 'USD'): array
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $row = $this->searchOne($settings, $apiKey, $domain, $currency);

            if ($row['status'] !== 'error' || ! $this->isBusyApiError($row['message'])) {
                return $row;
            }

            if ($attempt < $maxAttempts) {
                usleep(450_000 * $attempt);
            }
        }

        return $row;
    }

    private function isBusyApiError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'currently processing')
            || str_contains($normalized, 'another request')
            || str_contains($normalized, 'system_busy');
    }

    /**
     * @param  array{domain: string, available: bool, price: ?string, status: string, message: ?string}  $row
     */
    private function throwOnGlobalApiError(array $row, bool $sandbox): void
    {
        if ($row['status'] === 'error' && $this->isGlobalApiError($row['message'])) {
            throw new RuntimeException($this->humanizeApiError((string) $row['message'], $sandbox));
        }
    }

    private function apiBaseUrl(bool $sandbox): string
    {
        return $sandbox
            ? 'https://api-sandbox.dynadot.com/api3.json'
            : 'https://api.dynadot.com/api3.json';
    }

    /**
     * @return array{domain: string, available: bool, price: ?string, status: string, message: ?string}
     */
    private function searchOne(UserSetting $settings, string $apiKey, string $domain, string $currency = 'USD'): array
    {
        try {
            $payload = $this->requestJson($settings, [
                'key' => $apiKey,
                'command' => 'search',
                'domain0' => $domain,
                'show_price' => 1,
                'currency' => $currency,
            ], 25);
        } catch (RuntimeException $e) {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        if ($payload === []) {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => 'Порожня відповідь Dynadot',
            ];
        }

        return $this->parseSearchResult($domain, $payload, (bool) $settings->dynadot_sandbox);
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $payload
     * @return list<array{domain: string, available: bool, price: ?string, status: string, message: ?string}>|null
     */
    private function parseBatchSearchResult(array $domains, array $payload, bool $sandbox): ?array
    {
        $apiError = $this->extractPayloadError($payload, $sandbox);

        if ($apiError !== null) {
            if ($this->isGlobalApiError($apiError)) {
                throw new RuntimeException($this->humanizeApiError($apiError, $sandbox));
            }

            return null;
        }

        $searchResponse = $payload['SearchResponse'] ?? $payload['searchResponse'] ?? null;

        if (! is_array($searchResponse)) {
            return null;
        }

        $responseCode = (string) ($searchResponse['ResponseCode'] ?? $searchResponse['SuccessCode'] ?? '');

        if ($responseCode !== '' && $responseCode !== '0') {
            return null;
        }

        $rows = $this->collectSearchRows($searchResponse);

        if ($rows === [] || count($rows) < count($domains)) {
            return null;
        }

        $byName = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower((string) ($row['DomainName'] ?? ''));

            if ($name !== '') {
                $byName[$name] = $this->mapSearchRow($name, $row);
            }
        }

        $results = [];

        foreach ($domains as $domain) {
            $key = strtolower($domain);
            $results[] = $byName[$key] ?? [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => 'Немає в відповіді Dynadot',
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{domain: string, available: bool, price: ?string, status: string, message: ?string}
     */
    private function parseSearchResult(string $domain, array $payload, bool $sandbox = false): array
    {
        $apiError = $this->extractPayloadError($payload, $sandbox);

        if ($apiError !== null) {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => $apiError,
            ];
        }

        $searchResponse = $payload['SearchResponse'] ?? $payload['searchResponse'] ?? null;

        if (! is_array($searchResponse)) {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => 'Неочікувана відповідь Dynadot',
            ];
        }

        $responseCode = (string) ($searchResponse['ResponseCode'] ?? $searchResponse['SuccessCode'] ?? '');
        $status = strtolower((string) ($searchResponse['Status'] ?? ''));

        if ($responseCode !== '' && $responseCode !== '0' && $status === 'error') {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => $this->humanizeApiError((string) ($searchResponse['Error'] ?? 'Dynadot error'), $sandbox),
            ];
        }

        $rows = $this->collectSearchRows($searchResponse);
        $row = $rows[0] ?? null;

        if ($row === null) {
            return [
                'domain' => $domain,
                'available' => false,
                'price' => null,
                'status' => 'error',
                'message' => 'Порожня відповідь',
            ];
        }

        return $this->mapSearchRow($domain, $row);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractPayloadError(array $payload, bool $sandbox = false): ?string
    {
        $response = $payload['Response'] ?? $payload['response'] ?? null;

        if (! is_array($response)) {
            return null;
        }

        $code = (string) ($response['ResponseCode'] ?? $response['SuccessCode'] ?? '');

        if ($code === '' || $code === '0') {
            return null;
        }

        return $this->humanizeApiError((string) ($response['Error'] ?? 'Dynadot error'), $sandbox);
    }

    private function isGlobalApiError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'invalid key')
            || str_contains($normalized, 'api key')
            || str_contains($normalized, 'not enabled')
            || str_contains($normalized, 'over quota')
            || str_contains($normalized, 'sandbox');
    }

    private function humanizeApiError(string $message, bool $sandbox = false): string
    {
        $normalized = strtolower(trim($message));

        return match (true) {
            str_contains($normalized, 'invalid key') && $sandbox => 'Невірний ключ для Sandbox. Вставте ключ з вкладки «Ключ песочницы» або вимкніть Sandbox.',
            str_contains($normalized, 'invalid key') => 'Невірний Dynadot API key. Вставте Production Key (не Secret Key) і збережіть налаштування.',
            str_contains($normalized, 'not enabled') => 'Dynadot API не увімкнено в акаунті.',
            str_contains($normalized, 'over quota') => 'Перевищено ліміт запитів Dynadot API.',
            str_contains($normalized, 'was disabled')
                || str_contains($normalized, 'is disabled')
                || str_contains($normalized, 'has been disabled') => 'Домен вимкнено в Dynadot, NS змінити не можна.',
            default => $message,
        };
    }

    /**
     * @param  array<string, mixed>  $searchResponse
     * @return list<array<string, mixed>>
     */
    private function collectSearchRows(array $searchResponse): array
    {
        $rows = [];
        $results = $searchResponse['SearchResults'] ?? $searchResponse['SearchResult'] ?? null;

        if (is_array($results)) {
            if (isset($results['DomainName']) || isset($results['Available'])) {
                $rows[] = $results;
            } else {
                foreach ($results as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        $header = $searchResponse['SearchHeader'] ?? null;

        if (is_array($header) && (isset($header['DomainName']) || isset($header['Available']))) {
            $rows[] = $header;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{domain: string, available: bool, price: ?string, status: string, message: ?string}
     */
    private function mapSearchRow(string $domain, array $row): array
    {
        $name = strtolower((string) ($row['DomainName'] ?? $domain));
        $availableRaw = strtolower((string) ($row['Available'] ?? $row['available'] ?? 'no'));
        $available = in_array($availableRaw, ['yes', 'available', '1', 'true'], true);
        $price = $this->extractPrice($row);

        $statusKey = match (true) {
            $available => 'available',
            in_array($availableRaw, ['no', 'unavailable', '0', 'false'], true) => 'taken',
            in_array($availableRaw, ['offline', 'system_busy', 'over_quota'], true) => $availableRaw,
            $availableRaw === 'error' => 'error',
            default => $availableRaw !== '' ? $availableRaw : 'unknown',
        };

        $message = null;
        if ($statusKey === 'error') {
            $message = $this->humanizeSearchMessage((string) ($row['Error'] ?? $row['MoreInfo'] ?? 'Помилка реєстру'));
        }

        return [
            'domain' => $name !== '' ? $name : $domain,
            'available' => $available,
            'price' => $price,
            'status' => $statusKey,
            'message' => $message,
        ];
    }

    private function humanizeSearchMessage(string $message): string
    {
        $normalized = strtolower(trim($message));

        return match (true) {
            str_contains($normalized, 'currently processing'),
            str_contains($normalized, 'another request') => 'Dynadot зайнятий — спробуйте ще раз',
            str_contains($normalized, 'system_busy') => 'Реєстр тимчасово перевантажений',
            default => $message,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractPrice(array $row): ?string
    {
        foreach (['Price', 'RegistrationPrice', 'price'] as $key) {
            if (! empty($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }
}
