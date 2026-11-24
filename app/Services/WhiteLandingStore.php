<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhiteLanding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WhiteLandingStore
{
    public const MAX_ZIP_BYTES = 25 * 1024 * 1024;

    public const MAX_UNCOMPRESSED_BYTES = 80 * 1024 * 1024;

    public const MAX_FILES = 400;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'css', 'js', 'mjs', 'json', 'xml', 'txt', 'svg',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'ogg', 'mp3',
        'pdf', 'map', 'webmanifest',
    ];

    /** @var list<string> */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'cgi', 'exe', 'bat', 'cmd', 'sh', 'pl', 'py', 'rb', 'asp', 'aspx',
        'jsp', 'htaccess', 'htpasswd',
    ];

    /** @var array<string, string> */
    private const MIME = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    public function store(User $user, UploadedFile $zip, ?string $name = null): WhiteLanding
    {
        $this->assertZipUpload($zip);

        $extracted = $this->extractToTemp($zip->getRealPath() ?: $zip->getPathname());
        $landing = null;

        try {
            $label = $this->resolveName($name, $zip->getClientOriginalName());

            $landing = WhiteLanding::query()->create([
                'user_id' => $user->id,
                'name' => $label,
                'entry_file' => $extracted['entry_file'],
                'file_count' => $extracted['file_count'],
                'size_bytes' => $extracted['size_bytes'],
                'original_filename' => Str::limit((string) $zip->getClientOriginalName(), 180, ''),
            ]);

            $dest = $this->filesPath($landing);
            File::ensureDirectoryExists(dirname($dest), 0755);
            File::moveDirectory($extracted['path'], $dest, true);

            return $landing->fresh() ?? $landing;
        } catch (\Throwable $e) {
            if ($landing) {
                $landing->delete();
            }

            throw $e;
        } finally {
            if (isset($extracted['path']) && File::isDirectory($extracted['path'])) {
                File::deleteDirectory($extracted['path']);
            }
        }
    }

    public function delete(WhiteLanding $landing): void
    {
        $inUse = $landing->offers()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->exists();

        if ($inUse) {
            throw new InvalidArgumentException(
                'Цей вайт уже привʼязаний до живих оферів. Спочатку вимкни кло або заархівуй їх.',
            );
        }

        $path = $this->filesPath($landing);
        $landing->delete();

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }

    public function filesPath(WhiteLanding $landing): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$landing->user_id
            .DIRECTORY_SEPARATOR
            .$landing->id;
    }

    public function preview(WhiteLanding $landing, string $path = ''): BinaryFileResponse
    {
        $base = $this->filesPath($landing);
        $realBase = realpath($base);

        if (! $realBase || ! is_dir($realBase)) {
            abort(404);
        }

        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '' || $path === '.') {
            $path = $landing->entry_file ?: 'index.html';
        }

        if ($this->isUnsafeRelativePath($path)) {
            abort(404);
        }

        $full = $realBase.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realFile = realpath($full);
        $prefix = $realBase.DIRECTORY_SEPARATOR;

        if (! $realFile || ! is_file($realFile) || ! str_starts_with($realFile, $prefix)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            abort(404);
        }

        return response()->file($realFile, [
            'Content-Type' => self::MIME[$ext] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=120',
            'Content-Security-Policy' => "sandbox allow-scripts allow-same-origin; default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:;",
        ]);
    }

    private function assertZipUpload(UploadedFile $zip): void
    {
        if (! $zip->isValid()) {
            throw new InvalidArgumentException('ZIP не завантажився. Спробуй ще раз.');
        }

        $ext = strtolower($zip->getClientOriginalExtension());
        if ($ext !== 'zip') {
            throw new InvalidArgumentException('Потрібен ZIP-архів з вайтом.');
        }

        $size = (int) $zip->getSize();
        if ($size <= 0 || $size > self::MAX_ZIP_BYTES) {
            throw new InvalidArgumentException('ZIP має бути до 25 МБ.');
        }
    }

    /**
     * @return array{path: string, entry_file: string, file_count: int, size_bytes: int}
     */
    private function extractToTemp(string $zipPath): array
    {
        $archive = new ZipArchive;
        $opened = $archive->open($zipPath);

        if ($opened !== true) {
            throw new InvalidArgumentException('Не вдалося відкрити ZIP. Перевір, що архів не пошкоджений.');
        }

        $tmp = $this->root.DIRECTORY_SEPARATOR.'_tmp_'.Str::uuid()->toString();
        File::ensureDirectoryExists($tmp, 0755);

        try {
            $entries = $this->safeEntries($archive);
            $prefix = $this->singleRootPrefix($entries);
            $written = 0;
            $bytes = 0;
            $entryFile = null;

            foreach ($entries as $entry) {
                $relative = $prefix === ''
                    ? $entry['name']
                    : substr($entry['name'], strlen($prefix));

                if ($relative === false || $relative === '' || $this->isUnsafeRelativePath($relative)) {
                    throw new InvalidArgumentException('ZIP містить небезпечні шляхи. Перепакуй вайт.');
                }

                $dest = $tmp.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($dest), 0755);

                $stream = $archive->getStream($entry['name']);
                if ($stream === false) {
                    throw new InvalidArgumentException('Не вдалося прочитати файл з ZIP.');
                }

                $out = fopen($dest, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Не вдалося розпакувати вайт на диск.');
                }

                $copied = stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);

                if ($copied === false) {
                    throw new RuntimeException('Не вдалося розпакувати вайт на диск.');
                }

                $written++;
                $bytes += (int) $entry['size'];

                $base = strtolower(basename($relative));
                if ($relative === $base && ($base === 'index.html' || $base === 'index.htm')) {
                    $entryFile = $base;
                }
            }

            if ($entryFile === null) {
                throw new InvalidArgumentException(
                    'У корені ZIP має бути index.html (PHP-файли не приймаємо).',
                );
            }

            $archive->close();

            return [
                'path' => $tmp,
                'entry_file' => $entryFile,
                'file_count' => $written,
                'size_bytes' => $bytes,
            ];
        } catch (\Throwable $e) {
            $archive->close();
            if (File::isDirectory($tmp)) {
                File::deleteDirectory($tmp);
            }

            throw $e;
        }
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    private function safeEntries(ZipArchive $archive): array
    {
        $count = $archive->numFiles;
        if ($count <= 0) {
            throw new InvalidArgumentException('ZIP порожній.');
        }

        if ($count > self::MAX_FILES * 2) {
            throw new InvalidArgumentException('Забагато файлів у ZIP (ліміт '.self::MAX_FILES.').');
        }

        $entries = [];
        $uncompressed = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $archive->statIndex($i);
            if (! is_array($stat) || empty($stat['name'])) {
                continue;
            }

            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === '' || str_ends_with($name, '/') || $this->shouldSkipZipName($name)) {
                continue;
            }

            if ($this->isUnsafeZipName($name)) {
                throw new InvalidArgumentException('ZIP містить небезпечні шляхи. Перепакуй вайт.');
            }

            $base = strtolower(basename($name));
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

            if ($ext === '' || in_array($ext, self::BLOCKED_EXTENSIONS, true) || $base === '.htaccess' || $base === '.user.ini') {
                throw new InvalidArgumentException(
                    'Вайт має бути HTML/CSS/JS/картинки. PHP і скрипти сервера не приймаємо.',
                );
            }

            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException("Файл «{$base}» не підходить для вайту.");
            }

            $size = (int) ($stat['size'] ?? 0);
            $uncompressed += max(0, $size);

            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('Розпакований вайт завеликий (ліміт 80 МБ).');
            }

            $entries[] = [
                'name' => $name,
                'size' => $size,
            ];
        }

        if ($entries === []) {
            throw new InvalidArgumentException('У ZIP немає файлів вайту.');
        }

        if (count($entries) > self::MAX_FILES) {
            throw new InvalidArgumentException('Забагато файлів у ZIP (ліміт '.self::MAX_FILES.').');
        }

        return $entries;
    }

    /**
     * @param  list<array{name: string, size: int}>  $entries
     */
    private function singleRootPrefix(array $entries): string
    {
        $hasRootFile = false;
        $tops = [];

        foreach ($entries as $entry) {
            $slash = strpos($entry['name'], '/');
            if ($slash === false) {
                $hasRootFile = true;
                break;
            }

            $tops[substr($entry['name'], 0, $slash)] = true;
        }

        if ($hasRootFile || count($tops) !== 1) {
            return '';
        }

        return array_key_first($tops).'/';
    }

    private function shouldSkipZipName(string $name): bool
    {
        foreach (explode('/', $name) as $segment) {
            if ($segment === '__MACOSX' || $segment === '.DS_Store') {
                return true;
            }

            if (str_starts_with($segment, '.') && $segment !== '.' && $segment !== '..') {
                return true;
            }
        }

        return false;
    }

    private function isUnsafeZipName(string $name): bool
    {
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return true;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function isUnsafeRelativePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return $path === '' || $this->isUnsafeZipName($path);
    }

    private function resolveName(?string $name, ?string $original): string
    {
        $label = trim((string) $name);
        if ($label === '') {
            $label = pathinfo((string) $original, PATHINFO_FILENAME);
        }

        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        $label = Str::limit($label, 80, '');

        if ($label === '') {
            $label = 'White '.now()->format('Y-m-d H:i');
        }

        return $label;
    }
}
