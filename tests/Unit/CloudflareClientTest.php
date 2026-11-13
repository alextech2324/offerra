<?php

namespace Tests\Unit;

use App\Models\UserSetting;
use App\Services\CloudflareClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareClientTest extends TestCase
{
    public function test_configure_edge_security_sets_ssl_https_and_www_redirect(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/ssl' => Http::response([
                'success' => true,
                'result' => ['id' => 'ssl', 'value' => 'flexible'],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/always_use_https' => Http::response([
                'success' => true,
                'result' => ['id' => 'always_use_https', 'value' => 'on'],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone123/rulesets/phases/http_request_dynamic_redirect/entrypoint' => Http::sequence()
                ->push(['success' => true, 'result' => ['rules' => []]])
                ->push(['success' => true, 'result' => ['rules' => []]]),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        (new CloudflareClient)->configureEdgeSecurity($settings, 'zone123', 'example.com', [
            'cloudflare_ssl' => true,
            'cloudflare_https' => true,
            'cloudflare_www_redirect' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/settings/ssl'
                && $request->method() === 'PATCH'
                && ($request->data()['value'] ?? '') === 'flexible';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/settings/always_use_https'
                && ($request->data()['value'] ?? '') === 'on';
        });

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'http_request_dynamic_redirect/entrypoint')) {
                return false;
            }

            $rules = $request->data()['rules'] ?? [];

            return ($rules[0]['expression'] ?? '') === '(http.host eq "www.example.com")';
        });
    }

    public function test_configure_edge_security_creates_www_redirect_when_entrypoint_missing(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/ssl' => Http::response([
                'success' => true,
                'result' => ['id' => 'ssl', 'value' => 'flexible'],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone123/settings/always_use_https' => Http::response([
                'success' => true,
                'result' => ['id' => 'always_use_https', 'value' => 'on'],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone123/rulesets/phases/http_request_dynamic_redirect/entrypoint' => Http::sequence()
                ->push([], 404)
                ->push([], 404),
            'https://api.cloudflare.com/client/v4/zones/zone123/rulesets' => Http::response([
                'success' => true,
                'result' => ['id' => 'ruleset-new'],
            ]),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        (new CloudflareClient)->configureEdgeSecurity($settings, 'zone123', 'example.com', [
            'cloudflare_ssl' => true,
            'cloudflare_https' => true,
            'cloudflare_www_redirect' => true,
        ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/rulesets'
                && ($request->data()['phase'] ?? '') === 'http_request_dynamic_redirect';
        });
    }

    public function test_ensure_zone_recreates_moved_zone(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/zones') && str_contains($url, 'status=active')) {
                return Http::response(['success' => true, 'result' => []]);
            }

            if ($method === 'GET' && str_contains($url, '/zones') && str_contains($url, 'name=moved.example')) {
                return Http::response([
                    'success' => true,
                    'result' => [[
                        'id' => 'old-zone',
                        'status' => 'moved',
                        'name_servers' => ['julian.ns.cloudflare.com', 'shaz.ns.cloudflare.com'],
                    ]],
                ]);
            }

            if ($method === 'DELETE' && str_ends_with($url, '/zones/old-zone')) {
                return Http::response(['success' => true, 'result' => ['id' => 'old-zone']]);
            }

            if ($method === 'POST' && $url === 'https://api.cloudflare.com/client/v4/zones') {
                return Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'new-zone',
                        'status' => 'pending',
                        'name_servers' => ['julian.ns.cloudflare.com', 'shaz.ns.cloudflare.com'],
                    ],
                ]);
            }

            return Http::response(['success' => false], 500);
        });

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        $zone = (new CloudflareClient)->ensureZone($settings, 'moved.example');

        $this->assertSame('new-zone', $zone['zone_id']);
        $this->assertSame('pending', $zone['status']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/zones/old-zone'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.cloudflare.com/client/v4/zones');
    }

    public function test_ensure_txt_record_creates_apex_google_verification(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => []]);
            }

            if ($request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/dns_records')) {
                return Http::response(['success' => true, 'result' => ['id' => 'txt1']]);
            }

            return Http::response(['success' => false], 500);
        });

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        (new CloudflareClient)->ensureTxtRecord(
            $settings,
            'zone123',
            'example.com',
            'google-site-verification=abc',
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/dns_records'
                && ($request->data()['type'] ?? '') === 'TXT'
                && ($request->data()['name'] ?? '') === 'example.com'
                && ($request->data()['content'] ?? '') === 'google-site-verification=abc'
                && ($request->data()['proxied'] ?? true) === false;
        });
    }

    public function test_ensure_txt_record_skips_when_already_present(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone123/dns_records*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'txt1',
                    'type' => 'TXT',
                    'content' => 'google-site-verification=abc',
                ]],
            ]),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        (new CloudflareClient)->ensureTxtRecord(
            $settings,
            'zone123',
            'example.com',
            'google-site-verification=abc',
        );

        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT'], true)
            && str_contains($request->url(), '/dns_records')
            && ! str_contains($request->url(), '?'));
    }

    public function test_delete_zone_treats_invalid_identifier_as_already_missing(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/stale-zone' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid zone identifier']],
            ], 400),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        $result = (new CloudflareClient)->deleteZone($settings, 'stale-zone');

        $this->assertSame('already_missing', $result);
    }

    public function test_delete_zone_returns_deleted_on_success(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone123' => Http::response([
                'success' => true,
                'result' => ['id' => 'zone123'],
            ], 200),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        $result = (new CloudflareClient)->deleteZone($settings, 'zone123');

        $this->assertSame('deleted', $result);
    }

    public function test_normalize_api_token_strips_bearer_prefix_and_whitespace(): void
    {
        $this->assertSame(
            'abc123-token',
            CloudflareClient::normalizeApiToken("Bearer \r\nabc123-token\n"),
        );
    }

    public function test_test_connection_returns_ok_when_token_valid(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [],
                'result_info' => ['total_count' => 3],
            ]),
        ]);

        $settings = new UserSetting([
            'cloudflare_api_token' => 'Bearer cf-token',
            'cloudflare_account_id' => 'acct',
        ]);

        $result = (new CloudflareClient)->testConnection($settings);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('валідний', $result['message']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/user/tokens/verify'
                && $request->header('Authorization')[0] === 'Bearer cf-token';
        });
    }
}
