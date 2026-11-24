<?php

namespace App\Services;

use App\Models\Offer;
use App\Support\CloakOptions;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

class OfferCloakSync
{
    public const RUNTIME_FILES = [
        'cloak.php',
        'cloak.json',
        'googlebot.json',
    ];

    public function __construct(
        private readonly WhiteLandingStore $whites,
    ) {}

    public function sync(Offer $offer, string $targetPath): void
    {
        $targetPath = rtrim($targetPath, '/\\');
        $cloak = CloakOptions::fromOffer($offer);

        if (! $cloak['enabled']) {
            $this->remove($targetPath);

            return;
        }

        $landing = $offer->whiteLanding;
        if (! $landing) {
            throw new RuntimeException("Офер #{$offer->id}: кло увімкнено, але вайт зник.");
        }

        $source = $this->whites->filesPath($landing);
        $entry = $landing->entry_file ?: 'index.html';
        if (! is_file($source.DIRECTORY_SEPARATOR.$entry)) {
            throw new RuntimeException("Офер #{$offer->id}: у вайті немає {$entry}.");
        }

        $runtime = resource_path('cloak');
        foreach (['cloak.php', 'googlebot.json'] as $file) {
            $from = $runtime.DIRECTORY_SEPARATOR.$file;
            if (! is_file($from)) {
                throw new RuntimeException("Немає {$file} у resources/cloak.");
            }
            File::copy($from, $targetPath.DIRECTORY_SEPARATOR.$file);
        }

        File::put(
            $targetPath.DIRECTORY_SEPARATOR.'cloak.json',
            json_encode([
                'countries' => CloakOptions::countriesFor($offer),
                'panel_ips' => config('offerra.panel_ips', ['38.180.121.50']),
                'entry' => $entry,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $whiteDest = $targetPath.DIRECTORY_SEPARATOR.'white';
        if (File::isDirectory($whiteDest)) {
            File::deleteDirectory($whiteDest);
        }
        File::copyDirectory($source, $whiteDest);

        $this->ensureBootInConfigFiles($targetPath);
    }

    public function remove(string $targetPath): void
    {
        $targetPath = rtrim($targetPath, '/\\');

        foreach (self::RUNTIME_FILES as $file) {
            $path = $targetPath.DIRECTORY_SEPARATOR.$file;
            if (is_file($path)) {
                File::delete($path);
            }
        }

        $white = $targetPath.DIRECTORY_SEPARATOR.'white';
        if (File::isDirectory($white)) {
            File::deleteDirectory($white);
        }
    }

    private function ensureBootInConfigFiles(string $targetPath): void
    {
        if (! File::isDirectory($targetPath)) {
            return;
        }

        /** @var list<SplFileInfo> $files */
        $files = File::allFiles($targetPath);

        foreach ($files as $file) {
            if ($file->getFilename() !== 'config.php') {
                continue;
            }
            if (! str_ends_with(str_replace('\\', '/', $file->getPath()), '/includes')) {
                continue;
            }
            $this->ensureBootSnippet($file->getPathname());
        }
    }

    private function ensureBootSnippet(string $configPath): void
    {
        $src = File::get($configPath);
        if (str_contains($src, 'cloak.php')) {
            return;
        }

        $boot = <<<'PHP'
$__offerra_cloak = dirname(__DIR__) . '/cloak.php';
if (!is_file($__offerra_cloak)) {
    $__offerra_cloak = dirname(__DIR__, 2) . '/cloak.php';
}
if (!is_file($__offerra_cloak)) {
    $__offerra_cloak = dirname(__DIR__, 3) . '/cloak.php';
}
if (is_file($__offerra_cloak)) {
    require_once $__offerra_cloak;
}
unset($__offerra_cloak);

PHP;

        if (preg_match('/^<\?php\s*/', $src)) {
            $src = preg_replace('/^<\?php\s*/', "<?php\n".$boot, $src, 1) ?? $src;
        } else {
            $src = "<?php\n".$boot.'?>'.$src;
        }

        File::put($configPath, $src);
    }
}
