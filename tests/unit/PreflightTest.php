<?php

namespace ErnestDefoe\MillwrightBridge\Tests\Unit;

use ErnestDefoe\MillwrightBridge\Engine\Preflight;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A vendor/ folder the web server cannot read stopped the walk, and the
 * check then said nothing was locked.
 */
class PreflightTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads every folder');
        }

        $this->dir = sys_get_temp_dir().'/bridge-preflight-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/vendor/acme/open', 0777, true);
        mkdir($this->dir.'/vendor/acme/shut/src', 0777, true);
        file_put_contents($this->dir.'/vendor/acme/open/a.php', str_repeat('x', 100));
        chmod($this->dir.'/vendor/acme/shut', 0300);   // writable, not readable
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            chmod($this->dir.'/vendor/acme/shut', 0700);
            exec('rm -rf '.escapeshellarg($this->dir));
        }
    }

    public function test_an_unreadable_folder_counts_as_locked(): void
    {
        [$count, , $first] = $this->call('notWritable', $this->dir.'/vendor');

        $this->assertSame(1, $count);
        $this->assertSame('vendor/acme/shut', $first);
    }

    public function test_size_counts_what_it_can_read(): void
    {
        $this->assertSame(100, $this->call('size', $this->dir.'/vendor'));
    }

    public function test_a_vendor_it_cannot_open_is_locked_and_logged(): void
    {
        $log = $this->dir.'/error.log';
        $before = ini_set('error_log', $log);

        try {
            [$count, , $first] = $this->call('notWritable', $this->dir.'/missing');
        } finally {
            ini_set('error_log', (string) $before);
        }

        $this->assertSame(1, $count);
        $this->assertSame('vendor/', $first);
        $this->assertStringContainsString('[millwright-bridge] could not check vendor/ is writable', (string) file_get_contents($log));
    }

    private function call(string $method, string $arg): mixed
    {
        $preflight = (new \ReflectionClass(Preflight::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(Preflight::class, $method))->invoke($preflight, $arg);
    }
}
