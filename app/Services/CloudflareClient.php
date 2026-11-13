<?php

namespace App\Services;

use App\Models\UserSetting;
use App\Support\SecretValue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareClient
{
    public static function normalizeApiToken(?string $token): string
    {
        $token = SecretValue::normalize($token);

        if ($token === '') {
            return '';
        }

        if (preg_match('/^authorization\s*:\s*bearer\s+/i', $token)) {
            $token = preg_replace('/^authorization\s*:\s*bearer\s+/i', '', $token);
        }

        if (preg_match('/^bearer\s+/i', $token)) {
            $token = preg_replace('/^bearer\s+/i', '', $token);
        }

        return preg_replace('/\s+/', '', $token) ?? '';
    }

    /**
     * @return array{ok: bool, message: string, zones?: int}
     */
    public function testConnection(UserSetting $settings): array
    {
        $token = self::normalizeApiToken($settings->cloudflare_api_token);

        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'Вкажіть Cloudflare API token (не Global API Key).',
            ];
        }

        try {
            $response = $this->request($settings, 'GET', '/user/tokens/verify');
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }

        if (! ($response['success'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Cloudflare: '.$this->extractError($response),
            ];
        }

        $zones = 0;

        try {
            $listed = $this->request($settings, 'GET', '/zones', ['per_page' => 1]);
            $zones = (int) ($listed['result_info']['total_count'] ?? 0);
        } catch (RuntimeException) {
            // Token verify passed — zones list may fail on narrow permissions.
        }

        return [
            'ok' => true,
            'message' => 'Cloudflare API token валідний.'.($zones > 0 ? " Зон у акаунті: {$zones}." : ''),
            'zones' => $zones,
        ];
    }

    /**
     * @return array{zone_id: string, nameservers: list<string>, status: string}
     */
    public function ensureZone(UserSetting $settings, string $domain): array
    {
        $token = self::normalizeApiToken($settings->cloudflare_api_token);
        $accountId = trim((string) $settings->cloudflare_account_id);

        if ($token === '' || $accountId === '') {
            throw new RuntimeException('Збережіть Cloudflare API token і Account ID у налаштуваннях.');
        }

        $existing = $this->findZone($settings, $domain);

        if ($existing !== null) {
            $status = strtolower((string) ($existing['status'] ?? ''));
            // A "moved" zone keeps the old NS pair in the API but CF no longer
            // terminates TLS for it — landers come back HTTP 403 / handshake fail.
            if (in_array($status, ['moved', 'deleted'], true)) {
                $this->deleteZone($settings, (string) ($existing['zone_id'] ?? ''));
                $existing = null;
            }
        }

        if ($existing !== null) {
            return $existing;
        }

        try {
            $response = $this->request($settings, 'POST', '/zones', [
                'name' => $domain,
                'account' => ['id' => $accountId],
                'jump_start' => false,
            ]);
        } catch (RuntimeException $e) {
            throw new RuntimeException(self::humanizeZoneCreateError($e->getMessage()), 0, $e);
        }

        if (! ($response['success'] ?? false)) {
            $message = $this->extractError($response);

            if (str_contains(strtolower($message), 'already exists')) {
                $existing = $this->findZoneByName($settings, $domain);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new RuntimeException(self::humanizeZoneCreateError('Cloudflare zone: '.$message));
        }

        /** @var array<string, mixed> $result */
        $result = $response['result'] ?? [];

        return $this->zoneSummary($result);
    }

    public function ensureRootARecord(UserSetting $settings, string $zoneId, string $domain, string $ip): void
    {
        // Lander zones should only have our apex/www A records — drop parking,
        // leftover AAAA/CNAME/TXT/ACME junk that causes CF warnings and conflicts.
        $this->purgeZoneDnsRecords($settings, $zoneId);

        $proxied = (bool) ($settings->cloudflare_default_proxied ?? true);
        $this->ensureARecord($settings, $zoneId, $domain, '@', $ip, $proxied);
        $this->ensureARecord($settings, $zoneId, $domain, 'www', $ip, $proxied);
    }

    /**
     * Delete every DNS record in the zone (paginated). Safe to call before recreating A records.
     *
     * @return int Number of deleted records
     */
    public function purgeZoneDnsRecords(UserSetting $settings, string $zoneId): int
    {
        $zoneId = trim($zoneId);

        if ($zoneId === '') {
            return 0;
        }

        $deleted = 0;
        $guard = 0;

        // Always re-fetch page 1: deletes shift pagination.
        while ($guard < 50) {
            $guard++;
            $response = $this->request($settings, 'GET', '/zones/'.$zoneId.'/dns_records', [
                'per_page' => 100,
                'page' => 1,
            ]);

            /** @var list<array<string, mixed>> $records */
            $records = is_array($response['result'] ?? null) ? $response['result'] : [];

            if ($records === []) {
                break;
            }

            $round = 0;

            foreach ($records as $record) {
                $id = trim((string) ($record['id'] ?? ''));

                if ($id === '') {
                    continue;
                }

                $this->request($settings, 'DELETE', '/zones/'.$zoneId.'/dns_records/'.$id);
                $deleted++;
                $round++;
            }

            if ($round === 0) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listARecords(UserSetting $settings, string $zoneId, string $name): array
    {
        $response = $this->request($settings, 'GET', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => $name,
        ]);

        /** @var list<array<string, mixed>> */
        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    public function setARecordsProxied(UserSetting $settings, string $zoneId, string $domain, bool $proxied): void
    {
        foreach ([$domain, 'www.'.$domain] as $name) {
            foreach ($this->listARecords($settings, $zoneId, $name) as $record) {
                if (($record['type'] ?? '') !== 'A') {
                    continue;
                }

                $this->request($settings, 'PUT', '/zones/'.$zoneId.'/dns_records/'.($record['id'] ?? ''), [
                    'type' => 'A',
                    'name' => ($record['name'] ?? '') === $domain ? '@' : 'www',
                    'content' => $record['content'] ?? '',
                    'proxied' => $proxied,
                    'ttl' => 1,
                ]);
            }
        }
    }

    /**
     * Edge SSL + HTTPS + www→apex via Cloudflare (без Let's Encrypt на origin).
     *
     * @param  array{cloudflare_ssl?: bool, cloudflare_https?: bool, cloudflare_www_redirect?: bool}  $options
     */
    public function configureEdgeSecurity(UserSetting $settings, string $zoneId, string $domain, array $options = []): void
    {
        if ($zoneId === '') {
            throw new RuntimeException('Cloudflare zone ID порожній.');
        }

        $ssl = $options['cloudflare_ssl'] ?? true;
        $https = $options['cloudflare_https'] ?? true;
        $www = $options['cloudflare_www_redirect'] ?? true;

        if ($ssl) {
            $this->setZoneSetting(
                $settings,
                $zoneId,
                'ssl',
                $this->preferredSslMode($settings, $domain),
            );
        }

        if ($https) {
            $this->setZoneSetting($settings, $zoneId, 'always_use_https', 'on');
        }

        if ($www) {
            $this->ensureWwwRedirectRule($settings, $zoneId, $domain);
        }
    }

    /**
     * @return array{zone_id: string, nameservers: list<string>, status: string}|null
     */
    public function findZone(UserSetting $settings, string $domain): ?array
    {
        return $this->findZoneByName($settings, $domain);
    }

    /**
     * @return 'deleted'|'already_missing'
     */
    public function deleteZone(UserSetting $settings, string $zoneId): string
    {
        $zoneId = trim($zoneId);

        if ($zoneId === '') {
            return 'already_missing';
        }

        try {
            $response = $this->request($settings, 'DELETE', '/zones/'.$zoneId);
        } catch (RuntimeException $e) {
            if ($this->isZoneAbsentError($e)) {
                return 'already_missing';
            }

            throw $e;
        }

        if (! ($response['success'] ?? false)) {
            $message = $this->extractError($response);
            if ($this->isZoneAbsentMessage($message)) {
                return 'already_missing';
            }

            throw new RuntimeException('Cloudflare delete zone: '.$message);
        }

        return 'deleted';
    }

    public function isZoneAbsentError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        if (preg_match('/Cloudflare HTTP (400|404)\b/i', $message) === 1) {
            return true;
        }

        return $this->isZoneAbsentMessage($message);
    }

    public function isUnauthorizedError(\Throwable $e): bool
    {
        return preg_match('/Cloudflare HTTP 403\b/i', $e->getMessage()) === 1;
    }

    public function isZoneAbsentMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'invalid zone identifier')
            || str_contains($lower, 'zone could not be found')
            || str_contains($lower, 'could not find zone')
            || str_contains($lower, 'zone not found');
    }

    private function setZoneSetting(UserSetting $settings, string $zoneId, string $setting, string $value): void
    {
        $response = $this->request($settings, 'PATCH', '/zones/'.$zoneId.'/settings/'.$setting, [
            'value' => $value,
        ]);

        if (! ($response['success'] ?? false)) {
            throw new RuntimeException('Cloudflare '.$setting.': '.$this->extractError($response));
        }
    }

    /**
     * Full — якщо origin HTTPS відповідає нормально.
     * Flexible — якщо origin HTTPS редіректить на HTTP (типово origin без LE),
     * інакше Full + Always Use HTTPS дає ERR_TOO_MANY_REDIRECTS.
     */
    private function preferredSslMode(UserSetting $settings, string $domain): string
    {
        $hostIp = trim((string) ($settings->deploy_host ?? ''));

        if ($hostIp === '') {
            return 'flexible';
        }

        try {
            $response = Http::timeout(8)
                ->withOptions([
                    'verify' => false,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [$domain.':443:'.$hostIp],
                    ],
                ])
                ->get('https://'.$domain.'/');

            $status = $response->status();
            $location = strtolower((string) $response->header('Location'));

            if ($status >= 200 && $status < 400 && ! str_starts_with($location, 'http://')) {
                return 'full';
            }
        } catch (\Throwable) {
            // Origin ще може бути недоступним під час першого provision.
        }

        return 'flexible';
    }

    private function ensureWwwRedirectRule(UserSetting $settings, string $zoneId, string $domain): void
    {
        $wwwHost = 'www.'.$domain;
        $description = 'Offerra: www to apex';

        $this->upsertDynamicRedirectRule($settings, $zoneId, $description, [
            'description' => $description,
            'expression' => sprintf('(http.host eq "%s")', $wwwHost),
            'action' => 'redirect',
            'action_parameters' => [
                'from_value' => [
                    'status_code' => 301,
                    'preserve_query_string' => true,
                    'target_url' => [
                        'expression' => sprintf('concat("https://%s", http.request.uri.path)', $domain),
                    ],
                ],
            ],
        ]);
    }

    public function removeDynamicRedirectRule(UserSetting $settings, string $zoneId, string $description): void
    {
        $description = trim($description);
        if ($zoneId === '' || $description === '') {
            return;
        }

        $existing = $this->request(
            $settings,
            'GET',
            '/zones/'.$zoneId.'/rulesets/phases/http_request_dynamic_redirect/entrypoint',
            [],
            allowNotFound: true,
        );

        if (! ($existing['success'] ?? false) || ! is_array($existing['result'] ?? null)) {
            return;
        }

        /** @var list<array<string, mixed>> $rules */
        $rules = is_array($existing['result']['rules'] ?? null) ? $existing['result']['rules'] : [];
        $filtered = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => ($rule['description'] ?? '') !== $description,
        ));

        if (count($filtered) === count($rules)) {
            return;
        }

        $this->putDynamicRedirectRules($settings, $zoneId, $filtered);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function upsertDynamicRedirectRule(
        UserSetting $settings,
        string $zoneId,
        string $description,
        array $rule,
    ): void {
        $existing = $this->request(
            $settings,
            'GET',
            '/zones/'.$zoneId.'/rulesets/phases/http_request_dynamic_redirect/entrypoint',
            [],
            allowNotFound: true,
        );

        /** @var list<array<string, mixed>> $rules */
        $rules = [];
        if (($existing['success'] ?? false) && is_array($existing['result'] ?? null)) {
            $rules = is_array($existing['result']['rules'] ?? null) ? $existing['result']['rules'] : [];
            $rules = array_values(array_filter(
                $rules,
                static fn (array $item): bool => ($item['description'] ?? '') !== $description,
            ));
        }

        $rules[] = $rule;
        $this->putDynamicRedirectRules($settings, $zoneId, $rules);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function putDynamicRedirectRules(UserSetting $settings, string $zoneId, array $rules): void
    {
        $response = $this->request(
            $settings,
            'PUT',
            '/zones/'.$zoneId.'/rulesets/phases/http_request_dynamic_redirect/entrypoint',
            ['rules' => $rules],
            allowNotFound: true,
        );

        if ($response['success'] ?? false) {
            return;
        }

        $create = $this->request($settings, 'POST', '/zones/'.$zoneId.'/rulesets', [
            'name' => 'Offerra redirects',
            'kind' => 'zone',
            'phase' => 'http_request_dynamic_redirect',
            'rules' => $rules,
        ]);

        if (! ($create['success'] ?? false)) {
            throw new RuntimeException('Cloudflare dynamic redirect: '.$this->extractError($create));
        }
    }

    private function ensureARecord(
        UserSetting $settings,
        string $zoneId,
        string $domain,
        string $label,
        string $ip,
        bool $proxied,
    ): void {
        $name = $label === '@' ? $domain : 'www.'.$domain;
        $records = $this->listARecords($settings, $zoneId, $name);

        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A') {
                if (($record['content'] ?? '') === $ip && (bool) ($record['proxied'] ?? false) === $proxied) {
                    return;
                }

                $this->request($settings, 'PUT', '/zones/'.$zoneId.'/dns_records/'.($record['id'] ?? ''), [
                    'type' => 'A',
                    'name' => $label,
                    'content' => $ip,
                    'proxied' => $proxied,
                    'ttl' => 1,
                ]);

                return;
            }
        }

        $this->request($settings, 'POST', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => $label,
            'content' => $ip,
            'proxied' => $proxied,
            'ttl' => 1,
        ]);
    }

    /**
     * Apex TXT used by Google siteVerification DNS_TXT. Never proxied.
     */
    public function ensureTxtRecord(UserSetting $settings, string $zoneId, string $domain, string $content): void
    {
        $zoneId = trim($zoneId);
        $domain = strtolower(rtrim(trim($domain), '.'));
        $content = trim($content);
        if ($zoneId === '' || $domain === '' || $content === '') {
            throw new RuntimeException('Cloudflare TXT: zone, domain and content are required.');
        }

        $response = $this->request($settings, 'GET', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'TXT',
            'name' => $domain,
            'per_page' => 100,
        ]);

        /** @var list<array<string, mixed>> $records */
        $records = is_array($response['result'] ?? null) ? $response['result'] : [];

        foreach ($records as $record) {
            $existing = trim((string) ($record['content'] ?? ''), " \t\n\r\0\x0B\"");
            if ($existing === $content) {
                return;
            }

            $id = trim((string) ($record['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            if (str_starts_with($existing, 'google-site-verification=')
                && str_starts_with($content, 'google-site-verification=')) {
                $this->request($settings, 'PUT', '/zones/'.$zoneId.'/dns_records/'.$id, [
                    'type' => 'TXT',
                    'name' => $domain,
                    'content' => $content,
                    'proxied' => false,
                    'ttl' => 60,
                ]);

                return;
            }
        }

        $this->request($settings, 'POST', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'TXT',
            'name' => $domain,
            'content' => $content,
            'proxied' => false,
            'ttl' => 60,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDnsRecords(UserSetting $settings, string $zoneId, string $domain): array
    {
        return $this->listARecords($settings, $zoneId, $domain);
    }

    /**
     * @return array{zone_id: string, nameservers: list<string>, status: string}|null
     */
    private function findZoneByName(UserSetting $settings, string $domain): ?array
    {
        $response = $this->request($settings, 'GET', '/zones', [
            'name' => $domain,
            'status' => 'active',
        ]);

        /** @var list<array<string, mixed>> $zones */
        $zones = is_array($response['result'] ?? null) ? $response['result'] : [];
        $zone = $zones[0] ?? null;

        if (! is_array($zone)) {
            $response = $this->request($settings, 'GET', '/zones', ['name' => $domain]);
            $zones = is_array($response['result'] ?? null) ? $response['result'] : [];
            $zone = $zones[0] ?? null;
        }

        if (! is_array($zone)) {
            return null;
        }

        return $this->zoneSummary($zone);
    }

    /**
     * @param  array<string, mixed>  $zone
     * @return array{zone_id: string, nameservers: list<string>, status: string}
     */
    private function zoneSummary(array $zone): array
    {
        return [
            'zone_id' => (string) ($zone['id'] ?? ''),
            'nameservers' => array_values(array_filter(array_map(
                static fn ($ns) => strtolower(trim((string) $ns)),
                $zone['name_servers'] ?? [],
            ))),
            'status' => strtolower(trim((string) ($zone['status'] ?? ''))),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(
        UserSetting $settings,
        string $method,
        string $path,
        array $body = [],
        bool $allowNotFound = false,
    ): array {
        $token = self::normalizeApiToken($settings->cloudflare_api_token);

        if ($token === '') {
            throw new RuntimeException('Збережіть Cloudflare API token у налаштуваннях.');
        }

        $url = 'https://api.cloudflare.com/client/v4'.$path;

        $pending = Http::timeout(45)
            ->withToken($token)
            ->acceptJson();

        $response = match (strtoupper($method)) {
            'GET' => $pending->get($url, $body),
            'POST' => $pending->post($url, $body),
            'PUT' => $pending->put($url, $body),
            'PATCH' => $pending->patch($url, $body),
            'DELETE' => $pending->delete($url, $body),
            default => throw new RuntimeException('Unsupported Cloudflare method: '.$method),
        };

        if ($response->failed()) {
            if ($allowNotFound && $response->status() === 404) {
                return ['success' => false, 'errors' => [['message' => 'Not found']]];
            }

            $payload = $response->json();
            $detail = is_array($payload) ? $this->extractError($payload) : '';

            throw new RuntimeException(
                'Cloudflare HTTP '.$response->status().($detail !== '' && $detail !== 'невідома помилка' ? ' — '.$detail : '').' ('.$method.' '.$path.')',
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return $payload;
    }

    public static function humanizeZoneCreateError(string $message): string
    {
        $m = strtolower($message);

        if (str_contains($m, 'web property cannot be added')
            || str_contains($m, 'abusereply@cloudflare.com')) {
            return 'Cloudflare заблокував цей домен на всіх акаунтах. Зміна Cloudflare не створить зону — потрібен інший домен.';
        }

        if (str_contains($m, 'not allowed to create new zones')) {
            return 'Цей Cloudflare-акаунт більше не створює нові зони. Обери інший акаунт.';
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractError(array $response): string
    {
        $errors = $response['errors'] ?? [];

        if (is_array($errors) && isset($errors[0]['message'])) {
            return (string) $errors[0]['message'];
        }

        return 'невідома помилка';
    }
}
