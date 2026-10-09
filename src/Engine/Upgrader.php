<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Back up, then swap Flarum 2 in, migrate, finish and check, in ONE call.
 *
 * 🚨 One call because nothing after the swap can come back here. Bridge is a
 * Flarum 1 extension and is not in the Flarum 2 install, so its routes and its
 * classes are gone the moment vendor/ changes. Everything this needs from then
 * on is loaded before the swap (see preload()), and the work afterwards uses
 * only PHP itself: PDO, files, curl and, where allowed, a child process.
 *
 * Any failure after the swap begins puts everything back before returning.
 */
final class Upgrader
{
    public function __construct(private Site $site, private Journal $journal)
    {
    }

    /** @return array{ok: bool, version: ?string, log: list<string>, error: ?string, undone: list<string>} */
    public function run(): array
    {
        $this->preload();
        @set_time_limit(0);
        @ignore_user_abort(true);

        $this->journal->state('upgrading');

        try {
            $this->backup();
        } catch (\Throwable $e) {
            // Nothing has changed yet: say so, and stop.
            $this->journal->state('idle');

            return $this->result(false, null, 'The backup failed, so nothing was changed: '.$e->getMessage(), []);
        }

        try {
            $this->swap();
            $this->enableFor2();
            $this->migrate();
            $version = $this->recordedVersion();

            if ($version === null || ! str_starts_with($version, '2.')) {
                throw new \RuntimeException('the database still records Flarum '.($version ?? 'nothing').' after migrating.');
            }

            $this->caches();
            $this->health();

            $this->journal->state('done');
            $this->journal->log('Flarum '.$version.' is running');

            return $this->result(true, $version, null, []);
        } catch (\Throwable $e) {
            $this->journal->log('Failed: '.$e->getMessage());

            try {
                $undone = (new Restorer($this->site, $this->journal))->run();
            } catch (\Throwable $r) {
                $this->journal->state('needs-undo');

                return $this->result(false, null, 'The upgrade failed ('.$e->getMessage().') and putting it back also failed ('
                    .$r->getMessage().'). Run `php storage/bridge/undo.php`, or run it from your host\'s cron, to finish.', []);
            }

            return $this->result(false, null, 'The upgrade failed, so Flarum 1 has been put back: '.$e->getMessage(), $undone);
        }
    }

    private function backup(): void
    {
        $this->site->protectWork();
        $this->journal->remember('backup', 'backup-'.bin2hex(random_bytes(16)));
        $dir = $this->site->backupDir($this->journal);
        @mkdir($dir, 0700, true);

        $this->journal->log('Backing up the database');
        (new Database($this->site->pdo(), $this->site->prefix()))->dump($dir.'/database.sql.gz');

        foreach (['composer.json', 'composer.lock', 'config.php'] as $file) {
            if (! copy($this->site->base.'/'.$file, $dir.'/'.$file)) {
                throw new \RuntimeException('could not copy '.$file);
            }
        }

        // The undo that needs no framework: these files, loaded by path.
        $engine = $this->site->work.'/engine';
        @mkdir($engine, 0775, true);

        foreach (['Site', 'Journal', 'Database', 'Caches', 'Health', 'Swapper', 'Restorer'] as $class) {
            copy(__DIR__.'/'.$class.'.php', $engine.'/'.$class.'.php');
        }

        copy(dirname(__DIR__, 2).'/resources/undo.php', $this->site->work.'/undo.php');

        $this->journal->done('backup');
        $this->journal->log('Backed up the database, composer.json, composer.lock and config.php');
    }

    private function swap(): void
    {
        $base = $this->site->base;

        $stage = $this->site->stageDir();
        $aside = $this->site->asideDir();

        if (is_dir($aside)) {
            Swapper::emptyDir($aside);   // from an earlier attempt that was put back
            @rmdir($aside);
        }

        $this->journal->intend('vendor');
        $mode = Swapper::swapIn($base.'/vendor', $stage.'/vendor', $aside);
        $this->journal->remember('swap', $mode);
        $this->journal->done('vendor');

        if ($mode === 'contents') {
            $this->journal->log('vendor/ could not be renamed on this host, so its contents were swapped instead');
        }

        $this->journal->intend('files');
        copy($stage.'/composer.json', $base.'/composer.json');
        copy($stage.'/composer.lock', $base.'/composer.lock');

        // Flarum 2 has a driver of its own for MariaDB.
        $config = $this->site->config;

        if (($config['database']['driver'] ?? '') === 'mysql' && $this->site->isMariaDb()) {
            $config['database']['driver'] = 'mariadb';
            file_put_contents($base.'/config.php', "<?php return ".var_export($config, true).";\n");
        }

        $this->journal->done('files');
        Caches::resetCode($this->site);
        $this->journal->log('Swapped Flarum 2 in');
    }

    /**
     * Keep every enabled extension enabled across the upgrade, under its new
     * name where it was replaced, and switch Millwright on in Bridge's place.
     */
    private function enableFor2(): void
    {
        $pdo = $this->site->pdo();
        $table = $this->site->prefix().'settings';
        $raw = $pdo->query('SELECT value FROM `'.$table.'` WHERE `key` = \'extensions_enabled\'')->fetchColumn();
        $enabled = (array) json_decode((string) $raw, true);

        $renamed = [];

        foreach (Compatibility::RETIRED as $old => $new) {
            $renamed[self::id($old)] = $new === null ? null : self::id($new);
        }

        foreach ((array) json_decode((string) @file_get_contents($this->site->work.'/replacements.json'), true) as $old => $new) {
            $renamed[self::id((string) $old)] = self::id((string) $new);
        }

        $next = [];

        foreach ($enabled as $id) {
            $id = (string) $id;

            if (array_key_exists($id, $renamed)) {
                if ($renamed[$id] !== null) {
                    $next[] = $renamed[$id];
                }
            } else {
                $next[] = $id;
            }
        }

        $next[] = 'ernestdefoe-millwright';
        $next = array_values(array_unique($next));

        $this->journal->intend('migrate');
        $pdo->prepare('UPDATE `'.$table.'` SET value = ? WHERE `key` = \'extensions_enabled\'')->execute([json_encode($next)]);
    }

    private function migrate(): void
    {
        $this->journal->log('Updating the database');

        $php = Php::cli();

        if ($php !== null) {
            $result = Php::run([$php, 'flarum', 'migrate'], $this->site->base);

            if ($result['code'] !== 0) {
                throw new \RuntimeException('php flarum migrate failed: '.mb_substr(trim($result['output']), -600));
            }
        } else {
            (new Updater($this->site))->migrate();
        }

        $this->journal->done('migrate');
    }

    private function caches(): void
    {
        $php = Php::cli();

        Caches::clear($this->site);

        // Flarum 2's own clear also rebuilds the assets, so the first visitor
        // does not pay for it. Best effort: they rebuild on demand anyway.
        if ($php !== null) {
            Php::run([$php, 'flarum', 'cache:clear'], $this->site->base);
        }

        $this->journal->log('Caches cleared');
    }

    private function health(): void
    {
        $health = new Health($this->site->url());
        $deadline = time() + 45;

        do {
            $result = $health->check();

            if ($result['ok']) {
                $this->journal->log('The forum is answering');

                return;
            }

            // A 503 while php-fpm workers drop the old code, or the first
            // request compiling the assets, is worth waiting for.
            sleep(3);
        } while (time() < $deadline);

        throw new \RuntimeException('the forum did not answer after the upgrade ('.$result['why'].').');
    }

    private function recordedVersion(): ?string
    {
        $v = $this->site->pdo()->query('SELECT value FROM `'.$this->site->prefix().'settings` WHERE `key` = \'version\'')->fetchColumn();

        return is_string($v) ? $v : null;
    }

    /**
     * 🚨 Every class needed after the swap, loaded while Flarum 1's
     * autoloader can still find it. Afterwards the autoloader would look in
     * Flarum 2's vendor/, where Bridge does not exist.
     */
    private function preload(): void
    {
        foreach ([Restorer::class, Swapper::class, Database::class, Caches::class, Php::class, Updater::class, Health::class, Compatibility::class] as $class) {
            class_exists($class);
        }
    }

    public static function id(string $package): string
    {
        [$vendor, $name] = array_pad(explode('/', $package, 2), 2, $package);

        return $vendor.'-'.str_replace(['flarum-ext-', 'flarum-'], '', $name);
    }

    /** @return array{ok: bool, version: ?string, log: list<string>, error: ?string, undone: list<string>} */
    private function result(bool $ok, ?string $version, ?string $error, array $undone): array
    {
        return ['ok' => $ok, 'version' => $version, 'log' => $this->journal->read()['log'], 'error' => $error, 'undone' => $undone];
    }
}
