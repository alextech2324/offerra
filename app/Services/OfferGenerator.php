<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\InfrastructureOptions;
use App\Support\MarketOptions;
use App\Support\ValidationJsFormToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class OfferGenerator
{
    public function __construct(
        private readonly OfferConfigBuilder $configBuilder,
        private readonly TemplateCatalog $templateCatalog,
        private readonly OfferVerificationFileService $verificationFiles,
        private readonly string $offersPath,
        private readonly string $templatesPath,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{folder: string, path: string, offer: Offer}
     */
    public function generate(User $user, array $input): array
    {
        $settings = $user->settings;

        if (! $settings || ! $settings->crm_api_key || ! $settings->tg_bot_token) {
            throw new InvalidArgumentException(
                'Спочатку збережіть CRM API key і Telegram bot token у налаштуваннях.',
            );
        }

        $input = TemplateCatalog::pinVesperaMarket($input);
        $template = $input['template'];

        if (! in_array($template, $this->templateCatalog->enabledIds(), true)) {
            throw new InvalidArgumentException("Шаблон «{$template}» вимкнено.");
        }

        $templateLangForSource = TemplateCatalog::copiesWholeTree($template) ? 'en' : (string) $input['lang'];
        $templatePath = $this->templateCatalog->resolveSourcePath($template, $templateLangForSource);

        if (! File::isDirectory($templatePath)) {
            throw new InvalidArgumentException("Шаблон «{$template}» ({$input['lang']}) не знайдено на диску.");
        }

        $affiliateTag = $this->normalizeAffiliateTag($settings->affiliate_tag);
        $input['affiliate_tag'] = $affiliateTag;

        $this->ensureWorkerWritable($this->offersPath, recursive: false);

        $resolved = $this->resolveTargetFolder($user, $input);
        $folder = $resolved['folder'];
        $targetPath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$folder;

        if ($resolved['offer'] !== null) {
            return $this->reuseExistingOffer($resolved['offer'], $folder, $targetPath);
        }

        if (File::isDirectory($targetPath)) {
            return $this->resolveExisting($user, $folder, $targetPath);
        }

        $offer = null;

        try {
            File::copyDirectory($templatePath, $targetPath);
            $this->syncSharedIncludeFiles($targetPath, $template);
            $this->syncSharedIntegrationFiles($targetPath, $template);
            $this->syncSharedStaticFiles($targetPath, $template, strtolower($input['lang']));

            if (TemplateCatalog::isOverflowHub($template)) {
                $this->pruneMultilangDuplicates($targetPath);
            }

            if ($template === 'vespera') {
                $this->syncVesperaLangFrontControllers($targetPath);
                $this->syncVesperaBrandAssets($targetPath, (string) $input['brand']);
            }

            if (! empty($input['vitals_enabled'])) {
                app(MirrorProbeService::class)->ensureProbeToken($settings);
            }

            $config = $this->configBuilder->build($input, $settings);
            File::put($targetPath.'/includes/config.php', $config);

            $manifest = [
                'brand' => $input['brand'],
                'domain' => $input['domain'],
                'geo' => MarketOptions::normalizeGeo((string) $input['geo']),
                'lang' => strtolower($input['lang']),
                'phone' => strtolower($input['phone']),
                'phone_countries' => $this->configBuilder->phoneCountriesCsv($input),
                'min_deposit' => (string) $input['min_deposit'],
                'currency' => strtoupper((string) $input['currency']),
                'template' => $template,
                'status' => 'generated',
                'vitals_enabled' => ! empty($input['vitals_enabled']),
                'from_search_team' => ! empty($input['from_search_team']),
                'owner_id' => $user->id,
                'created_at' => now()->toDateString(),
                'folder' => $folder,
            ];

            File::put(
                $targetPath.'/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            );

            $infraOptions = InfrastructureOptions::fromInput($input);
            $provisionInfrastructure = InfrastructureOptions::anyEnabled($infraOptions);
            $providerSnapshot = $settings->providerSnapshotForOffer();

            $infraMeta = $provisionInfrastructure
                ? ['options' => $infraOptions]
                : null;

            $offer = Offer::create([
                'user_id' => $user->id,
                'folder' => $folder,
                'brand' => $input['brand'],
                'domain' => $input['domain'],
                'geo' => MarketOptions::normalizeGeo((string) $input['geo']),
                'lang' => strtolower($input['lang']),
                'phone' => strtolower($input['phone']),
                'phone_countries' => $this->configBuilder->phoneCountriesCsv($input),
                'min_deposit' => (string) $input['min_deposit'],
                'currency' => strtoupper((string) $input['currency']),
                'template' => $template,
                'status' => 'generated',
                // Bound later by OriginPool on first provision/deploy.
                'deploy_panel_name' => null,
                'vitals_enabled' => ! empty($input['vitals_enabled']),
                'from_search_team' => ! empty($input['from_search_team']),
                'provision_infrastructure' => $provisionInfrastructure,
                'infra_status' => $provisionInfrastructure ? 'pending' : null,
                'infra_meta' => $infraMeta,
                ...$providerSnapshot,
            ]);

            $this->prepareVerificationCopy($offer, $targetPath);
            $this->verificationFiles->syncToOfferFolder($offer);
            $this->ensureVerificationDirWritable($offer);
        } catch (UniqueConstraintViolationException $e) {
            if (File::isDirectory($targetPath)) {
                File::deleteDirectory($targetPath);
            }

            $existing = Offer::query()
                ->where('folder', $folder)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && ! $this->isRetiredStatus($existing->status)) {
                return $this->reuseExistingOffer(
                    $existing,
                    $folder,
                    rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$folder,
                );
            }

            throw new RuntimeException(
                'Оффер з такою папкою вже існує. Спробуйте ще раз — буде підібрано унікальну назву.',
                0,
                $e,
            );
        } catch (\Throwable $e) {
            // Keep the DB row if create already succeeded; only wipe a half-copied folder.
            if ($offer === null && File::isDirectory($targetPath)) {
                File::deleteDirectory($targetPath);
            }

            throw $e;
        }

        $this->ensureWorkerWritable($targetPath);

        return [
            'folder' => $folder,
            'path' => $targetPath,
            'offer' => $offer,
            'already_existed' => false,
        ];
    }

    /**
     * @return array{folder: string, path: string, offer: Offer, already_existed: bool}
     */
    private function resolveExisting(User $user, string $folder, string $targetPath): array
    {
        $existing = Offer::query()
            ->where('folder', $folder)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($this->isRetiredStatus($existing->status)) {
                throw new RuntimeException("Папка вже існує: {$folder}");
            }

            return [
                'folder' => $folder,
                'path' => $targetPath,
                'offer' => $existing,
                'already_existed' => true,
            ];
        }

        $manifestPath = $targetPath.'/manifest.json';

        if (File::exists($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);

            if (is_array($manifest)) {
                $offer = Offer::create([
                    'user_id' => $user->id,
                    'folder' => $folder,
                    'brand' => $manifest['brand'] ?? '',
                    'domain' => $manifest['domain'] ?? '',
                    'geo' => MarketOptions::normalizeGeo((string) ($manifest['geo'] ?? '')),
                    'lang' => strtolower($manifest['lang'] ?? ''),
                    'phone' => strtolower($manifest['phone'] ?? ''),
                    'phone_countries' => $manifest['phone_countries'] ?? strtolower($manifest['phone'] ?? ''),
                    'min_deposit' => (string) ($manifest['min_deposit'] ?? '250'),
                    'currency' => strtoupper((string) ($manifest['currency'] ?? 'EUR')),
                    'template' => $manifest['template'] ?? 'default',
                    'status' => $manifest['status'] ?? 'generated',
                ]);

                return [
                    'folder' => $folder,
                    'path' => $targetPath,
                    'offer' => $offer,
                    'already_existed' => true,
                ];
            }
        }

        throw new RuntimeException("Папка вже існує: {$folder}");
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function buildFolderName(array $input): string
    {
        $brandSlug = preg_replace('/\s+/', '-', trim($input['brand']));
        $date = now()->format('Y-m-d');
        $affiliate = $this->normalizeAffiliateTag($input['affiliate_tag'] ?? null);

        return sprintf(
            '%s_%s_%s_%s_%s_%s',
            MarketOptions::normalizeGeo((string) $input['geo']),
            strtolower($input['lang']),
            $affiliate,
            $brandSlug,
            strtolower($input['domain']),
            $date,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{folder: string, offer: ?Offer}
     */
    public function resolveTargetFolder(User $user, array $input): array
    {
        $base = $this->buildFolderName($input);
        $basePath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$base;
        $existing = Offer::query()->where('folder', $base)->first();

        if ($existing
            && (int) $existing->user_id === (int) $user->id
            && ! $this->isRetiredStatus($existing->status)
        ) {
            return ['folder' => $base, 'offer' => $existing];
        }

        if (! $existing && File::isDirectory($basePath)) {
            return ['folder' => $base, 'offer' => null];
        }

        return ['folder' => $this->uniqueFolderName($base), 'offer' => null];
    }

    public function uniqueFolderName(string $base): string
    {
        $folder = $base;
        $n = 2;

        while ($this->folderIsTaken($folder)) {
            $folder = $base.'_'.$n;
            $n++;

            if ($n > 99) {
                throw new RuntimeException("Не вдалося підібрати унікальну папку: {$base}");
            }
        }

        return $folder;
    }

    private function folderIsTaken(string $folder): bool
    {
        if (Offer::query()->where('folder', $folder)->exists()) {
            return true;
        }

        $path = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$folder;

        return File::isDirectory($path);
    }

    private function isRetiredStatus(?string $status): bool
    {
        return in_array($status, ['archived', 'archiving', 'purging', 'teardown_failed'], true);
    }

    /**
     * @return array{folder: string, path: string, offer: Offer, already_existed: bool}
     */
    private function reuseExistingOffer(Offer $offer, string $folder, string $targetPath): array
    {
        if (! File::isDirectory($targetPath) || ! $this->folderIsComplete($targetPath, $offer->template)) {
            try {
                $targetPath = $this->rebuildLocalFolder($offer);
            } catch (\Throwable) {
                // Offer row is the source of truth; deploy can rebuild later.
            }
        }

        return [
            'folder' => $folder,
            'path' => $targetPath,
            'offer' => $offer,
            'already_existed' => true,
        ];
    }

    private function normalizeAffiliateTag(?string $tag): string
    {
        $tag = strtoupper(trim((string) $tag));

        return $tag !== '' ? $tag : 'BRO';
    }

    public function ensureLocalFolder(Offer $offer): string
    {
        $targetPath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$offer->folder;

        $needsRebuild = false;

        if (File::isDirectory($targetPath) && $this->folderIsComplete($targetPath, $offer->template)) {
            // For multilang we rely on language switcher assets under `static/img/flags/*`.
            // If the folder was generated previously from `langs/<code>` (without root `static/`),
            // those files may be missing while the generic required files are still present.
            if (TemplateCatalog::isOverflowHub($offer->template) && ! File::isFile($targetPath.DIRECTORY_SEPARATOR.'static'.DIRECTORY_SEPARATOR.'img'.DIRECTORY_SEPARATOR.'flags'.DIRECTORY_SEPARATOR.'gb.png')) {
                $needsRebuild = true;
            }
        } else {
            $needsRebuild = true;
        }

        if (! $needsRebuild) {
            if (TemplateCatalog::isOverflowHub($offer->template)) {
                $this->pruneMultilangDuplicates($targetPath);
            }

            return $targetPath;
        }

        return $this->rebuildLocalFolder($offer);
    }

    /**
     * @return list<string>
     */
    public function requiredRelativePaths(?string $template = null): array
    {
        $paths = [
            'index.php',
            'contacts.php',
            'product.php',
            'offer.php',
            'faq.php',
            'sign.php',
            'privacy.php',
            'conditions.php',
            'includes/footer.php',
            'includes/head.php',
            'includes/form.php',
            'includes/config.php',
            'includes/helpers.php',
            'includes/schema.php',
            'integration/validation.js',
            'integration/visitor-geo.php',
            'integration/send.php',
            'integration/default-integration.css',
        ];

        if ($template === 'thalora') {
            return [
                ...$paths,
                'static/css/fonts.css',
                'static/css/tailwind.min.css',
                'static/css/form.css',
                'static/css/extra.css',
            ];
        }

        if ($template === 'vespera') {
            $paths = [
                'index.php',
                'templates/home.php',
                'templates/about.php',
                'templates/legal.php',
                'lang/en.php',
                'langs/en/index.php',
                'sitemap.php',
                'robots.php',
                'includes/config.php',
                'includes/helpers.php',
                'includes/geo.php',
                'includes/header.php',
                'includes/footer.php',
                'includes/lead-form.php',
                'includes/routes.php',
                'assets/css/global.css',
                'assets/img/ai-head.jpg',
                'integration/validation.js',
                'integration/visitor-geo.php',
                'integration/send.php',
                'integration/form-token.php',
                'integration/FormToken.php',
                'integration/LeadProcessor.php',
            ];

            $langDir = base_path('templates'.DIRECTORY_SEPARATOR.'vespera'.DIRECTORY_SEPARATOR.'lang');
            if (is_dir($langDir)) {
                foreach (scandir($langDir) ?: [] as $file) {
                    if (! preg_match('/^([a-z]{2})\.php$/', $file, $matches)) {
                        continue;
                    }
                    $paths[] = 'lang/'.$matches[1].'.php';
                    $paths[] = 'langs/'.$matches[1].'/index.php';
                }
            }

            return array_values(array_unique($paths));
        }

        return [
            ...$paths,
            'static/css/main.css',
        ];
    }

    private function folderIsComplete(string $targetPath, ?string $template = null): bool
    {
        foreach ($this->requiredRelativePaths($template) as $relativePath) {
            if (! File::isFile($targetPath.DIRECTORY_SEPARATOR.$relativePath)) {
                return false;
            }
        }

        return true;
    }

    public function rebuildLocalFolder(Offer $offer): string
    {
        $settings = $offer->user?->settings;

        if (! $settings || ! $settings->crm_api_key || ! $settings->tg_bot_token) {
            throw new InvalidArgumentException(
                'Налаштування користувача не знайдено або не заповнені CRM/Telegram ключі.',
            );
        }

        $this->ensureWorkerWritable($this->offersPath, recursive: false);

        $templateLangForSource = TemplateCatalog::copiesWholeTree($offer->template) ? 'en' : $offer->lang;
        $templatePath = $this->templateCatalog->resolveSourcePath($offer->template, $templateLangForSource);
        $targetPath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$offer->folder;

        $preservedFormTokenSecret = $this->extractFormTokenSecret($targetPath.'/includes/config.php');

        if (File::isDirectory($targetPath)) {
            File::deleteDirectory($targetPath);
        }

        File::copyDirectory($templatePath, $targetPath);
        $this->syncSharedIncludeFiles($targetPath, $offer->template);
        $this->syncSharedIntegrationFiles($targetPath, $offer->template);
        $this->syncSharedStaticFiles($targetPath, $offer->template, $offer->lang);

        if (TemplateCatalog::isOverflowHub($offer->template)) {
            $this->pruneMultilangDuplicates($targetPath);
        }

        if ($offer->template === 'vespera') {
            $this->syncVesperaLangFrontControllers($targetPath);
            $this->syncVesperaBrandAssets($targetPath, (string) $offer->brand);
        }

        $phones = $this->syncOfferPhoneFields($offer);

        $input = [
            'brand' => $offer->brand,
            'domain' => $offer->domain,
            'min_deposit' => $offer->min_deposit ?: '250',
            'currency' => $offer->currency ?: 'EUR',
            'geo' => $offer->geo,
            'lang' => $offer->lang,
            'phone' => $phones['phone'],
            'phone_countries' => $phones['phone_countries'],
            'template' => $offer->template,
            'form_token_secret' => $preservedFormTokenSecret,
            'vitals_enabled' => (bool) $offer->vitals_enabled,
            'from_search_team' => (bool) $offer->from_search_team,
        ];

        File::put($targetPath.'/includes/config.php', $this->configBuilder->build($input, $settings));

        $manifest = [
            'brand' => $offer->brand,
            'domain' => $offer->domain,
            'geo' => MarketOptions::normalizeGeo((string) $offer->geo),
            'lang' => strtolower($offer->lang),
            'phone' => $phones['phone'],
            'phone_countries' => $phones['phone_countries'],
            'min_deposit' => $offer->min_deposit ?: '250',
            'currency' => strtoupper($offer->currency ?: 'EUR'),
            'template' => $offer->template,
            'status' => $offer->status,
            'vitals_enabled' => (bool) $offer->vitals_enabled,
            'from_search_team' => (bool) $offer->from_search_team,
            'owner_id' => $offer->user_id,
            'folder' => $offer->folder,
            'deploy_panel' => $offer->deploy_panel_name,
            'remote_path' => $offer->remote_path,
            'deployed_at' => $offer->deployed_at?->toIso8601String(),
        ];

        File::put(
            $targetPath.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->migrateLegacyAssets($targetPath, $offer->template);
        if ($offer->template === 'vespera') {
            $this->syncVesperaBrandAssets($targetPath, (string) $offer->brand);
        }

        $this->prepareVerificationCopy($offer, $targetPath);
        $this->verificationFiles->syncToOfferFolder($offer);
        $this->ensureVerificationDirWritable($offer);
        $this->ensureWorkerWritable($targetPath);

        return $targetPath;
    }

    public function refreshConfig(Offer $offer): void
    {
        $settings = $offer->user?->settings;

        if (! $settings) {
            throw new InvalidArgumentException('Налаштування користувача не знайдено.');
        }

        $targetPath = $this->ensureLocalFolder($offer);
        $configPath = $targetPath.'/includes/config.php';

        $formTokenSecret = $this->extractFormTokenSecret($configPath);
        $phones = $this->syncOfferPhoneFields($offer);

        $input = [
            'brand' => $offer->brand,
            'domain' => $offer->domain,
            'min_deposit' => $offer->min_deposit ?: '250',
            'currency' => $offer->currency ?: 'EUR',
            'geo' => $offer->geo,
            'lang' => $offer->lang,
            'phone' => $phones['phone'],
            'phone_countries' => $phones['phone_countries'],
            'template' => $offer->template,
            'form_token_secret' => $formTokenSecret,
            'vitals_enabled' => (bool) $offer->vitals_enabled,
            'from_search_team' => (bool) $offer->from_search_team,
        ];

        File::ensureDirectoryExists(dirname($configPath));
        File::put($configPath, $this->configBuilder->build($input, $settings));
        $this->syncSharedIncludeFiles($targetPath, $offer->template);
        $this->syncSharedIntegrationFiles($targetPath, $offer->template);
        $this->syncSharedStaticFiles($targetPath, $offer->template, $offer->lang);
        if ($offer->template === 'vespera') {
            $this->syncVesperaLangFrontControllers($targetPath);
            $this->syncVesperaBrandAssets($targetPath, (string) $offer->brand);
        }
        $this->migrateLegacyAssets($targetPath, $offer->template);
        $this->prepareVerificationCopy($offer, $targetPath);
        $this->verificationFiles->syncToOfferFolder($offer);
        $this->ensureVerificationDirWritable($offer);
        $this->syncManifestFromOffer($offer, $targetPath);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateSettings(Offer $offer, array $input): Offer
    {
        $offer->loadMissing('user.settings');
        $settings = $offer->user?->settings;

        if (! $settings) {
            throw new InvalidArgumentException('Налаштування користувача не знайдено.');
        }

        if (in_array($offer->status, ['archived', 'archiving', 'purging', 'deploying'], true)) {
            throw new InvalidArgumentException(
                $offer->status === 'deploying'
                    ? 'Оффер зараз деплоїться — зачекайте і спробуйте знову.'
                    : 'Архівний оффер не можна редагувати.',
            );
        }

        $brand = trim((string) ($input['brand'] ?? $offer->brand));
        $template = trim((string) ($input['template'] ?? $offer->template));
        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => $template,
            'geo' => MarketOptions::normalizeGeo((string) ($input['geo'] ?? $offer->geo)),
            'lang' => strtolower(trim((string) ($input['lang'] ?? $offer->lang))),
            'phone' => strtolower((string) ($input['phone'] ?? $offer->phone)),
            'phone_countries' => $input['phone_countries'] ?? $offer->phone_countries ?? [],
        ]);
        $geo = $pinned['geo'];
        $lang = $pinned['lang'];
        $phone = strtolower((string) $pinned['phone']);
        $phoneCountriesCsv = $this->configBuilder->phoneCountriesCsv($pinned);
        $minDeposit = trim((string) ($input['min_deposit'] ?? $offer->min_deposit ?: '250'));
        $currency = strtoupper(trim((string) ($input['currency'] ?? $offer->currency ?: 'EUR')));

        if ($brand === '') {
            throw new InvalidArgumentException('Бренд не може бути порожнім.');
        }

        $allowedTemplates = $this->templateCatalog->ids();
        if (! in_array($template, $allowedTemplates, true)) {
            throw new InvalidArgumentException("Шаблон «{$template}» недоступний.");
        }

        if ($template !== (string) $offer->template && ! $this->templateCatalog->isEnabled($template)) {
            throw new InvalidArgumentException("Шаблон «{$template}» вимкнено.");
        }

        $allowedLangs = $this->templateCatalog->languageCodesFor($template);
        if (! in_array($lang, $allowedLangs, true)) {
            throw new InvalidArgumentException("Мова «{$lang}» недоступна для шаблону «{$template}».");
        }

        $needsRebuild = $lang !== strtolower((string) $offer->lang)
            || $template !== (string) $offer->template;

        $updates = [
            'brand' => $brand,
            'geo' => $geo,
            'lang' => $lang,
            'template' => $template,
            'min_deposit' => $minDeposit,
            'currency' => $currency,
            'phone' => $phone,
            'phone_countries' => $phoneCountriesCsv,
        ];

        if (array_key_exists('vitals_enabled', $input)) {
            $updates['vitals_enabled'] = (bool) $input['vitals_enabled'];
        }

        $offer->update($updates);
        $offer->refresh();

        if ($needsRebuild) {
            $this->rebuildLocalFolder($offer);
        } else {
            $this->refreshConfig($offer);
        }

        return $offer->fresh();
    }

    private function syncManifestFromOffer(Offer $offer, string $targetPath): void
    {
        $manifestPath = $targetPath.'/manifest.json';

        if (! File::exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest)) {
            return;
        }

        $manifest['brand'] = $offer->brand;
        $manifest['domain'] = $offer->domain;
        $manifest['geo'] = MarketOptions::normalizeGeo((string) $offer->geo);
        $manifest['lang'] = strtolower((string) $offer->lang);
        $phones = $this->syncOfferPhoneFields($offer);
        $manifest['phone'] = $phones['phone'];
        $manifest['phone_countries'] = $phones['phone_countries'];
        $manifest['min_deposit'] = $offer->min_deposit ?: '250';
        $manifest['currency'] = strtoupper($offer->currency ?: 'EUR');
        $manifest['template'] = $offer->template;
        $manifest['vitals_enabled'] = (bool) $offer->vitals_enabled;
        $manifest['from_search_team'] = (bool) $offer->from_search_team;
        $manifest['folder'] = $offer->folder;

        File::put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    /**
     * Origin nginx rewrites /{lang}/ to langs/{lang}/index.php (overflow hub).
     * Vespera has no lang packs there — drop a front-controller stub per lang/*.php.
     */
    public function syncVesperaLangFrontControllers(string $targetPath): void
    {
        $langDir = $targetPath.DIRECTORY_SEPARATOR.'lang';

        if (! File::isDirectory($langDir)) {
            return;
        }

        $boot = <<<'PHP'
<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/index.php';

PHP;

        foreach (File::files($langDir) as $file) {
            $code = strtolower($file->getFilenameWithoutExtension());

            if ($file->getExtension() !== 'php' || ! preg_match('/^[a-z]{2}$/', $code)) {
                continue;
            }

            $destDir = $targetPath.DIRECTORY_SEPARATOR.'langs'.DIRECTORY_SEPARATOR.$code;
            File::ensureDirectoryExists($destDir);
            File::put($destDir.DIRECTORY_SEPARATOR.'index.php', $boot);
        }
    }

    /**
     * Copy the stock hero into a brand-named file so Google Images can match the funnel.
     */
    public function syncVesperaBrandAssets(string $targetPath, string $brand): void
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $brand)) ?: 'offer';
        $candidates = [
            $targetPath.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'img',
            $targetPath.DIRECTORY_SEPARATOR.'static'.DIRECTORY_SEPARATOR.'img',
        ];

        foreach ($candidates as $imgDir) {
            $source = $imgDir.DIRECTORY_SEPARATOR.'ai-head.jpg';
            if (! File::isFile($source)) {
                continue;
            }

            File::copy($source, $imgDir.DIRECTORY_SEPARATOR.$slug.'-neural-core.jpg');
            $webp = $imgDir.DIRECTORY_SEPARATOR.'ai-head.webp';
            if (File::isFile($webp)) {
                File::copy($webp, $imgDir.DIRECTORY_SEPARATOR.$slug.'-neural-core.webp');
            }

            return;
        }
    }

    /**
     * Multilang pages load assets from offer root (`/static/*`, `/integration/*`).
     * Per-lang copies under langs/{code}/static and langs/{code}/integration are unused bloat.
     */
    public function pruneMultilangDuplicates(string $targetPath): void
    {
        $langsRoot = $targetPath.DIRECTORY_SEPARATOR.'langs';

        if (! File::isDirectory($langsRoot)) {
            return;
        }

        foreach (File::directories($langsRoot) as $langDir) {
            foreach (['static', 'integration'] as $sub) {
                $path = $langDir.DIRECTORY_SEPARATOR.$sub;

                if (File::isDirectory($path)) {
                    File::deleteDirectory($path);
                }
            }
        }
    }

    /**
     * helpers.php — єдине джерело в templates/{id}/includes/.
     * Мовні копії в langs/{code}/includes/ часто застарілі.
     */
    public function syncSharedIncludeFiles(string $targetPath, string $templateId = 'default'): void
    {
        $source = rtrim($this->templatesPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$templateId
            .DIRECTORY_SEPARATOR.'includes';

        if (! File::isDirectory($source)) {
            return;
        }

        $destination = $targetPath.DIRECTORY_SEPARATOR.'includes';
        File::ensureDirectoryExists($destination);

        foreach (['helpers.php'] as $filename) {
            $src = $source.DIRECTORY_SEPARATOR.$filename;

            if (File::isFile($src)) {
                File::copy($src, $destination.DIRECTORY_SEPARATOR.$filename);
            }
        }

        $this->writeKeitaroCompatStub($destination);
    }

    public function writeKeitaroCompatStub(string $includesDir): void
    {
        File::ensureDirectoryExists($includesDir);
        File::put($includesDir.DIRECTORY_SEPARATOR.'keitaro.php', $this->keitaroCompatStubSource());
    }

    public function keitaroCompatStubSource(): string
    {
        return <<<'PHP'
<?php
/**
 * Offerra no longer talks to Keitaro. Old landers still call these helpers
 * from localized forms / index.php — keep them as no-ops so pages do not 500.
 */
if (! function_exists('keitaro_is_enabled')) {
    function keitaro_is_enabled(): bool
    {
        return false;
    }
}

if (! function_exists('keitaro_should_track')) {
    function keitaro_should_track(): bool
    {
        return false;
    }
}

if (! function_exists('keitaro_session_key')) {
    function keitaro_session_key(): string
    {
        return function_exists('site_slug') ? site_slug().'_cid' : 'offerra_cid';
    }
}

if (! function_exists('keitaro_subid')) {
    function keitaro_subid(): string
    {
        return function_exists('offer_click_id') ? offer_click_id() : '';
    }
}

if (! function_exists('keitaro_create_client')) {
    function keitaro_create_client()
    {
        return null;
    }
}

if (! function_exists('keitaro_client_subid')) {
    function keitaro_client_subid($client): string
    {
        return keitaro_subid();
    }
}

if (! function_exists('keitaro_client_subid_from_result')) {
    function keitaro_client_subid_from_result($client): string
    {
        return keitaro_subid();
    }
}

if (! function_exists('keitaro_store_subid')) {
    function keitaro_store_subid(string $subid): void
    {
        if (! function_exists('offer_click_id_normalize')) {
            return;
        }

        $id = offer_click_id_normalize($subid);
        if ($id !== '') {
            $GLOBALS['_offer_cid'] = $id;
        }
    }
}

if (! function_exists('keitaro_bootstrap')) {
    function keitaro_bootstrap(): void
    {
    }
}
PHP;
    }

    /**
     * LeadProcessor / send.php — єдине джерело в templates/{id}/integration/.
     * Мовні копії та старі offers/ можуть мати застарілий код без TG group тощо.
     */
    public function syncSharedIntegrationFiles(string $targetPath, string $templateId = 'default'): void
    {
        $source = rtrim($this->templatesPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$templateId
            .DIRECTORY_SEPARATOR.'integration';

        if (! File::isDirectory($source)) {
            return;
        }

        $destination = $targetPath.DIRECTORY_SEPARATOR.'integration';
        File::ensureDirectoryExists($destination);

        foreach (File::files($source) as $file) {
            $name = $file->getFilename();
            $dest = $destination.DIRECTORY_SEPARATOR.$name;

            // Keep localized form strings from the lang pack / existing offer,
            // but patch packs that never fetch form-token.php (silent lead drop).
            if ($name === 'validation.js' && File::isFile($dest)) {
                $existing = File::get($dest);
                $patched = ValidationJsFormToken::ensure($existing);
                if ($patched !== $existing) {
                    File::put($dest, $patched);
                }
                continue;
            }

            File::copy($file->getPathname(), $dest);
        }

        $tokensDeny = $source.DIRECTORY_SEPARATOR.'tokens'.DIRECTORY_SEPARATOR.'.htaccess';
        if (File::isFile($tokensDeny)) {
            $tokensDir = $destination.DIRECTORY_SEPARATOR.'tokens';
            File::ensureDirectoryExists($tokensDir);
            File::copy($tokensDeny, $tokensDir.DIRECTORY_SEPARATOR.'.htaccess');
        }
    }

    /**
     * Language packs under langs/{code}/ may omit static/; copy from template root
     * or another lang pack (usually en).
     */
    public function syncSharedStaticFiles(string $targetPath, string $templateId = 'default', ?string $lang = null): void
    {
        $source = $this->resolveTemplateStaticSource($templateId, $lang);

        if ($source === null) {
            return;
        }

        $destination = $targetPath.DIRECTORY_SEPARATOR.'static';
        $marker = $templateId === 'thalora'
            ? $destination.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'fonts.css'
            : $destination.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'main.css';

        if (! File::isDirectory($destination) || ! File::isFile($marker)) {
            File::copyDirectory($source, $destination);
        }

        $cssSrc = $source.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'main.css';
        $cssDest = $destination.DIRECTORY_SEPARATOR.'css'.DIRECTORY_SEPARATOR.'main.css';
        if (File::isFile($cssSrc)) {
            File::ensureDirectoryExists(dirname($cssDest));
            File::copy($cssSrc, $cssDest);
        }
    }

    private function resolveTemplateStaticSource(string $templateId, ?string $lang = null): ?string
    {
        $templateRoot = rtrim($this->templatesPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$templateId;

        $candidates = [];

        if ($lang !== null && $lang !== '') {
            $candidates[] = $templateRoot.DIRECTORY_SEPARATOR.'langs'
                .DIRECTORY_SEPARATOR.strtolower($lang)
                .DIRECTORY_SEPARATOR.'static';
        }

        $candidates[] = $templateRoot.DIRECTORY_SEPARATOR.'static';

        foreach (['en', 'it', 'es', 'fr', 'de', 'pt', 'nl', 'da', 'no', 'hr'] as $fallbackLang) {
            $candidates[] = $templateRoot.DIRECTORY_SEPARATOR.'langs'
                .DIRECTORY_SEPARATOR.$fallbackLang
                .DIRECTORY_SEPARATOR.'static';
        }

        $langsRoot = $templateRoot.DIRECTORY_SEPARATOR.'langs';

        if (File::isDirectory($langsRoot)) {
            foreach (File::directories($langsRoot) as $langDir) {
                $candidates[] = $langDir.DIRECTORY_SEPARATOR.'static';
            }
        }

        $seen = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;

            if (File::isDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractFormTokenSecret(string $configPath): string
    {
        if (! File::exists($configPath)) {
            return '';
        }

        $config = File::get($configPath);

        if (preg_match("/define\('FORM_TOKEN_SECRET',\s*'([^']*)'\)/", $config, $matches)) {
            return (string) $matches[1];
        }

        return '';
    }

    /** Старі оффери з папкою assets/ — один раз перед деплоєм. Vespera keeps assets/. */
    public function migrateLegacyAssets(string $targetPath, ?string $template = null): void
    {
        if ($template === 'vespera') {
            return;
        }

        if (! File::isDirectory($targetPath.DIRECTORY_SEPARATOR.'assets')) {
            return;
        }

        $this->rewriteStaticAssetPaths($targetPath);
        $this->normalizeStaticAssets($targetPath);
    }

    public function rewriteStaticAssetPaths(string $targetPath): void
    {
        $includes = $targetPath.DIRECTORY_SEPARATOR.'includes';

        if (! File::isDirectory($includes)) {
            return;
        }

        foreach (File::files($includes) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = File::get($file->getPathname());
            $updated = preg_replace(
                [
                    "/asset\('assets\//",
                    '/asset\("assets\//',
                    "/'\/assets\//",
                    "/'assets\//",
                ],
                [
                    "asset('static/",
                    'asset("static/',
                    "'/static/",
                    "'static/",
                ],
                $content,
            );

            if ($updated !== null && $updated !== $content) {
                File::put($file->getPathname(), $updated);
            }
        }
    }

    public function normalizeStaticAssets(string $targetPath): void
    {
        $assets = $targetPath.DIRECTORY_SEPARATOR.'assets';
        $static = $targetPath.DIRECTORY_SEPARATOR.'static';

        if (! File::isDirectory($assets)) {
            return;
        }

        if (File::isDirectory($static)) {
            File::deleteDirectory($static);
        }

        if (! @rename($assets, $static)) {
            File::copyDirectory($assets, $static);
            File::deleteDirectory($assets);
        }
    }

    /**
     * Drop language codes (ja, en, …) from phone GEO and persist the clean list.
     *
     * @return array{phone: string, phone_countries: string}
     */
    private function syncOfferPhoneFields(Offer $offer): array
    {
        $pinned = TemplateCatalog::pinVesperaMarket([
            'template' => (string) $offer->template,
            'geo' => (string) $offer->geo,
            'lang' => (string) $offer->lang,
            'phone' => (string) $offer->phone,
            'phone_countries' => $offer->phone_countries ?? '',
        ]);
        $normalized = MarketOptions::normalizePhoneFields(
            (string) $pinned['phone'],
            $pinned['phone_countries'] ?? '',
            (string) $pinned['geo'],
        );
        $csv = implode(',', $normalized['phone_countries']);

        if (
            strtolower((string) $offer->phone) !== $normalized['phone']
            || (string) $offer->phone_countries !== $csv
        ) {
            $offer->forceFill([
                'phone' => $normalized['phone'],
                'phone_countries' => $csv,
            ])->save();
        }

        return [
            'phone' => $normalized['phone'],
            'phone_countries' => $csv,
        ];
    }

    private function prepareVerificationCopy(Offer $offer, string $targetPath): void
    {
        $this->ensureWorkerWritable($this->offersPath, recursive: false);
        $this->ensureWorkerWritable($targetPath);
        $this->ensureWorkerWritable(storage_path('app/offer-verification'), recursive: false);
        $this->ensureVerificationDirWritable($offer);
        $this->ensureWorkerWritable(storage_path('app/user-verification'), recursive: false);
    }

    private function ensureVerificationDirWritable(Offer $offer): void
    {
        $this->ensureWorkerWritable(storage_path('app/offer-verification/'.$offer->id));
    }

    /**
     * Queue workers run as the web user. Artisan/CLI as root must not leave
     * offer folders owned by root — refreshConfig then fails with EACCES.
     */
    public function ensureWorkerWritable(string $path, bool $recursive = true): void
    {
        if ($path === '') {
            return;
        }

        if (! is_dir($path) && ! is_file($path)) {
            @mkdir($path, 0775, true);
        }

        if (! is_dir($path) && ! is_file($path)) {
            return;
        }

        $owner = $this->panelWebOwner();
        if ($owner === null) {
            return;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === $owner['uid']) {
            return;
        }

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $user = escapeshellarg($owner['name']);
        $quoted = escapeshellarg($path);
        $flag = ($recursive && is_dir($path)) ? '-R ' : '';
        exec('chown '.$flag.$user.':'.$user.' '.$quoted.' && chmod '.$flag.'ug+rwX '.$quoted, $out, $code);

        if ($code !== 0) {
            Log::warning('Could not chown offer folder for queue workers', [
                'path' => $path,
                'owner' => $owner['name'],
                'code' => $code,
            ]);
        }
    }

    /**
     * @return array{uid: int, name: string}|null
     */
    private function panelWebOwner(): ?array
    {
        foreach ([storage_path('logs'), storage_path('app'), storage_path('framework')] as $probe) {
            if (! is_dir($probe)) {
                continue;
            }

            $uid = @fileowner($probe);
            if (! is_int($uid) || $uid === 0) {
                continue;
            }

            $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : null;
            $name = is_array($info) ? (string) ($info['name'] ?? '') : '';

            return [
                'uid' => $uid,
                'name' => $name !== '' ? $name : 'www-data',
            ];
        }

        return ['uid' => 33, 'name' => 'www-data'];
    }
}
