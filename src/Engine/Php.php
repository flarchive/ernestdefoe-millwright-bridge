<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * A command-line PHP to run `php flarum` with, when the host allows one.
 *
 * Under the web server PHP_BINARY is php-fpm, which cannot run a script, so
 * the usual places are tried and each candidate must say it is the CLI and at
 * least 8.3, the PHP Flarum 2 needs. Null means: use Flarum 2's own updater.
 */
final class Php
{
    /**
     * 🚨 Its own copy of the minimum, not Preflight::MIN_PHP. This runs after the
     * swap, when only preloaded classes exist, and an upgrade started from the
     * web never loads Preflight: the reference killed the migrate step on
     * Flarum-in-a-box (2026-10-09).
     */
    private const MIN = '8.3.0';

    private static ?string $found = null;
    private static bool $looked = false;

    public static function cli(): ?string
    {
        if (self::$looked) {
            return self::$found;
        }

        self::$looked = true;

        if (! function_exists('proc_open')) {
            return null;
        }

        $candidates = PHP_SAPI === 'cli' ? [PHP_BINARY] : [];
        $v = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        foreach ([PHP_BINDIR.'/php', PHP_BINDIR.'/php'.$v, '/usr/local/bin/php', '/usr/bin/php', '/usr/bin/php'.$v, '/usr/local/bin/php'.$v] as $path) {
            $candidates[] = $path;
        }

        foreach (array_unique($candidates) as $path) {
            if (! @is_executable($path)) {
                continue;
            }

            $probe = self::run([$path, '-r', 'echo PHP_SAPI, " ", PHP_VERSION;'], sys_get_temp_dir());

            if ($probe['code'] === 0 && preg_match('/^cli (\S+)/', trim($probe['output']), $m) && version_compare($m[1], self::MIN, '>=')) {
                return self::$found = $path;
            }
        }

        return null;
    }

    /** @param list<string> $command @return array{code: int, output: string} */
    public static function run(array $command, string $cwd): array
    {
        $proc = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);

        if (! is_resource($proc)) {
            return ['code' => -1, 'output' => 'could not start '.$command[0]];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($proc), 'output' => $output];
    }
}
