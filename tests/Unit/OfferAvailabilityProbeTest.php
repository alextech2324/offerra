<?php

namespace Tests\Unit;

use App\Services\OfferAvailabilityProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferAvailabilityProbeTest extends TestCase
{
    public function test_public_a_record_and_https_200_is_ok(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '104.21.77.116']],
                ]);
            }

            return Http::response('<html>lander</html>', 200);
        });

        $result = app(OfferAvailabilityProbe::class)->probeDomain('ethereoxia-es.pro');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function test_public_nxdomain_does_not_http_probe(): void
    {
        Http::fake([
            'https://cloudflare-dns.com/dns-query*' => Http::response([
                'Status' => 3,
                'Answer' => [],
            ]),
            'https://dns.google/resolve*' => Http::response([
                'Status' => 3,
                'Answer' => [],
            ]),
            '*' => Http::response('should-not-hit', 200),
        ]);

        $result = app(OfferAvailabilityProbe::class)->probeDomain('missing-lander.example');

        $this->assertFalse($result['ok']);
        $this->assertSame('NXDOMAIN / no public DNS', $result['error']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://missing-lander.example'));
    }

    public function test_ns_without_a_record_is_not_ok(): void
    {
        Http::fake(function ($request) {
            $type = (string) ($request->data()['type'] ?? '');
            if (str_contains($request->url(), 'dns-query') || str_contains($request->url(), 'dns.google')) {
                if ($type === 'A') {
                    return Http::response(['Status' => 0, 'Answer' => []]);
                }

                return Http::response([
                    'Status' => 0,
                    'Answer' => [
                        ['data' => 'indie.ns.cloudflare.com.'],
                        ['data' => 'piotr.ns.cloudflare.com.'],
                    ],
                ]);
            }

            return Http::response('should-not-hit', 200);
        });

        $result = app(OfferAvailabilityProbe::class)->probeDomain('ns-only.example');

        $this->assertFalse($result['ok']);
        $this->assertSame('No A record (NS: indie.ns.cloudflare.com, piotr.ns.cloudflare.com)', $result['error']);
    }

    public function test_stale_cloudflare_doh_parking_ip_does_not_keep_lander_red(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $type = (string) ($request->data()['type'] ?? '');
            if (str_contains($url, 'dns-query')) {
                if ($type === 'NS') {
                    return Http::response(['Status' => 0, 'Answer' => []]);
                }

                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '137.131.40.164']],
                ]);
            }
            if (str_contains($url, 'dns.google')) {
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
            }

            return Http::response('<html>lander</html>', 200);
        });

        $result = app(OfferAvailabilityProbe::class)->probeDomain('epargniture-be.net');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }
}
