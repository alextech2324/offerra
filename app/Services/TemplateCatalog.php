<?php

namespace App\Services;

use App\Support\MarketOptions;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class TemplateCatalog
{
    public function __construct(
        private readonly string $templatesPath,
    ) {}

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_column($this->forWizard(), 'id');
    }

    /**
     * @return list<array{id: string, name: string, languages: list<array{code: string, name: string}>}>
     */
    public function forWizard(): array
    {
        $templates = [];

        foreach ($this->discoverTemplateIds() as $id) {
            $templates[] = [
                'id' => $id,
                'name' => $this->label($id),
                'languages' => $this->languagesFor($id),
            ];
        }

        return $templates;
    }

    /**
     * @return list<array{id: string, name: string, description: string, pages: string, active: bool}>
     */
    public function forLibrary(): array
    {
        $items = [];

        foreach ($this->discoverTemplateIds() as $id) {
            $langs = $this->languagesFor($id);

            $items[] = [
                'id' => $id,
                'name' => $this->label($id),
                'description' => $this->libraryDescription($id, $langs),
                'languages' => $langs,
                'pages' => 'index, product, offer, faq, contacts, sign, thanks',
                'preview_url' => route('templates.preview', ['template' => $id]).'/',
                'active' => true,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function languagesFor(string $templateId): array
    {
        $languages = [];

        foreach (array_keys($this->templateLanguageSources($templateId)) as $code) {
            $languages[] = [
                'code' => $code,
                'name' => $this->languageLabel($code),
            ];
        }

        usort($languages, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $languages;
    }

    /**
     * @return list<string>
     */
    public function languageCodesFor(string $templateId): array
    {
        return array_column($this->languagesFor($templateId), 'code');
    }

    public function resolveSourcePath(string $templateId, string $lang): string
    {
        $lang = strtolower($lang);
        $sources = $this->templateLanguageSources($templateId);

        if (isset($sources[$lang]) && File::isDirectory($sources[$lang])) {
            return $sources[$lang];
        }

        $templatePath = $this->templatePath($templateId);

        if ($templatePath && File::isFile($templatePath.DIRECTORY_SEPARATOR.'index.php')) {
            return $templatePath;
        }

        throw new InvalidArgumentException(
            "Мова «{$lang}» недоступна для шаблону «{$templateId}».",
        );
    }

    public function label(string $templateId): string
    {
        return match ($templateId) {
            'default-pro' => 'Default Pro',
            default => $templateId,
        };
    }

    /** Copy the template root (dictionary i18n or overflow hub), not one langs/{code}/ folder. */
    public static function copiesWholeTree(string $templateId): bool
    {
        return in_array($templateId, ['multilang', 'vespera'], true);
    }

    /** Old overflow hub only — Vespera is a separate ML offer. */
    public static function isOverflowHub(string $templateId): bool
    {
        return $templateId === 'multilang';
    }

    public static function usesMlGeo(string $templateId): bool
    {
        return in_array($templateId, ['multilang', 'vespera'], true);
    }

    /**
     * Vespera can sit in a single-GEO pack (min deposit + currency from the form),
     * but the stored offer is always ML / en — not the market GEO/lang.
     */
    public static function pinVesperaMarket(array $input): array
    {
        if (($input['template'] ?? '') !== 'vespera') {
            return $input;
        }

        $input['geo'] = 'ML';
        $input['lang'] = 'en';
        $input['phone'] = 'ip';
        $input['phone_countries'] = MarketOptions::allPhoneCodes();

        return $input;
    }

    /**
     * Filesystem root used for /preview/{template}/… rendering.
     * Prefer template root when it has index.php; otherwise the requested
     * (or first available) language under langs/.
     */
    public function previewRootPath(string $templateId, ?string $lang = null): string
    {
        $templatePath = $this->templatePath($templateId);

        if (! $templatePath) {
            throw new InvalidArgumentException("Шаблон «{$templateId}» не знайдено.");
        }

        $lang = $lang !== null ? strtolower($lang) : null;
        $codes = $this->languageCodesFor($templateId);

        if ($lang !== null && $lang !== '' && in_array($lang, $codes, true)) {
            return $this->resolveSourcePath($templateId, $lang);
        }

        if (File::isFile($templatePath.DIRECTORY_SEPARATOR.'index.php')) {
            return $templatePath;
        }

        if ($codes === []) {
            return $templatePath;
        }

        $defaultLang = in_array('en', $codes, true) ? 'en' : $codes[0];

        return $this->resolveSourcePath($templateId, $defaultLang);
    }

    /**
     * Language packs often omit static/ and shared integration CSS.
     * Preview URLs still request them from /preview/{id}/static/… — look in
     * the template root, then langs/en, then any other lang pack that has the file.
     */
    public function resolvePreviewAsset(string $templateId, string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $templatePath = $this->templatePath($templateId);

        if (! $templatePath) {
            return null;
        }

        $root = realpath($templatePath);

        if (! $root) {
            return null;
        }

        $candidates = [$templatePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath)];

        foreach ($this->previewAssetLangOrder($templateId) as $code) {
            try {
                $dir = $this->resolveSourcePath($templateId, $code);
            } catch (InvalidArgumentException) {
                continue;
            }

            $candidates[] = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        $seen = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $real = realpath($candidate);

            if ($real && is_file($real) && str_starts_with($real, $root)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function previewAssetLangOrder(string $templateId): array
    {
        $codes = $this->languageCodesFor($templateId);
        $ordered = [];

        if (in_array('en', $codes, true)) {
            $ordered[] = 'en';
        }

        foreach ($codes as $code) {
            if ($code !== 'en') {
                $ordered[] = $code;
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function discoverTemplateIds(): array
    {
        if (! File::isDirectory($this->templatesPath)) {
            return [];
        }

        $ids = [];

        foreach (File::directories($this->templatesPath) as $directory) {
            $id = basename($directory);

            if (str_starts_with($id, '.')) {
                continue;
            }

            $ids[] = $id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, string> lang code => folder path (templates only)
     */
    private function templateLanguageSources(string $templateId): array
    {
        $sources = [];
        $templatePath = $this->templatePath($templateId);

        if ($templatePath && File::isDirectory($templatePath)) {
            $langsDir = $templatePath.DIRECTORY_SEPARATOR.'langs';

            if (File::isDirectory($langsDir)) {
                foreach (File::directories($langsDir) as $directory) {
                    $code = strtolower(basename($directory));

                    if (preg_match('/^[a-z]{2}$/', $code)) {
                        $sources[$code] = $directory;
                    }
                }
            }

            $langFilesDir = $templatePath.DIRECTORY_SEPARATOR.'lang';

            if (File::isDirectory($langFilesDir)) {
                foreach (File::files($langFilesDir) as $file) {
                    $code = strtolower($file->getFilenameWithoutExtension());

                    if ($file->getExtension() === 'php' && preg_match('/^[a-z]{2}$/', $code)) {
                        $sources[$code] = $templatePath;
                    }
                }
            }

            $rootLang = $this->readSiteLang($templatePath);

            if ($rootLang && ! isset($sources[$rootLang])) {
                $sources[$rootLang] = $templatePath;
            }
        }

        return $sources;
    }

    private function templatePath(string $templateId): ?string
    {
        $path = rtrim($this->templatesPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$templateId;

        return File::isDirectory($path) ? $path : null;
    }

    private function readSiteLang(string $directory): ?string
    {
        $configPath = $directory.'/includes/config.php';

        if (! File::exists($configPath)) {
            return null;
        }

        if (preg_match("/define\s*\(\s*'SITE_LANG'\s*,\s*'([a-z]{2})'\s*\)/i", File::get($configPath), $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function languageLabel(string $code): string
    {
        foreach (config('offerra.languages', []) as $language) {
            if ($language['code'] === $code) {
                return $language['name'];
            }
        }

        return strtoupper($code);
    }

    /**
     * @param  list<array{code: string, name: string}>  $languages
     */
    private function libraryDescription(string $templateId, array $languages): string
    {
        $codes = array_column($languages, 'code');

        if ($codes === []) {
            return 'PHP-ленд · templates/'.$templateId.'/ · мови не знайдено';
        }

        $labels = array_map(
            fn (array $lang) => $lang['name'].' ('.$lang['code'].')',
            $languages,
        );

        $base = 'PHP-ленд · '.count($codes).' '.(count($codes) === 1 ? 'мова' : 'мови').': '.implode(', ', $labels);

        return $templateId === 'default-pro'
            ? $base.' · branded SEO copy (H1, FAQ, GEO)'
            : $base;
    }
}
