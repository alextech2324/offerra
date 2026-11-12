<?php

namespace Tests\Unit;

use App\Support\PublicDnsLookup;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicDnsLookupTest extends TestCase
{
    public function test_uses_public_doh_ns_and_does_not_need_local_stub(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response([
                'Status' => 0,
                'Answer' => [
                    ['data' => 'pam.ns.cloudflare.com.'],
                    ['data' => 'randy.ns.cloudflare.com.'],
                ],
            ]),
        ]);

        $ns = (new PublicDnsLookup)->nameservers('nezertronixpro-ar.cfd');

        $this->assertSame(['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'], $ns);
    }

    public function test_public_nxdomain_is_empty_not_stale_local(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response([
                'Status' => 3,
                'Answer' => [],
            ]),
        ]);

        $this->assertSame([], (new PublicDnsLookup)->nameservers('nezertronixpro-ar.online'));
        $this->assertSame([], (new PublicDnsLookup)->aRecords('nezertronixpro-ar.online'));
    }

    public function test_falls_back_to_google_doh_when_cloudflare_fails(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response('nope', 502),
            'https://dns.google/resolve*' => Http::response([
                'Status' => 0,
                'Answer' => [
                    ['data' => '104.21.58.45'],
                    ['data' => '172.67.156.56'],
                ],
            ]),
        ]);

        $ips = (new PublicDnsLookup)->aRecords('nezertronixpro-ar.cfd');

        $this->assertSame(['104.21.58.45', '172.67.156.56'], $ips);
    }

    public function test_stale_cloudflare_doh_ns_pair_loses_to_google_new_pair(): void
    {
        Http::fake(function ($request) {
            $type = (string) ($request->data()['type'] ?? '');
            if (str_contains($request->url(), 'dns-query')) {
                if ($type === 'NS') {
                    return Http::response([
                        'Status' => 0,
                        'Answer' => [
                            ['data' => 'otto.ns.cloudflare.com.'],
                            ['data' => 'reza.ns.cloudflare.com.'],
                        ],
                    ]);
                }

                return Http::response([
                    'Status' => 0,
                    'Answer' => [
                        ['data' => '172.67.164.165'],
                        ['data' => '104.21.74.240'],
                    ],
                ]);
            }

            if ($type === 'NS') {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [
                        ['data' => 'augustus.ns.cloudflare.com.'],
                        ['data' => 'lina.ns.cloudflare.com.'],
                    ],
                ]);
            }

            return Http::response([
                'Status' => 0,
                'Answer' => [
                    ['data' => '104.21.74.240'],
                    ['data' => '172.67.164.165'],
                ],
            ]);
        });

        $dns = new PublicDnsLookup;

        $this->assertSame(
            ['augustus.ns.cloudflare.com', 'lina.ns.cloudflare.com'],
            $dns->nameservers('xoveniq.org'),
        );
        $this->assertSame(
            ['172.67.164.165', '104.21.74.240'],
            $dns->aRecords('xoveniq.org'),
        );
    }

    public function test_stale_cloudflare_doh_parking_a_loses_to_google(): void
    {
        Http::fake(function ($request) {
            $type = (string) ($request->data()['type'] ?? '');
            if (str_contains($request->url(), 'dns-query')) {
                if ($type === 'NS') {
                    return Http::response(['Status' => 0, 'Answer' => []]);
                }

                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '137.131.40.164']],
                ]);
            }

            if ($type === 'NS') {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [
                        ['data' => 'aron.ns.cloudflare.com.'],
                        ['data' => 'jasper.ns.cloudflare.com.'],
                    ],
                ]);
            }

            return Http::response([
                'Status' => 0,
                'Answer' => [
                    ['data' => '104.21.74.45'],
                    ['data' => '172.67.197.246'],
                ],
            ]);
        });

        $dns = new PublicDnsLookup;

        $this->assertSame(
            ['aron.ns.cloudflare.com', 'jasper.ns.cloudflare.com'],
            $dns->nameservers('epargniture-be.net'),
        );
        $this->assertSame(
            ['104.21.74.45', '172.67.197.246'],
            $dns->aRecords('epargniture-be.net'),
        );
    }

    public function test_cloudflare_nxdomain_does_not_hide_google_records(): void
    {
        Http::fake(function ($request) {
            $type = (string) ($request->data()['type'] ?? '');
            if (str_contains($request->url(), 'dns-query')) {
                return Http::response(['Status' => 3, 'Answer' => []]);
            }
            if ($type === 'NS') {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [
                        ['data' => 'julian.ns.cloudflare.com.'],
                        ['data' => 'shaz.ns.cloudflare.com.'],
                    ],
                ]);
            }

            return Http::response([
                'Status' => 0,
                'Answer' => [['data' => '104.21.68.82']],
            ]);
        });

        $dns = new PublicDnsLookup;

        $this->assertSame(
            ['julian.ns.cloudflare.com', 'shaz.ns.cloudflare.com'],
            $dns->nameservers('maturidence-uk.com'),
        );
        $this->assertSame(['104.21.68.82'], $dns->aRecords('maturidence-uk.com'));
    }

    public function test_mixed_a_records_keep_only_cloudflare_proxy(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response([
                'Status' => 0,
                'Answer' => [['data' => '45.61.177.8']],
            ]),
            'https://dns.google/resolve*' => Http::response([
                'Status' => 0,
                'Answer' => [['data' => '188.114.96.0']],
            ]),
        ]);

        $this->assertSame(
            ['188.114.96.0'],
            (new PublicDnsLookup)->aRecords('split-brain.example'),
        );
    }

    public function test_grey_cloud_origin_a_is_kept_when_no_proxy_ip(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response([
                'Status' => 0,
                'Answer' => [['data' => '45.61.177.8']],
            ]),
            'https://dns.google/resolve*' => Http::response([
                'Status' => 0,
                'Answer' => [['data' => '45.61.177.8']],
            ]),
        ]);

        $this->assertSame(
            ['45.61.177.8'],
            (new PublicDnsLookup)->aRecords('grey-cloud.example'),
        );
    }
}
