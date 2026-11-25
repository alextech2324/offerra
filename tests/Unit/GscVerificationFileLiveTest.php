<?php

namespace Tests\Unit;

use App\Services\GoogleSearchConsoleClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GscVerificationFileLiveTest extends TestCase
{
    public function test_verification_file_is_ok_when_public_dns_and_token_match(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '104.21.67.64']],
                ]);
            }

            if (str_contains($url, 'googled436d39bff274925.html')) {
                return Http::response('google-site-verification: googled436d39bff274925.html', 200);
            }

            return Http::response('nope', 404);
        });

        app(GoogleSearchConsoleClient::class)
            ->assertVerificationFileLive('clearexirum.biz', 'googled436d39bff274925.html');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'googled436d39bff274925.html'));
    }

    public function test_verification_file_timeout_is_wrapped(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '104.21.67.64']],
                ]);
            }

            throw new RuntimeException('cURL error 28: Timeout was reached');
        });

        try {
            app(GoogleSearchConsoleClient::class)
                ->assertVerificationFileLive('clearexirum.biz', 'googled436d39bff274925.html');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot fetch verification file', $e->getMessage());
            $this->assertStringContainsString('Timeout was reached', $e->getMessage());
        }
    }

    public function test_verification_file_tries_next_pin_ip_after_connect_timeout(): void
    {
        $gets = 0;
        Http::fake(function ($request) use (&$gets) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '172.86.81.159']],
                ]);
            }

            $gets++;
            if ($gets === 1) {
                throw new RuntimeException('cURL error 28: Failed to connect to host port 443 after 5001 ms: Timeout was reached');
            }

            return Http::response('google-site-verification: googled436d39bff274925.html', 200);
        });

        app(GoogleSearchConsoleClient::class)
            ->assertVerificationFileLive(
                'clearexirum.biz',
                'googled436d39bff274925.html',
                ['172.86.71.141'],
            );

        $this->assertSame(2, $gets);
    }

    public function test_verification_file_cloudflare_challenge_does_not_block_submit_precheck(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '104.21.50.179']],
                ]);
            }

            return Http::response('<html><title>Just a moment...</title></html>', 403, [
                'Cf-Mitigated' => 'challenge',
                'Server' => 'cloudflare',
                'Cf-Ray' => 'abc123-AMS',
            ]);
        });

        app(GoogleSearchConsoleClient::class)
            ->assertVerificationFileLive('lucrianza.live', 'google94a870f17d061816.html');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'google94a870f17d061816.html'));
    }

    public function test_https_live_accepts_cloudflare_challenge_as_up(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
                return Http::response([
                    'Status' => 0,
                    'Answer' => [['data' => '104.21.50.179']],
                ]);
            }

            return Http::response('<html><title>Just a moment...</title></html>', 403, [
                'Cf-Mitigated' => 'challenge',
                'Server' => 'cloudflare',
                'Cf-Ray' => 'abc123-AMS',
            ]);
        });

        app(GoogleSearchConsoleClient::class)->assertHttpsLive('lucrianza.live');

        Http::assertSent(fn ($request) => $request->url() === 'https://lucrianza.live/');
    }
}
