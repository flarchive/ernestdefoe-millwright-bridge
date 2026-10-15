<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Everything worth knowing before anything is touched.
 *
 * Each finding is a blocker or a note. A blocker stops the upgrade before the
 * staging step; a note is something the admin should read but that does not
 * stop it. Nothing here changes the forum.
 */
final class Preflight
{
    public const MIN_PHP = '8.3.0';

    /** @param \Closure(string, array<string, mixed>=): string $t a message from the locale (see Bridge::t()) */
    public function __construct(private Site $site, private Compatibility $compat, private \Closure $t)
    {
    }

    /**
     * @return array{ok: bool, php: string, blockers: list<string>, notes: list<string>,
     *   extensions: array<string, array{status: string, version: ?string, replacement: ?string, installed: string}>}
     */
    public function run(): array
    {
        $blockers = [];
        $notes = [];

        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            $blockers[] = ($this->t)('php_too_old', ['min' => self::MIN_PHP, 'version' => PHP_VERSION]);
        }

        foreach (['vendor', 'composer.json', 'composer.lock', 'config.php'] as $path) {
            if (! is_writable($this->site->base.'/'.$path)) {
                $blockers[] = ($this->t)('path_not_writable', ['path' => $path]);
            }
        }

        /*
         * 🚨 Every folder in vendor/, not just vendor/ itself. Running composer
         * as root (docker exec defaults to root) leaves packages the web
         * server cannot empty or write into, and on a host where vendor/ must
         * be swapped by its contents the upgrade then failed halfway
         * (ClaudiusH, Flarum-in-a-box, 2026-10-09).
         */
        [$locked, $owner, $first] = $this->notWritable($this->site->base.'/vendor');

        if ($locked > 0) {
            $blockers[] = ($this->t)($owner !== null ? 'vendor_locked_owner' : 'vendor_locked', [
                'count' => $locked, 'owner' => (string) $owner, 'first' => (string) $first, 'user' => $this->webUser(),
            ]);
        }

        // vendor/ is renamed inside the forum's folder, and Flarum 2 is built beside it.
        if (! is_writable($this->site->base)) {
            $blockers[] = ($this->t)('base_not_writable', ['path' => $this->site->base]);
        }

        if (! is_writable($this->site->storage)) {
            $blockers[] = ($this->t)('storage_not_writable');
        }

        $free = @disk_free_space($this->site->storage);
        $vendor = $this->size($this->site->base.'/vendor');

        if ($free !== false && $vendor > 0 && $free < $vendor * 3) {
            $blockers[] = ($this->t)('disk_space', ['needed' => $this->mb($vendor * 3), 'free' => $this->mb((int) $free)]);
        }

        $memory = $this->bytes((string) ini_get('memory_limit'));

        if ($memory !== -1 && $memory < 512 * 1024 * 1024) {
            $notes[] = ($this->t)('memory_note', ['limit' => (string) ini_get('memory_limit')]);
        }

        if (! function_exists('proc_open')) {
            $notes[] = ($this->t)('proc_open_note');
        }

        if (! function_exists('curl_init')) {
            $notes[] = ($this->t)('curl_note');
        }

        if ($this->site->url() === '') {
            $blockers[] = ($this->t)('no_url');
        }

        $installed = $this->site->installed();
        $checked = $this->compat->check($installed, $this->site->localPackages());
        $extensions = [];

        foreach ($checked as $name => $result) {
            $extensions[$name] = $result + ['installed' => $installed[$name] ?? ''];

            if ($result['status'] === 'local') {
                $blockers[] = ($this->t)('ext_local', ['name' => $name]);
            } elseif ($result['status'] === 'php') {
                $blockers[] = ($this->t)('ext_php', ['name' => $name, 'version' => (string) $result['version'], 'php' => (string) ($result['php'] ?? ''), 'current' => PHP_VERSION]);
            } elseif ($result['status'] === 'none') {
                $blockers[] = ($this->t)('ext_none', ['name' => $name]);
            } elseif ($result['status'] === 'unknown') {
                $notes[] = ($this->t)('ext_unknown', ['name' => $name]);
            }
        }

        return [
            'ok' => $blockers === [],
            'php' => PHP_VERSION,
            'blockers' => $blockers,
            'notes' => $notes,
            'extensions' => $extensions,
        ];
    }

    /** @return array{0: int, 1: ?string, 2: ?string} how many folders, whose, and the first one */
    private function notWritable(string $vendor): array
    {
        $count = 0;
        $owner = null;
        $first = null;

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($vendor, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($it as $f) {
                if ($f->isDir() && ! $f->isLink() && ! $f->isWritable()) {
                    $count++;
                    $first ??= 'vendor/'.substr($f->getPathname(), strlen($vendor) + 1);
                    $owner ??= function_exists('posix_getpwuid') ? (posix_getpwuid($f->getOwner())['name'] ?? null) : null;
                }
            }
        } catch (\Throwable $e) {
            return [0, null, null];
        }

        return [$count, $owner, $first];
    }

    private function webUser(): string
    {
        $me = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return $me !== null ? $me.':'.$me : 'www-data:www-data';
    }

    private function size(string $dir): int
    {
        $total = 0;

        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $total += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return $total;
    }

    private function mb(int $bytes): string
    {
        return round($bytes / 1048576).' MB';
    }

    private function bytes(string $ini): int
    {
        $ini = trim($ini);

        if ($ini === '' || $ini === '-1') {
            return -1;
        }

        $n = (int) $ini;

        return match (strtolower(substr($ini, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
