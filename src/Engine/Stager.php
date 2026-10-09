<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

use Composer\Console\Application as ComposerApp;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Build the complete Flarum 2 install in storage/bridge/stage, beside the
 * live forum, which keeps running untouched the whole time.
 *
 * Composer runs in this process, as a library: shared hosts often forbid
 * starting processes, and the whole point is that those forums can upgrade
 * too. If it cannot resolve, the error is the answer, and nothing has changed.
 */
final class Stager
{
    public function __construct(private Site $site, private Journal $journal)
    {
    }

    public function stageDir(): string
    {
        return $this->site->work.'/stage';
    }

    /**
     * The Flarum 2 composer.json, from the forum's own.
     *
     * @param array<string, array{status: string, replacement: ?string}> $extensions from Compatibility
     * @return array<string,mixed>
     */
    public function composerJson(array $current, array $extensions): array
    {
        $require = (array) ($current['require'] ?? []);

        foreach ($require as $name => $constraint) {
            $result = $extensions[$name] ?? null;

            if ($name === 'flarum/core') {
                $require[$name] = '^2.0';
            } elseif ($result !== null && in_array($result['status'], ['retired', 'replaced'], true)) {
                unset($require[$name]);

                if ($result['replacement'] !== null) {
                    $require[$result['replacement']] = '*';
                }
            } elseif ($result !== null) {
                // Every extension, bundled or not, to whatever release works with Flarum 2.
                $require[$name] = '*';
            }
        }

        unset($require['ernestdefoe/millwright-bridge']);

        // Millwright takes over: the admin lands on the updater built for Flarum 2.
        $require['ernestdefoe/millwright'] = '^1.20';

        $next = $current;
        $next['require'] = $require;
        $next['minimum-stability'] = 'beta';
        $next['prefer-stable'] = true;
        unset($next['require-dev'], $next['config']['vendor-dir']);

        foreach ((array) ($next['repositories'] ?? []) as $i => $repo) {
            if (! is_array($repo) || ($repo['type'] ?? '') !== 'path' || ! isset($repo['url'])) {
                continue;
            }

            // Path repositories are relative to composer.json, which the stage is not beside.
            $url = str_starts_with((string) $repo['url'], '/') ? (string) $repo['url'] : $this->site->base.'/'.$repo['url'];
            $name = json_decode((string) @file_get_contents($url.'/composer.json'), true)['name'] ?? null;

            /*
             * A path repository for a package Flarum 2 no longer requires is
             * dropped, not just left unused: Composer asks git about every path
             * repository it scans, and a host without proc_open cannot run it.
             */
            if (is_string($name) && ! isset($require[$name])) {
                unset($next['repositories'][$i]);
            } else {
                $next['repositories'][$i]['url'] = $url;
            }
        }

        // A list stays a list once a member is dropped; named repositories keep their names.
        if (isset($next['repositories']) && array_is_list((array) ($current['repositories'] ?? []))) {
            $next['repositories'] = array_values((array) $next['repositories']);
        }

        return $next;
    }

    /**
     * @param array<string, array{status: string, replacement: ?string}> $extensions
     * @return array{ok: bool, output: string, core: ?string}
     */
    public function stage(array $extensions): array
    {
        $this->site->protectWork();
        $dir = $this->stageDir();
        $this->wipe($dir);
        @mkdir($dir, 0775, true);

        $current = (array) json_decode((string) file_get_contents($this->site->base.'/composer.json'), true);
        file_put_contents($dir.'/composer.json', json_encode($this->composerJson($current, $extensions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // The forum's own credentials, so private and premium extensions resolve.
        if (is_file($this->site->base.'/auth.json')) {
            copy($this->site->base.'/auth.json', $dir.'/auth.json');
        }

        $this->journal->log('Building Flarum 2 beside the forum');

        $result = $this->composer(['command' => 'update', '--working-dir' => $dir, '--prefer-dist' => true, '--no-plugins' => true,
            '--no-scripts' => true, '--no-dev' => true, '--optimize-autoloader' => true, '--with-all-dependencies' => true, '--no-interaction' => true]);

        // The credentials were only needed to resolve.
        @unlink($dir.'/auth.json');

        $core = $this->coreVersion($dir.'/vendor');

        if ($result['code'] !== 0 || $core === null || ! str_starts_with(ltrim($core, 'v'), '2.')) {
            return ['ok' => false, 'output' => $result['output'], 'core' => $core];
        }

        $this->journal->log('Flarum '.$core.' is built and ready to swap in');

        return ['ok' => true, 'output' => $result['output'], 'core' => $core];
    }

    public function staged(): bool
    {
        $core = $this->coreVersion($this->stageDir().'/vendor');

        return $core !== null && str_starts_with(ltrim($core, 'v'), '2.');
    }

    /** @return array{code: int, output: string} */
    private function composer(array $args): array
    {
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);

        $home = $this->site->work.'/composer-home';
        @mkdir($home, 0775, true);

        /*
         * 🚨 The variables that stop Composer starting programs, as Millwright
         * learned them on shared hosting: without COMPOSER_ROOT_VERSION it asks
         * git what version the forum is, and without COLUMNS/LINES Symfony
         * measures the terminal with stty. Either is proc_open, which these
         * hosts forbid. $_SERVER too, because Composer reads it first and some
         * hosts disable putenv.
         */
        $env = ['COMPOSER_HOME' => $home, 'COMPOSER_MEMORY_LIMIT' => '-1', 'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1', 'COMPOSER_ALLOW_SUPERUSER' => '1', 'COLUMNS' => '120', 'LINES' => '50'];

        if (getenv('COMPOSER_ROOT_VERSION') === false && ! isset($_SERVER['COMPOSER_ROOT_VERSION'])) {
            $env['COMPOSER_ROOT_VERSION'] = '1.0.0';
        }

        if ((getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) === '') {
            $env['HOME'] = $this->site->base;
        }

        foreach ($env as $name => $value) {
            if (function_exists('putenv')) {
                @putenv($name.'='.$value);
            }

            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        $app = new ComposerApp();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);

        $output = new BufferedOutput();

        try {
            $code = $app->run(new ArrayInput($args), $output);
        } catch (\Throwable $e) {
            $code = 1;
            $output->writeln(str_contains($e->getMessage(), 'proc_open')
                ? 'Composer needed to run git (for a Git or path repository), and this host does not let PHP start programs. '
                    .'Install that package from Packagist or another Composer repository instead, or run the upgrade from the command line.'
                : $e->getMessage());
        }

        return ['code' => $code, 'output' => $output->fetch()];
    }

    private function coreVersion(string $vendor): ?string
    {
        $raw = json_decode((string) @file_get_contents($vendor.'/composer/installed.json'), true);

        foreach ((array) ($raw['packages'] ?? $raw ?? []) as $p) {
            if (is_array($p) && ($p['name'] ?? '') === 'flarum/core') {
                return (string) ($p['version'] ?? '');
            }
        }

        return null;
    }

    public function wipe(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }

        // 🚨 Never follows a symlink: a vendor tree is full of them, and a
        // recursive delete that follows one empties whatever it points at.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
}
