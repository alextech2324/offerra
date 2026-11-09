<?php

namespace App\Services;

use App\Models\Offer;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Assemble the same lander deploy uploads, then pack it as a downloadable ZIP.
 */
class OfferZipExporter
{
    /** @var list<string> */
    private const SKIP_FILES = [
        'dev-server.ps1',
        'router.php',
        '.gitignore',
        '.DS_Store',
        'Thumbs.db',
    ];

    public function __construct(
        private readonly OfferGenerator $generator,
        private readonly string $offersPath,
    ) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function build(Offer $offer): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервері немає PHP zip. Увімкніть розширення zip.');
        }

        $offer->loadMissing('user.settings');
        $this->generator->refreshConfig($offer);

        $localPath = rtrim($this->offersPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$offer->folder;

        if (! File::isFile($localPath.DIRECTORY_SEPARATOR.'index.php')) {
            throw new RuntimeException('Не вдалося зібрати ленд: немає index.php.');
        }

        $filename = $this->downloadName($offer);
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'offerra-zip-'.$offer->id.'-'.bin2hex(random_bytes(4)).'.zip';

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Не вдалося створити ZIP-архів.');
        }

        try {
            $this->addDirectory($zip, $localPath);
        } finally {
            $zip->close();
        }

        if (! is_file($zipPath) || filesize($zipPath) < 32) {
            @unlink($zipPath);
            throw new RuntimeException('ZIP вийшов порожнім.');
        }

        return [
            'path' => $zipPath,
            'filename' => $filename,
        ];
    }

    public function downloadName(Offer $offer): string
    {
        $domain = strtolower(trim((string) $offer->domain));
        $safe = preg_replace('/[^a-z0-9.-]+/', '-', $domain) ?: 'offer';

        return $safe.'.zip';
    }

    private function addDirectory(ZipArchive $zip, string $localPath): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $added = 0;

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($localPath))), '/');

            if ($relative === '' || $this->shouldSkip($relative, $file->getFilename())) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);

                continue;
            }

            if (! $file->isFile()) {
                continue;
            }

            if (! $zip->addFile($absolute, $relative)) {
                throw new RuntimeException('Не вдалося додати файл у ZIP: '.$relative);
            }

            $added++;
        }

        if ($added === 0) {
            throw new RuntimeException('У зібраному ленді немає файлів для ZIP.');
        }
    }

    private function shouldSkip(string $relative, string $basename): bool
    {
        $relative = strtolower(str_replace('\\', '/', $relative));
        $basename = strtolower($basename);

        foreach (self::SKIP_FILES as $skip) {
            $skip = strtolower($skip);
            if ($basename === $skip || $relative === $skip) {
                return true;
            }
        }

        return false;
    }
}
