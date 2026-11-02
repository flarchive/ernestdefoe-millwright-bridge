<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Where the forum lives and how to reach its database, read once from disk.
 *
 * Plain values only. After the swap, Flarum 1's classes are gone and Flarum
 * 2's must not be loaded into this process, so nothing here may depend on
 * either.
 */
final class Site
{
    public readonly string $work;

    /** @param array<string,mixed> $config config.php as loaded */
    public function __construct(
        public readonly string $base,
        public readonly string $storage,
        public readonly string $public,
        public readonly array $config,
    ) {
        $this->work = $storage.'/bridge';
    }

    public static function at(string $base, string $storage, string $public): self
    {
        $config = is_file($base.'/config.php') ? (array) include $base.'/config.php' : [];

        return new self(rtrim($base, '/'), rtrim($storage, '/'), rtrim($public, '/'), $config);
    }

    /** @return array<string,mixed> */
    public function database(): array
    {
        return (array) ($this->config['database'] ?? []);
    }

    /**
     * Where this upgrade's backup lives. Random, recorded in the journal.
     */
    public function backupDir(Journal $journal): string
    {
        $name = (string) ($journal->read()['backup'] ?? '');

        return $this->work.'/'.($name !== '' ? $name : 'backup');
    }

    /**
     * 🚨 storage/bridge holds a full database dump, a copy of config.php with
     * the database password, and briefly auth.json. Flarum keeps storage/
     * outside the web root, but a 1.x forum installed in the document root
     * relies on .htaccess, which nginx ignores. Deny files for Apache and IIS
     * here, and a random folder name for the backup itself (Upgrader).
     */
    public function protectWork(): void
    {
        @mkdir($this->work, 0775, true);
        @file_put_contents($this->work.'/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($this->work.'/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'."\n");
        @file_put_contents($this->work.'/index.html', '');
    }

    /**
     * Beside vendor/, never under storage/: a rename between the two fails
     * wherever storage/ is a separate disk or Docker volume.
     */
    public function stageDir(): string
    {
        return $this->base.'/.bridge-stage';
    }

    /** Where Flarum 1's vendor/ waits during and after the upgrade, for the undo. */
    public function asideDir(): string
    {
        return $this->base.'/.bridge-flarum1-vendor';
    }

    public function url(): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/');
    }

    public function prefix(): string
    {
        return (string) ($this->database()['prefix'] ?? '');
    }

    public function pdo(): \PDO
    {
        $db = $this->database();
        $host = (string) ($db['host'] ?? 'localhost');
        $dsn = str_starts_with($host, '/')
            ? 'mysql:unix_socket='.$host.';dbname='.($db['database'] ?? '')
            : 'mysql:host='.$host.';port='.(int) ($db['port'] ?? 3306).';dbname='.($db['database'] ?? '');

        $pdo = new \PDO($dsn.';charset=utf8mb4', (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    }

    /** @return array<string,string> package => version, from Composer's own record */
    public function installed(): array
    {
        $raw = json_decode((string) @file_get_contents($this->base.'/vendor/composer/installed.json'), true);
        $packages = isset($raw['packages']) ? $raw['packages'] : (array) $raw;
        $out = [];

        foreach ($packages as $p) {
            if (is_array($p) && ($p['type'] ?? '') === 'flarum-extension') {
                $out[(string) $p['name']] = (string) ($p['version'] ?? '');
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Packages the forum gets from path repositories, read from disk:
     * name => its composer.json. Packagist has never heard of them.
     *
     * @return array<string, array<string,mixed>>
     */
    public function localPackages(): array
    {
        $root = (array) json_decode((string) @file_get_contents($this->base.'/composer.json'), true);
        $out = [];

        foreach ((array) ($root['repositories'] ?? []) as $repo) {
            if (! is_array($repo) || ($repo['type'] ?? '') !== 'path' || ! is_string($repo['url'] ?? null)) {
                continue;
            }

            $pattern = str_starts_with($repo['url'], '/') ? $repo['url'] : $this->base.'/'.$repo['url'];

            foreach (array_filter(glob($pattern) ?: [], 'is_dir') as $dir) {
                $json = (array) json_decode((string) @file_get_contents($dir.'/composer.json'), true);

                if (isset($json['name'])) {
                    $out[(string) $json['name']] = $json;
                }
            }
        }

        return $out;
    }

    public function isMariaDb(): bool
    {
        try {
            return stripos((string) $this->pdo()->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
