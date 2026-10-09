<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Put the forum back on Flarum 1, from whatever point the upgrade reached.
 *
 * 🚨 Depends on nothing but PHP, Site, Journal and Database. It runs inside a
 * request whose vendor/ may already be Flarum 2's, and from undo.php, which
 * loads these files by path with no autoloader at all.
 */
final class Restorer
{
    public function __construct(private Site $site, private Journal $journal)
    {
    }

    /** @return list<string> what was put back */
    public function run(): array
    {
        $done = [];
        $w = $this->site->work;
        $base = $this->site->base;
        $backup = $this->site->backupDir($this->journal);

        if ($this->journal->has('migrate') && is_file($backup.'/database.sql.gz')) {
            (new Database($this->site->pdo(), $this->site->prefix()))->restore($backup.'/database.sql.gz');
            $done[] = 'database restored from the backup';
        }

        if ($this->journal->has('vendor') && is_dir($w.'/vendor.v1')) {
            if (is_dir($base.'/vendor')) {
                @rename($base.'/vendor', $w.'/vendor.v2-'.date('YmdHis'));
            }

            if (! @rename($w.'/vendor.v1', $base.'/vendor')) {
                throw new \RuntimeException('Flarum 1\'s files could not be moved back into vendor/. They are in '.$w.'/vendor.v1.');
            }

            $done[] = 'Flarum 1\'s files moved back';
        }

        foreach (['composer.json', 'composer.lock', 'config.php'] as $file) {
            if (is_file($backup.'/'.$file)) {
                copy($backup.'/'.$file, $base.'/'.$file);
            }
        }

        $done[] = 'composer.json, composer.lock and config.php restored';

        Caches::clear($this->site);
        $done[] = 'caches cleared';

        $this->journal->state('rolled-back');
        $this->journal->log('Put back: '.implode(', ', $done));

        return $done;
    }
}
