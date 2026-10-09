<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

use Composer\Semver\Semver;

/**
 * Whether each installed extension has a release that works with Flarum 2.
 *
 * One Packagist request per extension, nothing else. A package Packagist does
 * not know (private, premium, a path checkout) is reported as "unknown" rather
 * than guessed at: it may well have a Flarum 2 release somewhere this cannot
 * see, and the staging step finds out for certain.
 */
class Compatibility
{
    /** Retired in Flarum 2: their job moved into core or another package. */
    public const RETIRED = [
        'blomstra/database-queue' => null,
        'blomstra/fontawesome' => null,
        'blomstra/flarum-redis' => 'fof/redis',
        'blomstra/horizon' => 'fof/horizon',
        'flarum/package-manager' => 'flarum/extension-manager',
        'ernestdefoe/millwright-bridge' => null,
        // Only ever needed before the upgrade, so it has no Flarum 2 release (ClaudiusH).
        'fof/upgrade-advisor' => null,
    ];

    public const TARGET = '2.0.0';

    /** @var callable(string): ?array */
    private $fetch;

    /**
     * @param ?string $cacheDir where answers are kept for an hour, so opening
     *        the admin page again does not ask Packagist about every
     *        extension again
     */
    public function __construct(?callable $fetch = null, private ?string $cacheDir = null)
    {
        $this->fetch = $fetch ?? fn (string $name) => $this->cached($name);
    }

    /** @return list<array<string,mixed>>|null */
    private function cached(string $name): ?array
    {
        $file = $this->cacheDir === null ? null : $this->cacheDir.'/'.sha1($name).'.json';

        if ($file !== null && is_file($file) && time() - (int) filemtime($file) < 3600) {
            $hit = json_decode((string) file_get_contents($file), true);

            if (is_array($hit)) {
                return $hit['versions'];
            }
        }

        $versions = $this->fromPackagist($name);

        // Only answers are kept: an outage should not stick for an hour.
        if ($file !== null && $versions !== null) {
            @mkdir($this->cacheDir, 0775, true);
            @file_put_contents($file, json_encode(['versions' => $versions]));
        }

        return $versions;
    }

    /**
     * @param array<string,string> $installed package => installed version
     * @return array<string, array{status: string, version: ?string, replacement: ?string}>
     *   status: ok | none | replaced | retired | unknown
     */
    public function check(array $installed): array
    {
        $out = [];

        foreach ($installed as $name => $version) {
            if (str_starts_with($name, 'flarum/') && ! array_key_exists($name, self::RETIRED)) {
                // Bundled extensions move with core.
                $out[$name] = ['status' => 'ok', 'version' => null, 'replacement' => null];
                continue;
            }

            if (array_key_exists($name, self::RETIRED)) {
                $out[$name] = ['status' => 'retired', 'version' => null, 'replacement' => self::RETIRED[$name]];
                continue;
            }

            $versions = ($this->fetch)($name);

            if ($versions === null) {
                $out[$name] = ['status' => 'unknown', 'version' => null, 'replacement' => null];
                continue;
            }

            $result = self::judge($name, $versions);

            // A rename names its new package only in its own repository.
            if ($result['status'] === 'replaced' && $result['replacement'] === null) {
                $result['replacement'] = $this->realName((array) ($versions[0]['source'] ?? []));
            }

            /*
             * 🚨 A takeover the old package never mentions. FoF continued
             * afrux/forum-widgets-core as fof/forum-widgets-core, whose
             * composer.json replaces the old name, but the old name has no
             * Flarum 2 release and no pointer to the new one, so the check told
             * people to remove an extension whose successor was right there
             * (ClaudiusH). Only for extensions with no Flarum 2 release: one
             * search for its short name, keeping only results that replace it.
             */
            if ($result['status'] === 'none' && ($successor = $this->successor($name)) !== null) {
                $result = ['status' => 'replaced', 'version' => null, 'replacement' => $successor];
            }

            $out[$name] = $result;
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $versions expanded p2 entries, newest first
     * @param string $php the PHP that will run Flarum 2: this one
     * @return array{status: string, version: ?string, replacement: ?string, php?: string}
     */
    public static function judge(string $name, array $versions, string $php = PHP_VERSION): array
    {
        /*
         * 🚨 A package whose newest release replaces its own name is a rename.
         * Packagist serves v17development/flarum-seo from the fof/seo
         * repository, whose composer.json replaces the old name, so requiring
         * the old name installs nothing under it and the extension quietly
         * vanishes from the forum. The new name is in that repository.
         */
        if (isset(array_change_key_case((array) ($versions[0]['replace'] ?? []))[strtolower($name)])) {
            return ['status' => 'replaced', 'version' => null, 'replacement' => null];
        }

        $abandoned = $versions[0]['abandoned'] ?? false;
        $needsPhp = null;

        foreach ($versions as $v) {
            $version = (string) ($v['version'] ?? '');
            $core = $v['require']['flarum/core'] ?? null;

            if ($version === '' || str_starts_with($version, 'dev-') || str_contains($version, '-dev') || ! is_string($core)) {
                continue;
            }

            try {
                if (! Semver::satisfies(self::TARGET, $core)) {
                    continue;
                }

                /*
                 * 🚨 Its PHP requirement too. michaelbelgium/flarum-profile-views
                 * has a Flarum 2 release, but only for PHP 8.4. On a forum
                 * running 8.3 the check said "ready" and Composer then refused
                 * to build, with the reason buried under every older version
                 * it had tried (ClaudiusH).
                 */
                $phpConstraint = $v['require']['php'] ?? null;

                if (is_string($phpConstraint) && ! Semver::satisfies(self::phpVersion($php), $phpConstraint)) {
                    $needsPhp ??= ['version' => $version, 'php' => $phpConstraint];
                    continue;
                }

                return ['status' => 'ok', 'version' => $version, 'replacement' => null];
            } catch (\Throwable $e) {
                continue;   // a constraint Composer cannot parse is not a promise
            }
        }

        if ($needsPhp !== null) {
            return ['status' => 'php', 'version' => $needsPhp['version'], 'replacement' => null, 'php' => $needsPhp['php']];
        }

        return [
            'status' => is_string($abandoned) && $abandoned !== '' ? 'replaced' : 'none',
            'version' => null,
            'replacement' => is_string($abandoned) && $abandoned !== '' ? $abandoned : null,
        ];
    }

    /** A Flarum 2 package that declares it replaces $name, found by Packagist search. */
    private function successor(string $name): ?string
    {
        $short = substr($name, (int) strpos($name, '/') + 1);
        $body = @file_get_contents('https://packagist.org/search.json?type=flarum-extension&per_page=15&q='.rawurlencode($short), false, stream_context_create([
            'http' => ['timeout' => 15, 'header' => "User-Agent: Millwright Bridge\r\n"],
        ]));

        foreach ((array) (is_string($body) ? json_decode($body, true)['results'] ?? [] : []) as $hit) {
            $candidate = (string) ($hit['name'] ?? '');

            if ($candidate === '' || $candidate === $name) {
                continue;
            }

            $versions = ($this->fetch)($candidate);

            if ($versions === null || ! isset(array_change_key_case((array) ($versions[0]['replace'] ?? []))[strtolower($name)])) {
                continue;
            }

            if (self::judge($candidate, $versions)['status'] === 'ok') {
                return $candidate;
            }
        }

        return null;
    }

    /** "8.3.33-1+ubuntu" is 8.3.33 to Composer's semver. */
    private static function phpVersion(string $php): string
    {
        return preg_match('/^\d+\.\d+\.\d+/', $php, $m) ? $m[0] : $php;
    }

    /** The package a GitHub repository actually publishes, from its composer.json. */
    public function realName(array $source): ?string
    {
        if (! preg_match('#github\.com[/:]([\w.-]+)/([\w.-]+?)(?:\.git)?$#', (string) ($source['url'] ?? ''), $m)) {
            return null;
        }

        $ref = (string) ($source['reference'] ?? 'HEAD');
        $body = @file_get_contents('https://raw.githubusercontent.com/'.$m[1].'/'.$m[2].'/'.rawurlencode($ref).'/composer.json', false, stream_context_create([
            'http' => ['timeout' => 15, 'header' => "User-Agent: Millwright Bridge\r\n"],
        ]));
        $name = is_string($body) ? (json_decode($body, true)['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? strtolower($name) : null;
    }

    /** @return list<array<string,mixed>>|null */
    public function fromPackagist(string $name): ?array
    {
        $body = @file_get_contents('https://repo.packagist.org/p2/'.$name.'.json', false, stream_context_create([
            'http' => ['timeout' => 15, 'header' => "User-Agent: Millwright Bridge\r\n"],
        ]));

        $data = is_string($body) ? json_decode($body, true) : null;
        $entries = $data['packages'][$name] ?? null;

        return is_array($entries) ? self::expand($entries) : null;
    }

    /**
     * Packagist p2 is minified: each entry repeats only what changed since the
     * one before it, and `__unset` removes a key. Expanded as Composer does.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    public static function expand(array $entries): array
    {
        $out = [];
        $current = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry as $key => $value) {
                if ($value === '__unset') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            $out[] = $current;
        }

        return $out;
    }
}
