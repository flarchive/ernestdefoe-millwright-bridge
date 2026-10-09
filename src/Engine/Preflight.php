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

    public function __construct(private Site $site, private Compatibility $compat)
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
            $blockers[] = 'Flarum 2 needs PHP '.self::MIN_PHP.' or newer, and this forum runs '.PHP_VERSION
                .'. Ask your host to switch this site to PHP 8.3 or 8.4 (it is usually a setting in the control panel), then check again.';
        }

        foreach (['vendor', 'composer.json', 'composer.lock', 'config.php'] as $path) {
            if (! is_writable($this->site->base.'/'.$path)) {
                $blockers[] = $path.' cannot be changed by the web server, so the upgrade could not swap it. Fix its permissions and check again.';
            }
        }

        // vendor/ is renamed inside the forum's folder, and Flarum 2 is built beside it.
        if (! is_writable($this->site->base)) {
            $blockers[] = 'The forum\'s folder ('.$this->site->base.') cannot be changed by the web server, and the upgrade builds Flarum 2 there, beside vendor/. Fix its permissions and check again.';
        }

        if (! is_writable($this->site->storage)) {
            $blockers[] = 'storage/ is not writable, and the upgrade builds Flarum 2 and keeps its backups there.';
        }

        $free = @disk_free_space($this->site->storage);
        $vendor = $this->size($this->site->base.'/vendor');

        if ($free !== false && $vendor > 0 && $free < $vendor * 3) {
            $blockers[] = 'There is not enough free disk space. The upgrade keeps the current version beside the new one so it can be put back, which needs about '
                .$this->mb($vendor * 3).' free; there is '.$this->mb((int) $free).'.';
        }

        $memory = $this->bytes((string) ini_get('memory_limit'));

        if ($memory !== -1 && $memory < 512 * 1024 * 1024) {
            $notes[] = 'PHP\'s memory limit is '.ini_get('memory_limit').'. Working out the Flarum 2 install can need more; the upgrade raises it where the host allows, and says so if it cannot.';
        }

        if (! function_exists('proc_open')) {
            $notes[] = 'This host does not let PHP start other processes, so the database update will be done through Flarum 2\'s own updater instead of the command line. That is supported; it is just slower.';
        }

        if (! function_exists('curl_init')) {
            $notes[] = 'PHP\'s curl extension is missing, so the site cannot be checked after the upgrade and a failed upgrade would not be put back automatically.';
        }

        if ($this->site->url() === '') {
            $blockers[] = 'config.php has no url, so the forum cannot be checked after the upgrade.';
        }

        $installed = $this->site->installed();
        $checked = $this->compat->check($installed, $this->site->localPackages());
        $extensions = [];

        foreach ($checked as $name => $result) {
            $extensions[$name] = $result + ['installed' => $installed[$name] ?? ''];

            if ($result['status'] === 'local') {
                $blockers[] = $name.' is a local package in this forum\'s own folders, and it requires Flarum 1. Remove it with `composer remove '.$name.'`, or update its composer.json for Flarum 2, then check again.';
            } elseif ($result['status'] === 'php') {
                $blockers[] = $name.'\'s Flarum 2 release ('.$result['version'].') needs PHP '.($result['php'] ?? '').', and this forum runs '.PHP_VERSION
                    .'. Switch the site to a newer PHP in your host\'s control panel, or remove '.$name.', then check again.';
            } elseif ($result['status'] === 'none') {
                $blockers[] = $name.' has no release that works with Flarum 2. Remove it (in Extension Manager or with `composer remove '.$name.'`) or wait for its author, then check again.';
            } elseif ($result['status'] === 'unknown') {
                $notes[] = $name.' is not on Packagist, so it could not be checked here. If it has no Flarum 2 release, building Flarum 2 will stop and say so, before anything changes.';
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
