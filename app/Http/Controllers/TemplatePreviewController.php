<?php

namespace App\Http\Controllers;

use App\Services\TemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TemplatePreviewController extends Controller
{
    public function __construct(
        private readonly TemplateCatalog $catalog,
    ) {}

    public function show(string $template, ?string $path = null): Response|BinaryFileResponse|RedirectResponse
    {
        if (! in_array($template, $this->catalog->ids(), true)) {
            abort(404);
        }

        $lang = request()->query('lang');
        $lang = is_string($lang) ? strtolower($lang) : null;

        try {
            $basePath = $this->catalog->previewRootPath($template, $lang);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if (! is_dir($basePath)) {
            abort(404);
        }

        $path = trim((string) $path, '/');

        if ($path === '' && ! str_ends_with(request()->getPathInfo(), '/')) {
            return redirect('/preview/'.$template.'/', 301);
        }

        if ($path === '') {
            $path = 'index.php';
        }

        if ($path === 'robots.txt') {
            $path = 'robots.php';
        }

        if ($path === 'sitemap.xml') {
            $path = 'sitemap.php';
        }

        // Allow explicit /preview/{id}/langs/{code}/… while keeping asset URLs on /preview/{id}/
        if (preg_match('#^langs/([a-z]{2})(?:/(.*))?$#', $path, $matches)) {
            try {
                $basePath = $this->catalog->resolveSourcePath($template, $matches[1]);
            } catch (\InvalidArgumentException) {
                abort(404);
            }
            $path = ($matches[2] ?? '') !== '' ? $matches[2] : 'index.php';
        }

        $fullPath = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realBase = realpath($basePath);
        $realFile = realpath($fullPath);

        if (! $realBase || ! $realFile || ! str_starts_with($realFile, $realBase)) {
            $shared = $this->catalog->resolvePreviewAsset($template, $path);
            if ($shared !== null) {
                $realFile = $shared;
            } else {
                $frontController = $basePath.DIRECTORY_SEPARATOR.'index.php';
                if ($template === 'vespera' && is_file($frontController)) {
                    return $this->servePhp($template, $frontController, $path);
                }

                abort(404);
            }
        }

        if (is_dir($realFile)) {
            $index = $realFile.DIRECTORY_SEPARATOR.'index.php';

            if (! is_file($index)) {
                abort(404);
            }

            return $this->servePhp($template, $index, $path);
        }

        $extension = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            return $this->servePhp($template, $realFile, $path);
        }

        if (! in_array($extension, ['css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'map', 'json', 'txt'], true)) {
            abort(404);
        }

        return response()->file($realFile, [
            'Content-Type' => $this->mimeType($extension),
        ]);
    }

    private function servePhp(string $template, string $absoluteFile, string $webPath): Response
    {
        if (! is_file($absoluteFile)) {
            abort(404);
        }

        $script = base_path('scripts/render-offer-php.php');
        $webPath = ltrim($webPath, '/');

        if ($webPath === '' || $webPath === 'index.php') {
            $requestUri = '/preview/'.$template.'/';
        } else {
            $requestUri = '/preview/'.$template.'/'.ltrim($webPath, '/');
        }

        $httpHost = request()->getHttpHost();

        $result = Process::timeout(30)->run([
            $this->phpCliBinary(),
            $script,
            $absoluteFile,
            $requestUri,
            $httpHost,
        ]);

        if (! $result->successful()) {
            abort(500, 'Помилка рендеру шаблону: '.trim($result->errorOutput() ?: $result->output()));
        }

        return response($result->output(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function phpCliBinary(): string
    {
        $binary = PHP_BINARY;

        if ($binary !== '' && is_executable($binary) && ! str_contains(basename($binary), 'fpm')) {
            return $binary;
        }

        foreach (['/usr/bin/php', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'json' => 'application/json; charset=UTF-8',
            'txt' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
}
