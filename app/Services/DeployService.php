<?php

namespace App\Services;

use App\Jobs\DeployOfferJob;
use App\Jobs\PushGscVerificationFileJob;
use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class DeployService
{
    private const DEPLOY_TIMEOUT = 600;

    /** Per-host deploy slot TTL — must exceed slow uploads. */
    private const HOST_DEPLOY_LOCK_SECONDS = 720;

    private const HOST_DEPLOY_LOCK_WAIT = 3600;

    /** @var array<string, true> */
    /** @var list<string> */
    private const SKIP_FILES = [
        'dev-server.ps1',
        'router.php',
        '.gitignore',
    ];

    public function __construct(
        private readonly OfferGenerator $generator,
        private readonly OriginHostService $origin,
        private readonly OriginPool $pool,
        private readonly OfferVerificationFileService $verificationFiles,
        private readonly string $offersPath,
    ) {}

    public function resetStuckDeploys(int $minutes = 8): int
    {
        $stuck = Offer::query()
            ->where('status', 'deploying')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stuck as $offer) {
            $offer->update([
                'status' => $offer->deployed_at ? 'deployed' : 'failed',
                'deploy_error' => 'Деплой перервано (таймаут або збій з\'єднання). Спробуйте ще раз.',
            ]);
        }

        return $stuck->count();
    }

    public function enqueueDeploy(User $user, Offer $offer): void
    {
        $this->assertCanDeploy($user, $offer);

        if ($offer->status === 'deploying') {
            throw new RuntimeException(
                'Деплой цього оффера вже виконується. Зачекайте кілька хвилин і оновіть сторінку.',
            );
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging'], true)) {
            throw new RuntimeException('Архівний оффер не можна деплоїти.');
        }

        $offer->update(['deploy_error' => null]);

        DeployOfferJob::dispatch($offer->id);
    }

    public function enqueueDeployAfterInfra(Offer $offer): bool
    {
        $offer->loadMissing('user.settings');

        if (! $offer->user || ! $offer->provision_infrastructure) {
            return false;
        }

        if ($offer->infra_status !== 'ready') {
            return false;
        }

        if (in_array($offer->status, ['deploying', 'archived', 'archiving', 'purging'], true)) {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        if (($meta['origin'] ?? '') !== 'done') {
            return false;
        }

        if (! $this->shouldAutoDeployAfterInfra($offer, $meta)) {
            Log::info('Auto-deploy after infra skipped — already deployed on target origin', [
                'offer' => $offer->id,
                'domain' => $offer->domain,
            ]);

            return false;
        }

        try {
            $this->enqueueDeploy($offer->user, $offer->fresh());

            return true;
        } catch (InvalidArgumentException|RuntimeException $e) {
            Log::info('Auto-deploy after infra skipped', [
                'offer' => $offer->id,
                'domain' => $offer->domain,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function needsDeploy(Offer $offer): bool
    {
        $offer->loadMissing('user.settings');

        if (! $offer->user || $offer->infra_status !== 'ready') {
            return false;
        }

        if (in_array($offer->status, ['deploying', 'archived', 'archiving', 'purging'], true)) {
            return false;
        }

        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];

        if (($meta['origin'] ?? '') !== 'done') {
            return false;
        }

        return $this->shouldAutoDeployAfterInfra($offer, $meta);
    }

    public function assertCanDeploy(User $user, Offer $offer): void
    {
        // Resolves SSH for the offer's bound origin, or allocates one from the pool.
        $this->resolveDeploySettings($user, $offer);
    }

    /**
     * SSH comes from the admin-managed origin pool: the offer's bound server when
     * it already has one, otherwise a pool host with the fewest offers of this
     * brand (then the least total load).
     */
    public function resolveDeploySettings(User $user, Offer $offer, bool $allocate = true): UserSetting
    {
        $offer->loadMissing('user.settings');
        $settings = $user->settings ?? $offer->user?->settings;

        if (! $settings) {
            throw new InvalidArgumentException('Заповніть налаштування користувача перед деплоєм.');
        }

        if (! $allocate && $this->pool->boundHost($offer) === '') {
            throw new InvalidArgumentException("Оффер #{$offer->id} не привʼязаний до origin-сервера.");
        }

        try {
            return $this->pool->settingsForOffer($settings, $offer, $allocate);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    public function offerDeployHost(Offer $offer): string
    {
        $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
        $fromMeta = trim((string) ($meta['deploy_host'] ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $panel = trim((string) ($offer->deploy_panel_name ?? ''));
        if ($panel !== '' && filter_var($panel, FILTER_VALIDATE_IP)) {
            return $panel;
        }

        return '';
    }

    public function deploy(User $user, Offer $offer): Offer
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $this->resetStuckDeploys();

        $offer->loadMissing('user.settings');
        $settings = $this->resolveDeploySettings($user, $offer);

        $hostLock = $this->acquireDeployHostSlot($settings);

        $lock = Cache::lock('offer-deploy-'.$offer->id, self::DEPLOY_TIMEOUT + 60);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $hostLock->release();

            throw new RuntimeException(
                'Деплой цього оффера вже виконується. Зачекайте кілька хвилин і спробуйте знову.',
            );
        }

        try {
            return $this->runDeploy($user, $offer, $settings);
        } finally {
            $lock->release();
            $hostLock->release();
        }
    }

    public function deployDirect(User $user, Offer $offer): Offer
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $settings = $this->resolveDeploySettings($user, $offer);

        $lock = Cache::lock('offer-deploy-'.$offer->id, self::DEPLOY_TIMEOUT + 60);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new RuntimeException(
                'Деплой цього оффера вже виконується. Зачекайте кілька хвилин і спробуйте знову.',
            );
        }

        try {
            return $this->runDeploy($user, $offer, $settings);
        } finally {
            $lock->release();
        }
    }

    private function runDeploy(User $user, Offer $offer, ?UserSetting $settings = null): Offer
    {
        $offer->loadMissing('user.settings');
        $settings ??= $this->resolveDeploySettings($user, $offer);

        $offer->update([
            'status' => 'deploying',
            'deploy_error' => null,
        ]);

        try {
            $this->generator->refreshConfig($offer);

            $localPath = $this->generator->ensureLocalFolder($offer);

            if (! File::isFile($localPath.'/index.php')) {
                throw new RuntimeException("Локальний index.php не знайдено: {$offer->folder}");
            }

            $this->generator->syncSharedIncludeFiles($localPath, $offer->template);
            $this->generator->syncSharedIntegrationFiles($localPath, $offer->template);
            $this->generator->syncSharedStaticFiles($localPath, $offer->template, $offer->lang);
            $this->generator->migrateLegacyAssets($localPath, $offer->template);

            $remotePath = $this->origin->deployArchive(
                $settings,
                $offer->domain,
                $localPath,
                self::SKIP_FILES,
            );

            $deployHost = trim((string) $settings->deploy_host);

            Log::info('Deploy upload finished', [
                'offer' => $offer->id,
                'host' => $deployHost,
            ]);

            $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
            unset($meta['needs_redeploy']);
            $meta['deploy_host'] = $deployHost;

            $offer->update([
                'status' => 'deployed',
                'deploy_panel_name' => $deployHost !== '' ? $deployHost : ($settings->deploy_panel_name ?? null),
                'remote_path' => $remotePath,
                'deployed_at' => now(),
                'deploy_error' => null,
                'infra_meta' => $meta,
            ]);
            if (config('offerra.purge_local_after_deploy', true)) {
                $this->purgeLocalFolder($localPath, $offer);
            }

            // Trigger DNS recheck now that files are on the server.
            $fresh = $offer->fresh();
            if ($fresh->provision_infrastructure && ($fresh->infra_meta['dns'] ?? null) === 'pending') {
                \App\Jobs\RecheckInfrastructureDnsJob::dispatch($fresh->id)->delay(now()->addSeconds(10));
            }

            if ($fresh && ($fresh->infra_meta['dns'] ?? null) === 'done') {
                app(OfferGscSubmitter::class)->queueAfterDns($fresh);
            }

            return $fresh;
        } catch (\Throwable $e) {
            $offer->update([
                'status' => $offer->deployed_at ? 'deployed' : 'failed',
                'deploy_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Deploy is possible when the admin pool has at least one server that can
     * take offers — per-user SSH settings no longer exist.
     */
    public function poolReady(): bool
    {
        return $this->pool->hasCapacity();
    }

    /**
     * Refresh local lander PHP and upload config + helpers + integration
     * (not HTML/static). Used after Keitaro removal so live send.php
     * stops verifying clicks against the tracker.
     */
    public function pushConfig(User $user, Offer $offer): void
    {
        $offer->loadMissing('user.settings');
        $settings = $this->resolveDeploySettings($user, $offer);

        $this->generator->refreshConfig($offer);
        $localPath = $this->generator->ensureLocalFolder($offer);
        $uploaded = [];

        foreach ($this->leadRuntimeRelativeFiles() as $relative) {
            $local = $localPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! File::isFile($local)) {
                continue;
            }

            $this->origin->uploadOfferRelativeFile(
                $settings,
                $offer->domain,
                $local,
                $relative,
            );
            $uploaded[] = $relative;
        }

        if ($uploaded === []) {
            throw new RuntimeException("Lead runtime files missing for offer #{$offer->id}");
        }

        Log::info('Pushed lead runtime PHP to origin', [
            'offer' => $offer->id,
            'domain' => $offer->domain,
            'host' => $settings->deploy_host,
            'files' => $uploaded,
        ]);
    }

    /**
     * @return list<string>
     */
    public function leadRuntimeRelativeFiles(): array
    {
        return [
            'includes/config.php',
            'includes/helpers.php',
            'includes/keitaro.php',
            'integration/LeadProcessor.php',
            'integration/send.php',
            'integration/FormToken.php',
            'integration/LeadFieldGuard.php',
            'integration/form-token.php',
            'integration/visitor-geo.php',
        ];
    }

    /**
     * Copy the owner's current GSC HTML token onto the live origin.
     * Needed when Settings gets a new file after the lander was already deployed.
     */
    public function pushVerificationFile(User $user, Offer $offer): void
    {
        $offer->loadMissing('user.settings');
        $settings = $this->resolveDeploySettings($user, $offer, allocate: false);

        $this->verificationFiles->applyFromUserSettings($offer);
        $offer->refresh();

        $filename = trim((string) $offer->verification_filename);
        $local = $this->verificationFiles->storagePath($offer);

        if ($filename === '' || $local === null || ! File::isFile($local)) {
            throw new RuntimeException('GSC verification HTML file is missing.');
        }

        $this->origin->uploadOfferRelativeFile(
            $settings,
            $offer->domain,
            $local,
            $filename,
        );

        Log::info('Pushed GSC verification file to origin', [
            'offer' => $offer->id,
            'domain' => $offer->domain,
            'file' => $filename,
            'host' => $settings->deploy_host,
        ]);
    }

    /**
     * Queue a verification-file push for every live offer that still needs GSC.
     */
    public function queueVerificationPushForUser(User $user): int
    {
        $ids = Offer::query()
            ->where('user_id', $user->id)
            ->where('status', 'deployed')
            ->where('submitted_for_indexing', false)
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            PushGscVerificationFileJob::dispatch((int) $id)->onQueue('deploy');
        }

        return count($ids);
    }

    private function purgeLocalFolder(string $localPath, Offer $offer): void
    {
        if (! File::isDirectory($localPath)) {
            return;
        }

        File::deleteDirectory($localPath);

        Log::info('Deploy purged local offer folder', [
            'offer' => $offer->id,
            'folder' => $offer->folder,
            'path' => $localPath,
        ]);
    }

    private function deployHostLockKey(UserSetting $settings): string
    {
        $host = strtolower(trim((string) $settings->deploy_host));

        return 'origin-deploy-host-'.md5($host !== '' ? $host : 'unknown');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function shouldAutoDeployAfterInfra(Offer $offer, array $meta): bool
    {
        if (($meta['needs_redeploy'] ?? false) === true) {
            return true;
        }

        if ($offer->status !== 'deployed') {
            return true;
        }

        if (! filled($offer->remote_path)) {
            return true;
        }

        // Do not move an already-deployed offer just because Settings.deploy_host changed.
        // Redeploy always targets the offer's Server column.
        return false;
    }

    private function acquireDeployHostSlot(UserSetting $settings): \Illuminate\Contracts\Cache\Lock
    {
        $slots = max(40, (int) config('offerra.deploy_concurrency_per_host', 40));
        $base = $this->deployHostLockKey($settings);
        $deadline = time() + self::HOST_DEPLOY_LOCK_WAIT;

        while (time() < $deadline) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $lock = Cache::lock($base.'-slot-'.$slot, self::HOST_DEPLOY_LOCK_SECONDS);

                if ($lock->get()) {
                    return $lock;
                }
            }

            sleep(2);
        }

        throw new RuntimeException(
            'Черга деплою на цей сервер зайнята. Спробуйте пізніше.',
        );
    }
}
