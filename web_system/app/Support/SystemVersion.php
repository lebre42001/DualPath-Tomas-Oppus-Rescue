<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SystemVersion
{
    private const CACHE_KEY = 'system-version.current';
    private const CACHE_SECONDS = 15;

    /**
     * @var array<int, string>
     */
    private const WATCH_PATHS = [
        'app',
        'config',
        'database/migrations',
        'public/build',
        'public/manifest.webmanifest',
        'public/offline.html',
        'public/auth-password.js',
        'public/pwa-helper.js',
        'public/sw.js',
        'resources/css',
        'resources/js',
        'resources/views',
        'routes',
        'composer.lock',
        'package-lock.json',
        'vite.config.js',
    ];

    public function current(): string
    {
        return (string) cache()->remember(
            self::CACHE_KEY,
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): string => $this->buildVersionFingerprint()
        );
    }

    private function buildVersionFingerprint(): string
    {
        $entries = [];

        foreach (self::WATCH_PATHS as $relativePath) {
            $absolutePath = base_path($relativePath);

            if (! file_exists($absolutePath)) {
                continue;
            }

            if (is_file($absolutePath)) {
                $entries[] = $this->normalizeEntry($relativePath, @filemtime($absolutePath) ?: 0, @filesize($absolutePath) ?: 0);
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }

                $entries[] = $this->normalizeEntry(
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $fileInfo->getPathname()),
                    $fileInfo->getMTime(),
                    $fileInfo->getSize()
                );
            }
        }

        sort($entries);

        return substr(sha1(implode("\n", $entries)), 0, 16);
    }

    private function normalizeEntry(string $path, int $mtime, int $size): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path).'|'.$mtime.'|'.$size;
    }
}
