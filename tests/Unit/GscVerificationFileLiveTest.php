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
}
