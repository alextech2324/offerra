<?php

namespace App\Services;

use App\Jobs\ProbeOfferAvailabilityJob;
use App\Jobs\SubmitOfferToGscJob;
use App\Models\Offer;
use App\Models\UserSetting;
use InvalidArgumentException;
use RuntimeException;

class OfferGscSubmitter
{
    public function __construct(
        private readonly GoogleSearchConsoleClient $gsc,
        private readonly OfferGoogleIndexInspector $indexInspector,
        private readonly DeployService $deploy,
    ) {}

    public function canSubmit(Offer $offer): bool
    {
        if (! $this->baseReady($offer)) {
            return false;
        }

        return ($offer->availability_status ?? '') === 'ok';
    }

    /**
     * DNS done + deployed + Google connected — enough to start waiting for live HTTPS.
     */
    public function shouldProbeAfterDns(Offer $offer): bool
    {
        if (! $offer->provision_infrastructure) {
            return false;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging', 'teardown_failed'], true)) {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['dns'] ?? null) !== 'done') {
            return false;
        }

        if ($offer->submitted_for_indexing || ($meta['gsc']['status'] ?? null) === 'submitted') {
            return false;
        }

        $settings = $this->ownerSettings($offer);
        if (! $settings) {
            return false;
        }

        return $settings->hasGoogleOAuth()
            && filled($settings->gsc_verification_filename)
            && filled(config('services.google.client_id'));
    }

    public function shouldQueue(Offer $offer): bool
    {
        if ($offer->submitted_for_indexing) {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['gsc']['status'] ?? null) === 'submitted') {
            return false;
        }

        return $this->canSubmit($offer);
    }

    public function queueAfterDns(Offer $offer, int $delaySeconds = 15): void
    {
        if (! $this->shouldProbeAfterDns($offer)) {
            return;
        }

        $pending = ProbeOfferAvailabilityJob::dispatch($offer->id);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function queue(Offer $offer, int $delaySeconds = 0, bool $force = false): void
    {
        if ($force) {
            if (! $this->canSubmit($offer)) {
                return;
            }
        } elseif (! $this->shouldQueue($offer)) {
            return;
        }

        $pending = SubmitOfferToGscJob::dispatch($offer->id, $force);
        // Spread a batch of greens so they do not all hit GSC sites.add in the same minute.
        $spread = max(0, $delaySeconds) + (($offer->id % 10) * 8);
        if ($spread > 0) {
            $pending->delay(now()->addSeconds($spread));
        }
    }

    /**
     * Sync: verify site in Search Console, submit sitemap, tick indexing checkbox.
     *
     * @return array{site_url: string, sitemap_url: string}
     */
    public function submit(Offer $offer): array
    {
        if (! $this->canSubmit($offer)) {
            throw new InvalidArgumentException(
                'GSC недоступний: потрібні DNS=done, живий сайт (зелений кружечок), деплой, Google Connect і verification HTML.',
            );
        }

        $settings = $this->ownerSettings($offer);
        if (! $settings) {
            throw new InvalidArgumentException('Немає Settings у власника офера.');
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $meta['gsc'] = [
            'status' => 'waiting',
            'updated_at' => now()->toIso8601String(),
        ];
        unset($meta['gsc_error']);
        $offer->update(['infra_meta' => $meta]);

        try {
            if ($offer->user) {
                $this->deploy->pushVerificationFile($offer->user, $offer);
            }

            $result = $this->gsc->submitOffer($offer, $settings);

            if (! ($result['added'] ?? false) || ! ($result['sitemap_submitted'] ?? false)) {
                throw new RuntimeException('Search Console або sitemap не підтверджені.');
            }

            $fresh = $offer->fresh() ?? $offer;
            $meta = is_array($fresh->infra_meta) ? $fresh->infra_meta : [];
            $meta['gsc'] = [
                'status' => 'submitted',
                'site_url' => $result['site_url'],
                'sitemap_url' => $result['sitemap_url'],
                'submitted_at' => now()->toIso8601String(),
                'email' => $result['email'] ?? null,
                'google_account_id' => $result['google_account_id'] ?? null,
            ];
            unset($meta['gsc_error']);

            $fresh->update([
                'submitted_for_indexing' => true,
                'indexed_at' => $fresh->indexed_at ?? now(),
                'google_index_status' => $fresh->google_indexed_at
                    ? $fresh->google_index_status
                    : OfferGoogleIndexInspector::STATUS_WAITING,
                'infra_meta' => $meta,
            ]);

            if (! $fresh->google_indexed_at) {
                $this->indexInspector->queueFirstCheck($fresh);
            }

            return [
                'site_url' => $result['site_url'],
                'sitemap_url' => $result['sitemap_url'],
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $retryable = self::isRetryableError($message);

            $fresh = $offer->fresh() ?? $offer;
            $meta = is_array($fresh->infra_meta) ? $fresh->infra_meta : [];
            $meta['gsc_error'] = $retryable
                ? self::retryableErrorLabel($message)
                : self::publicErrorLabel($message);
            $meta['gsc'] = [
                'status' => $retryable ? 'waiting' : 'failed',
                'updated_at' => now()->toIso8601String(),
            ];
            $fresh->update(['infra_meta' => $meta]);

            throw $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                ? $e
                : new RuntimeException($message, 0, $e);
        }
    }

    public static function isRetryableError(string $message): bool
    {
        $m = strtolower($message);

        if (GoogleSearchConsoleClient::isAccountFullError($message)) {
            return false;
        }

        if (str_contains($m, 'http 429')
            || str_contains($m, 'quota exceeded')
            || str_contains($m, 'rate-limited')
            || str_contains($m, 'rate limit')
            || str_contains($m, 'low rate user requests')
            || str_contains($m, 'internal_failure')) {
            return true;
        }

        return str_contains($message, 'HTTPS is not live')
            || str_contains($message, 'Cannot fetch verification file')
            || str_contains($message, 'Verification file HTTP')
            || str_contains($message, 'does not look like a GSC HTML token')
            || str_contains($message, 'GSC verification HTML file is missing')
            || str_contains($message, 'SFTP:')
            || str_contains($message, 'NXDOMAIN')
            || str_contains($message, 'не резолвиться')
            || str_contains($message, 'Timeout')
            || str_contains($message, 'DNS resolve')
            || (str_contains($message, 'sitemaps.submit failed') && str_contains($message, '403'));
    }

    public static function publicErrorLabel(string $message): string
    {
        if (GoogleOAuthService::isDeadTokenError($message)) {
            if (preg_match('/для\s+(\S+@\S+)/u', $message, $match) === 1) {
                return GoogleOAuthService::deadTokenMessage(rtrim($match[1], '.,;'));
            }

            return GoogleOAuthService::deadTokenMessage();
        }

        if (GoogleSearchConsoleClient::isAccountFullError($message)) {
            return GoogleSearchConsoleClient::ACCOUNT_FULL_MESSAGE;
        }

        return substr($message, 0, 400);
    }

    public static function retryableErrorLabel(string $message): string
    {
        $m = strtolower($message);
        if (str_contains($m, '429')
            || str_contains($m, 'quota exceeded')
            || str_contains($m, 'rate-limited')
            || str_contains($m, 'rate limit')
            || str_contains($m, 'low rate user requests')) {
            return 'GSC ліміт запитів — повторюємо автоматично';
        }

        return 'GSC ще не готовий — повторюємо автоматично';
    }

    private function baseReady(Offer $offer): bool
    {
        if (! $offer->provision_infrastructure) {
            return false;
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging', 'teardown_failed'], true)) {
            return false;
        }

        if ($offer->status !== 'deployed') {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        if (($meta['dns'] ?? null) !== 'done') {
            return false;
        }

        $settings = $this->ownerSettings($offer);
        if (! $settings) {
            return false;
        }

        return $settings->hasGoogleOAuth()
            && filled($settings->gsc_verification_filename)
            && filled(config('services.google.client_id'));
    }

    private function ownerSettings(Offer $offer): ?UserSetting
    {
        $offer->loadMissing('user.settings');
        $settings = $offer->user?->settings;

        return $settings instanceof UserSetting ? $settings : null;
    }
}
