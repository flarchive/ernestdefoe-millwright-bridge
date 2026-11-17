<?php

namespace ErnestDefoe\MillwrightBridge;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Paths;

class BridgeServiceProvider extends AbstractServiceProvider
{
    /**
     * 🚨 After a swap run from the command line, php-fpm workers can keep
     * serving Flarum 1's compiled code against a database already migrated to
     * Flarum 2. Those stale workers still run this provider, from their cache,
     * so a flag left by the upgrade makes the first of them reset the cache
     * the whole pool shares. One stat per request otherwise.
     */
    public function boot(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $flag = $this->container->make(Paths::class)->storage.'/bridge/opcache-reset';

        if (is_file($flag) && @rename($flag, $flag.'.'.getmypid())) {
            @unlink($flag.'.'.getmypid());

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }
    }
}
