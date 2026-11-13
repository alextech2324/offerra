<?php

namespace Tests\Unit;

use App\Models\GoogleAccount;
use App\Services\GoogleOAuthService;
use App\Services\GoogleSearchConsoleClient;
use App\Services\OfferGscSubmitter;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OfferGscRetryableErrorTest extends TestCase
{
    public function test_quota_429_is_retryable(): void
    {
        $message = 'Search Console sites.add failed (HTTP 429): Quota exceeded for quota metric \'Low rate user requests limit\'';

        $this->assertTrue(OfferGscSubmitter::isRetryableError($message));
        $this->assertSame(
            'GSC ліміт запитів — повторюємо автоматично',
            OfferGscSubmitter::retryableErrorLabel($message),
        );
    }

    public function test_https_not_live_is_retryable(): void
    {
        $this->assertTrue(OfferGscSubmitter::isRetryableError('HTTPS is not live yet for example.com'));
    }

    public function test_verification_file_mismatch_is_retryable(): void
    {
        $this->assertTrue(OfferGscSubmitter::isRetryableError(
            'Verification file does not look like a GSC HTML token: https://example.com/googleabc.html',
        ));
    }

    public function test_permission_error_is_not_retryable(): void
    {
        $this->assertFalse(OfferGscSubmitter::isRetryableError(
            'Search Console sites.add failed (HTTP 403): You do not own this site',
        ));
    }

    public function test_site_capacity_quota_is_not_retryable_and_has_clear_label(): void
    {
        $message = 'Search Console sites.add failed (HTTP 403): {"error": {"code": 403, "message": "Cannot add any more sites to this Search Console account.", "errors": [{ "message": "Cannot add any more sites to this Search Console account.", "domain": "usageLimits", "reason": "quotaExceeded" }] }}';

        $this->assertTrue(\App\Services\GoogleSearchConsoleClient::isAccountFullError($message));
        $this->assertFalse(OfferGscSubmitter::isRetryableError($message));
        $this->assertSame(
            \App\Services\GoogleSearchConsoleClient::ACCOUNT_FULL_MESSAGE,
            OfferGscSubmitter::publicErrorLabel($message),
        );
    }

    public function test_google_oauth_internal_failure_is_retryable(): void
    {
        $message = 'Google OAuth refresh failed: {
  "error": "internal_failure",
  "error_description": "Internal Error"
}';

        $this->assertTrue(OfferGscSubmitter::isRetryableError($message));
    }

    public function test_dead_token_message_names_the_account(): void
    {
        $this->assertTrue(GoogleOAuthService::isDeadTokenError('invalid_grant'));
        $this->assertSame(
            'Google токен протух для fuegodorn@gmail.com. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».',
            GoogleOAuthService::deadTokenMessage('fuegodorn@gmail.com'),
        );
    }

    public function test_expired_token_is_not_retryable_and_names_the_account(): void
    {
        $message = 'Google OAuth refresh failed: {
  "error": "invalid_grant",
  "error_description": "Token has been expired or revoked."
}';

        $this->assertFalse(OfferGscSubmitter::isRetryableError($message));
        $this->assertSame(
            'Google токен протух. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».',
            OfferGscSubmitter::publicErrorLabel($message),
        );
        $this->assertSame(
            'Google токен протух для fuegodorn@gmail.com. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».',
            OfferGscSubmitter::publicErrorLabel(
                'Google токен протух для fuegodorn@gmail.com. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».',
            ),
        );
    }

    public function test_find_site_skips_a_dead_token_instead_of_aborting_submit(): void
    {
        Cache::flush();

        $oauth = Mockery::mock(GoogleOAuthService::class);
        $oauth->shouldReceive('accessTokenForAccount')
            ->once()
            ->andThrow(new RuntimeException(GoogleOAuthService::deadTokenMessage('jack.ego877@gmail.com')));
        $this->app->instance(GoogleOAuthService::class, $oauth);

        $account = new GoogleAccount([
            'email' => 'jack.ego877@gmail.com',
            'refresh_token' => 'revoked',
        ]);
        $account->id = 99;

        $found = $this->app->make(GoogleSearchConsoleClient::class)
            ->findSiteOnAccount($account, 'soldivia.live');

        $this->assertNull($found);
    }

    public function test_dead_primary_does_not_fall_through_to_inspect_account(): void
    {
        $primary = new GoogleAccount(['email' => 'lincoln@example.com', 'is_primary' => true]);
        $primary->id = 40;
        $inspect = new GoogleAccount(['email' => 'brobuyer88@gmail.com', 'is_primary' => false]);
        $inspect->id = 27;

        $this->assertTrue(GoogleSearchConsoleClient::deadPrimaryBlocksSubmitFallback($primary, null, $primary));
        $this->assertTrue(GoogleSearchConsoleClient::deadPrimaryBlocksSubmitFallback($primary, $primary, $primary));
        $this->assertFalse(GoogleSearchConsoleClient::deadPrimaryBlocksSubmitFallback($inspect, null, $primary));
        $this->assertFalse(GoogleSearchConsoleClient::deadPrimaryBlocksSubmitFallback($inspect, $inspect, $primary));
        $this->assertFalse(GoogleSearchConsoleClient::deadPrimaryBlocksSubmitFallback($primary, $inspect, $primary));
    }
}
