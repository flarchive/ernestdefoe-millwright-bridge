<?php

/*
 * Millwright Bridge: put this forum back on Flarum 1.
 *
 * Copied here when the upgrade started. Use it only if an upgrade was
 * interrupted and the forum is not working:
 *
 *     php storage/bridge/undo.php
 *
 * No SSH? Point a one-off cron job at it, with your host's PHP 8.3 command.
 * It needs nothing but PHP: no Flarum, no Composer, no autoloader. It restores
 * the database from the backup taken just before the upgrade, moves Flarum 1's
 * files back into vendor/, and restores composer.json, composer.lock and
 * config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);   // never runnable from a browser
    exit;
}

$work = __DIR__;
$storage = dirname($work);
$base = dirname($storage);

foreach (['Site', 'Journal', 'Database', 'Caches', 'Health', 'Swapper', 'Restorer'] as $class) {
    require $work.'/engine/'.$class.'.php';
}

use ErnestDefoe\MillwrightBridge\Engine\Journal;
use ErnestDefoe\MillwrightBridge\Engine\Restorer;
use ErnestDefoe\MillwrightBridge\Engine\Site;

$public = is_dir($base.'/public') ? $base.'/public' : $base;

$journal = new Journal($work.'/journal.json');
$backup = $work.'/'.((string) ($journal->read()['backup'] ?? '') ?: 'backup');

// config.php may already have been rewritten for Flarum 2; the backup is Flarum 1's.
$configFile = is_file($backup.'/config.php') ? $backup.'/config.php' : $base.'/config.php';
$site = new Site($base, $storage, $public, (array) include $configFile);

$state = $journal->read()['state'];

if ($state === 'rolled-back') {
    echo "This forum has already been put back on Flarum 1.\n";
    exit(0);
}

echo "Putting this forum back on Flarum 1...\n";

try {
    foreach ((new Restorer($site, $journal))->run() as $line) {
        echo " - $line\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not finish: '.$e->getMessage()."\n");
    exit(1);
}

echo "Done. If the forum is behind PHP-FPM, restart it (or wait a minute) so it stops running Flarum 2's code.\n";
