<?php

namespace Tests\Unit;

use App\Models\UserSetting;
use App\Services\DynadotClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DynadotPurchaseTest extends TestCase
{
    public function test_get_account_balance_parses_usd_balance(): void
    {
        Http::fake([
            'https://api.dynadot.com/api3.json*' => Http::response([
                'GetAccountBalanceResponse' => [
                    'ResponseCode' => 0,
                    'Status' => 'success',
                    'BalanceList' => [
                        ['Currency' => 'USD', 'Amount' => '42.50'],
                    ],
                ],
            ]),
        ]);

        $balance = app(DynadotClient::class)->getAccountBalance($this->makeSettings());

        $this->assertSame('42.50', $balance['balances'][0]['amount']);
        $this->assertSame('USD', $balance['balances'][0]['currency']);
        $this->assertSame(42.5, $balance['usd']);
        $this->assertSame('USD', $balance['payment_currency']);
        $this->assertSame(42.5, $balance['payment_amount']);
        $this->assertFalse($balance['low_balance']);
    }

    public function test_get_account_balance_uses_eur_when_usd_empty(): void
    {
        Http::fake([
            'https://api.dynadot.com/api3.json*' => Http::response([
                'GetAccountBalanceResponse' => [
                    'ResponseCode' => 0,
                    'Status' => 'success',
                    'BalanceList' => [
                        ['Currency' => 'EUR', 'Amount' => '100.00'],
                    ],
                ],
            ]),
        ]);

        $balance = app(DynadotClient::class)->getAccountBalance($this->makeSettings());

        $this->assertNull($balance['usd']);
        $this->assertSame('EUR', $balance['payment_currency']);
        $this->assertSame(100.0, $balance['payment_amount']);
        $this->assertFalse($balance['low_balance']);
    }

    public function test_pick_payment_currency_prefers_usd_then_eur(): void
    {
        $client = app(DynadotClient::class);

        $this->assertSame('EUR', $client->pickPaymentCurrency([
            ['currency' => 'EUR', 'amount' => '100.00'],
        ]));
        $this->assertSame('USD', $client->pickPaymentCurrency([
            ['currency' => 'USD', 'amount' => '5.00'],
            ['currency' => 'EUR', 'amount' => '100.00'],
        ]));
        $this->assertSame('EUR', $client->pickPaymentCurrency([
            ['currency' => 'USD', 'amount' => '0.00'],
            ['currency' => 'EUR', 'amount' => '100.00'],
        ]));
    }

    public function test_register_returns_success_payload(): void
    {
        Http::fake([
            'https://api.dynadot.com/api3.json*' => function ($request) {
                $query = $request->data();

                if (($query['command'] ?? '') === 'get_account_balance') {
                    return Http::response([
                        'GetAccountBalanceResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                            'BalanceList' => [
                                ['Currency' => 'USD', 'Amount' => '50.00'],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'search') {
                    return Http::response([
                        'SearchResponse' => [
                            'ResponseCode' => '0',
                            'SearchResults' => [
                                [
                                    'DomainName' => 'brand-new.online',
                                    'Available' => 'yes',
                                    'Price' => '2.50 in USD',
                                ],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'set_renew_option') {
                    return Http::response([
                        'SetRenewOptionResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                        ],
                    ]);
                }

                return Http::response([
                    'RegisterResponse' => [
                        'ResponseCode' => 0,
                        'Status' => 'success',
                        'DomainName' => 'brand-new.online',
                        'Expiration' => 1458379145266,
                    ],
                ]);
            },
        ]);

        $result = app(DynadotClient::class)->register($this->makeSettings(), 'brand-new.online');

        $this->assertTrue($result['ok']);
        $this->assertSame('brand-new.online', $result['domain']);
        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['auto_renew_disabled']);
    }

    public function test_register_charges_eur_when_only_eur_balance(): void
    {
        $seenCurrency = null;

        Http::fake([
            'https://api.dynadot.com/api3.json*' => function ($request) use (&$seenCurrency) {
                $query = $request->data();

                if (($query['command'] ?? '') === 'get_account_balance') {
                    return Http::response([
                        'GetAccountBalanceResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                            'BalanceList' => [
                                ['Currency' => 'EUR', 'Amount' => '100.00'],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'search') {
                    $this->assertSame('EUR', $query['currency'] ?? null);

                    return Http::response([
                        'SearchResponse' => [
                            'ResponseCode' => '0',
                            'SearchResults' => [
                                [
                                    'DomainName' => 'brand-new.online',
                                    'Available' => 'yes',
                                    'Price' => '2.30 in EUR',
                                ],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'set_renew_option') {
                    return Http::response([
                        'SetRenewOptionResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'register') {
                    $seenCurrency = $query['currency'] ?? null;
                }

                return Http::response([
                    'RegisterResponse' => [
                        'ResponseCode' => 0,
                        'Status' => 'success',
                        'DomainName' => 'brand-new.online',
                        'Expiration' => 1458379145266,
                    ],
                ]);
            },
        ]);

        $result = app(DynadotClient::class)->register($this->makeSettings(), 'brand-new.online');

        $this->assertTrue($result['ok']);
        $this->assertSame('EUR', $seenCurrency);
    }

    public function test_register_strips_c_prefix_from_contact_id(): void
    {
        Http::fake([
            'https://api.dynadot.com/api3.json*' => function ($request) {
                $query = $request->data();

                if (($query['command'] ?? '') === 'get_account_balance') {
                    return Http::response([
                        'GetAccountBalanceResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                            'BalanceList' => [
                                ['Currency' => 'USD', 'Amount' => '50.00'],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'search') {
                    return Http::response([
                        'SearchResponse' => [
                            'ResponseCode' => '0',
                            'SearchResults' => [
                                [
                                    'DomainName' => 'brand-new.online',
                                    'Available' => 'yes',
                                    'Price' => '2.50 in USD',
                                ],
                            ],
                        ],
                    ]);
                }

                if (($query['command'] ?? '') === 'set_renew_option') {
                    return Http::response([
                        'SetRenewOptionResponse' => [
                            'ResponseCode' => 0,
                            'Status' => 'success',
                        ],
                    ]);
                }

                $this->assertSame('1885528', $query['registrant_contact'] ?? null);

                return Http::response([
                    'RegisterResponse' => [
                        'ResponseCode' => 0,
                        'Status' => 'success',
                        'DomainName' => 'brand-new.online',
                        'Expiration' => 1458379145266,
                    ],
                ]);
            },
        ]);

        $settings = $this->makeSettings(['dynadot_contact_id' => 'C-1885528']);
        $result = app(DynadotClient::class)->register($settings, 'brand-new.online');

        $this->assertTrue($result['ok']);
    }

    public function test_normalize_contact_id(): void
    {
        $this->assertSame('1885528', DynadotClient::normalizeContactId('C-1885528'));
        $this->assertSame('1885528', DynadotClient::normalizeContactId('c-1885528'));
        $this->assertSame('0', DynadotClient::normalizeContactId('0'));
    }

    public function test_register_requires_contact_id(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact ID');

        app(DynadotClient::class)->register($this->makeSettings(['dynadot_contact_id' => '']), 'brand-new.online');
    }

    public function test_set_ns_retries_tls_blip_then_succeeds(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException(
                    'cURL error 35: TLS connect error for https://api.dynadot.com/api3.json?key=super-secret&command=set_ns',
                );
            }

            return Http::response([
                'SetNsResponse' => [
                    'ResponseCode' => '0',
                    'Status' => 'success',
                ],
            ]);
        });

        app(DynadotClient::class)->setNameservers(
            $this->makeSettings(),
            'capttrusteau.online',
            ['linda.ns.cloudflare.com', 'weston.ns.cloudflare.com'],
        );

        $this->assertSame(3, $attempts);
    }

    public function test_set_ns_tls_failure_does_not_leak_api_key(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 35: unexpected eof while reading for https://api.dynadot.com/api3.json?key=super-secret&command=set_ns',
            );
        });

        try {
            app(DynadotClient::class)->setNameservers(
                $this->makeSettings(),
                'capttrusteau.online',
                ['linda.ns.cloudflare.com', 'weston.ns.cloudflare.com'],
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('super-secret', $e->getMessage());
            $this->assertTrue(DynadotClient::isRetryableNsError($e->getMessage()));
        }
    }

    public function test_redact_secrets_strips_key_query(): void
    {
        $raw = 'cURL error 35 for https://api.dynadot.com/api3.json?key=dummy-secret&command=set_ns';

        $this->assertSame(
            'cURL error 35 for https://api.dynadot.com/api3.json?key=REDACTED&command=set_ns',
            DynadotClient::redactSecrets($raw),
        );
    }

    public function test_set_ns_stops_quickly_while_domain_is_still_initializing(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response([
                'SetNsResponse' => [
                    'ResponseCode' => '-1',
                    'Status' => 'error',
                    'Error' => 'Cannot set nameservers to these domains ( example.com : Domain initialization is still in progress. Please try again later.)',
                ],
            ]);
        });

        try {
            app(DynadotClient::class)->setNameservers(
                $this->makeSettings(),
                'example.com',
                ['linda.ns.cloudflare.com', 'weston.ns.cloudflare.com'],
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertTrue(DynadotClient::isNsNotReadyError($e->getMessage()));
        }

        $this->assertSame(2, $attempts);
    }

    public function test_park_nameservers_sets_dynadot_parking_ns(): void
    {
        Http::fake([
            'https://api.dynadot.com/api3.json*' => Http::response([
                'SetNsResponse' => [
                    'ResponseCode' => '0',
                    'Status' => 'success',
                ],
            ]),
        ]);

        app(DynadotClient::class)->parkNameservers($this->makeSettings(), 'yieldario-ai.org');

        $this->assertTrue(DynadotClient::parkingDropsFromTld('solaraaitrade.online'));
        $this->assertFalse(DynadotClient::parkingDropsFromTld('solaraaitrade.live'));
        $this->assertFalse(DynadotClient::parkingDropsFromTld('yieldario-ai.org'));

        Http::assertSent(function ($request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['command'] ?? null) === 'set_ns'
                && ($query['domain'] ?? null) === 'yieldario-ai.org'
                && ($query['ns0'] ?? null) === 'ns1.dynadot.com'
                && ($query['ns1'] ?? null) === 'ns2.dynadot.com';
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSettings(array $overrides = []): UserSetting
    {
        $settings = new UserSetting(array_merge([
            'dynadot_api_key' => 'test-api-key',
            'dynadot_sandbox' => false,
            'dynadot_default_years' => 1,
            'dynadot_contact_id' => '999',
        ], $overrides));

        $settings->exists = true;

        return $settings;
    }
}
