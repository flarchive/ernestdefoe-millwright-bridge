<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Empty everything compiled by one version so the other rebuilds it.
 *
 * Without a framework: this runs after the swap, when neither Flarum 1's nor
 * Flarum 2's cache:clear can be loaded into this process.
 */
final class Caches
{
    public static function clear(Site $site): void
    {
        foreach (['cache', 'formatter', 'locale', 'views', 'less'] as $dir) {
            self::emptyDir($site->storage.'/'.$dir);
        }

        // Compiled bundles and their manifest. Avatars, uploads and extension
        // files live in subfolders and are left alone.
        // 🚨 No GLOB_BRACE: musl (Alpine, so most Docker images) does not have it,
        // and the undo died on it (ClaudiusH, 2026-10-09).
        foreach (['js', 'css', 'map'] as $ext) {
            foreach (glob($site->public.'/assets/*.'.$ext) ?: [] as $file) {
                @unlink($file);
            }
        }

        @unlink($site->public.'/assets/rev-manifest.json');

        self::resetCode($site);
    }

    /**
     * 🚨 Make the web server drop the compiled code of the version that just
     * left. Needed straight after the swap, not only at the end: the next
     * request (Flarum 2's updater, asked to migrate) otherwise lands on a
     * php-fpm worker still running Flarum 1's cached classes against Flarum
     * 2's files, and answers 500.
     *
     * Under the web server the cache is shared by the pool, so resetting it
     * here is enough. From the command line it cannot be reached; a flag is
     * left instead, taken up by the first stale worker (which still runs
     * Bridge's provider from its cache) and by Millwright's, then the site is
     * asked for a page so that happens now.
     */
    public static function resetCode(Site $site): void
    {
        if (PHP_SAPI !== 'cli') {
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return;
        }

        foreach ([$site->work, $site->storage.'/millwright'] as $dir) {
            @mkdir($dir, 0775, true);
            @touch($dir.'/opcache-reset');
        }

        if (function_exists('curl_init') && $site->url() !== '') {
            for ($i = 0; $i < 6 && is_file($site->work.'/opcache-reset'); $i++) {
                (new Health($site->url()))->check();
                usleep(300_000);
            }
        }
    }

    private static function emptyDir(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            if (basename($f->getPathname()) === '.gitignore') {
                continue;
            }

            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }
}
