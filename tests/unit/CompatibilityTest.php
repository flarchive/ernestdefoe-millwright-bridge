<?php

namespace ErnestDefoe\MillwrightBridge\Tests\Unit;

use ErnestDefoe\MillwrightBridge\Engine\Compatibility;
use PHPUnit\Framework\TestCase;

class CompatibilityTest extends TestCase
{
    public function test_a_release_requiring_flarum_2_makes_it_ready(): void
    {
        $r = Compatibility::judge('fof/upload', [
            ['version' => '2.0.1', 'require' => ['flarum/core' => '^2.0']],
            ['version' => '1.9.1', 'require' => ['flarum/core' => '^1.8']],
        ]);

        $this->assertSame(['status' => 'ok', 'version' => '2.0.1', 'replacement' => null], $r);
    }

    public function test_a_flarum_1_only_extension_is_reported_as_having_no_release(): void
    {
        $r = Compatibility::judge('acme/old', [['version' => '1.2.0', 'require' => ['flarum/core' => '^1.0']]]);

        $this->assertSame('none', $r['status']);
    }

    public function test_dev_branches_do_not_count(): void
    {
        $r = Compatibility::judge('acme/x', [['version' => 'dev-main', 'require' => ['flarum/core' => '^2.0']]]);

        $this->assertSame('none', $r['status']);
    }

    /** v17development/flarum-seo is served from fof/seo, whose newest release replaces the old name. */
    public function test_a_release_that_replaces_its_own_name_is_a_rename(): void
    {
        $r = Compatibility::judge('v17development/flarum-seo', [
            ['name' => 'v17development/flarum-seo', 'version' => '4.0.0-beta.11', 'replace' => ['v17development/flarum-seo' => '*'], 'require' => ['flarum/core' => '^2.0']],
        ]);

        $this->assertSame('replaced', $r['status']);
    }

    public function test_abandoned_with_a_replacement_is_a_rename(): void
    {
        $r = Compatibility::judge('acme/old', [['version' => '1.0.0', 'abandoned' => 'acme/new', 'require' => ['flarum/core' => '^1.0']]]);

        $this->assertSame(['status' => 'replaced', 'version' => null, 'replacement' => 'acme/new'], $r);
    }

    public function test_bundled_and_retired_extensions_need_no_lookup(): void
    {
        $asked = [];
        $compat = new Compatibility(function (string $name) use (&$asked) {
            $asked[] = $name;

            return null;
        });

        $out = $compat->check(['flarum/tags' => 'v1.8.8', 'blomstra/flarum-redis' => '1.0.0', 'fof/upgrade-advisor' => '1.0.0', 'acme/private' => '1.0.0']);

        $this->assertSame('ok', $out['flarum/tags']['status']);
        $this->assertSame(['status' => 'retired', 'version' => null, 'replacement' => 'fof/redis'], $out['blomstra/flarum-redis']);
        $this->assertSame('retired', $out['fof/upgrade-advisor']['status'], 'only needed before the upgrade; never a blocker');
        $this->assertSame('unknown', $out['acme/private']['status']);
        $this->assertSame(['acme/private'], $asked);
    }

    public function test_packagist_p2_is_expanded(): void
    {
        $out = Compatibility::expand([
            ['version' => '2.0.0', 'require' => ['flarum/core' => '^2.0'], 'source' => ['url' => 'x']],
            ['version' => '1.0.0', 'require' => ['flarum/core' => '^1.0']],
            ['version' => '0.9.0', 'source' => '__unset'],
        ]);

        $this->assertSame('x', $out[1]['source']['url']);
        $this->assertSame(['flarum/core' => '^1.0'], $out[2]['require']);
        $this->assertArrayNotHasKey('source', $out[2]);
    }
}
