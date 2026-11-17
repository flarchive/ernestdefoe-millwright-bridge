<?php

namespace ErnestDefoe\MillwrightBridge\Tests\Unit;

use ErnestDefoe\MillwrightBridge\Engine\Journal;
use ErnestDefoe\MillwrightBridge\Engine\Site;
use ErnestDefoe\MillwrightBridge\Engine\Stager;
use ErnestDefoe\MillwrightBridge\Engine\Upgrader;
use PHPUnit\Framework\TestCase;

class StagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/bridge-'.bin2hex(random_bytes(5));
        mkdir($this->dir.'/packages/bridge', 0777, true);
        mkdir($this->dir.'/packages/local', 0777, true);
        file_put_contents($this->dir.'/packages/bridge/composer.json', '{"name":"ernestdefoe/millwright-bridge"}');
        file_put_contents($this->dir.'/packages/local/composer.json', '{"name":"acme/local"}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_the_flarum_2_composer_json(): void
    {
        $stager = new Stager(new Site($this->dir, $this->dir.'/storage', $this->dir.'/public', []), new Journal($this->dir.'/j.json'));

        $next = $stager->composerJson([
            'require' => [
                'flarum/core' => '^1.8', 'flarum/tags' => '^1.8', 'fof/upload' => '^1.9',
                'v17development/flarum-seo' => '^3.0', 'blomstra/fontawesome' => '*',
                'ernestdefoe/millwright-bridge' => '@dev', 'acme/local' => '*', 'monolog/monolog' => '^2.0',
            ],
            'require-dev' => ['phpunit/phpunit' => '*'],
            'repositories' => [['type' => 'path', 'url' => 'packages/bridge'], ['type' => 'path', 'url' => 'packages/local']],
            'config' => ['vendor-dir' => 'lib', 'sort-packages' => true],
        ], [
            'flarum/core' => ['status' => 'ok', 'replacement' => null],
            'flarum/tags' => ['status' => 'ok', 'replacement' => null],
            'fof/upload' => ['status' => 'ok', 'replacement' => null],
            'v17development/flarum-seo' => ['status' => 'replaced', 'replacement' => 'fof/seo'],
            'blomstra/fontawesome' => ['status' => 'retired', 'replacement' => null],
            'ernestdefoe/millwright-bridge' => ['status' => 'retired', 'replacement' => null],
            'acme/local' => ['status' => 'unknown', 'replacement' => null],
        ]);

        $this->assertSame([
            'flarum/core' => '^2.0', 'flarum/tags' => '*', 'fof/upload' => '*', 'acme/local' => '*',
            'monolog/monolog' => '^2.0', 'fof/seo' => '*', 'ernestdefoe/millwright' => '^1.20',
        ], $next['require'], 'libraries keep their constraint; retired and renamed packages go');
        $this->assertSame('beta', $next['minimum-stability']);
        $this->assertTrue($next['prefer-stable']);
        $this->assertArrayNotHasKey('require-dev', $next);
        $this->assertArrayNotHasKey('vendor-dir', $next['config']);
        $this->assertSame([['type' => 'path', 'url' => $this->dir.'/packages/local']], $next['repositories'],
            'a path repository for a package no longer required is dropped; the rest become absolute');
    }

    public function test_extension_ids_follow_flarum(): void
    {
        $this->assertSame('fof-upload', Upgrader::id('fof/upload'));
        $this->assertSame('v17development-seo', Upgrader::id('v17development/flarum-seo'));
        $this->assertSame('acme-thing', Upgrader::id('acme/flarum-ext-thing'));
        $this->assertSame('flarum-lang-persian', Upgrader::id('flarum-lang/persian'));
    }

    /** Flarum-in-a-box computes its url with a function in config.php; only the driver may change. */
    public function test_only_the_driver_line_of_config_php_changes(): void
    {
        $config = "<?php return [\n    'database' => [\n        // Flarum 1.x only knows the 'mysql' driver\n        'driver' => 'mysql',\n    ],\n    'url' => (function () { return 'https://forum.test'; })(),\n];\n";

        $next = Upgrader::mariadbDriver($config);

        $this->assertSame(str_replace("'driver' => 'mysql'", "'driver' => 'mariadb'", $config), $next);
        $this->assertNull(Upgrader::mariadbDriver("<?php return ['database' => ['driver' => 'pgsql']];"));
    }
}
