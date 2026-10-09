<?php

namespace ErnestDefoe\MillwrightBridge;

use ErnestDefoe\MillwrightBridge\Engine\Compatibility;
use ErnestDefoe\MillwrightBridge\Engine\Journal;
use ErnestDefoe\MillwrightBridge\Engine\Preflight;
use ErnestDefoe\MillwrightBridge\Engine\Site;
use ErnestDefoe\MillwrightBridge\Engine\Stager;
use ErnestDefoe\MillwrightBridge\Engine\Upgrader;
use Flarum\Foundation\Paths;

/** The three things the admin page and the command can ask for. */
final class Bridge
{
    private Site $site;
    private Journal $journal;

    public function __construct(Paths $paths)
    {
        $this->site = Site::at($paths->base, $paths->storage, $paths->public);
        $this->journal = new Journal($this->site->work.'/journal.json');
    }

    public function check(): array
    {
        $this->site->protectWork();
        $report = (new Preflight($this->site, new Compatibility(null, $this->site->work.'/packagist')))->run();
        $report['staged'] = (new Stager($this->site, $this->journal))->staged();
        $report['state'] = $this->journal->read()['state'];

        return $report;
    }

    public function stage(): array
    {
        $report = $this->check();

        if (! $report['ok']) {
            return ['ok' => false, 'error' => 'The check found something that must be fixed first.', 'report' => $report];
        }

        $this->journal->reset();

        // What was renamed, for enabling the new name after the upgrade.
        $replacements = [];

        foreach ($report['extensions'] as $name => $result) {
            if ($result['status'] === 'replaced' && $result['replacement'] !== null) {
                $replacements[$name] = $result['replacement'];
            }
        }

        @mkdir($this->site->work, 0775, true);
        file_put_contents($this->site->work.'/replacements.json', json_encode($replacements));

        $result = (new Stager($this->site, $this->journal))->stage($report['extensions']);

        return $result['ok']
            ? ['ok' => true, 'core' => $result['core']]
            : ['ok' => false, 'error' => 'Composer could not work out a Flarum 2 install for this forum. Nothing has changed.', 'output' => $this->tail($result['output'])];
    }

    public function upgrade(): array
    {
        if (! (new Stager($this->site, $this->journal))->staged()) {
            return ['ok' => false, 'error' => 'Build Flarum 2 first.'];
        }

        return (new Upgrader($this->site, $this->journal))->run();
    }

    private function tail(string $output): string
    {
        $lines = array_filter(array_map('rtrim', explode("\n", $output)));

        return implode("\n", array_slice($lines, -25));
    }
}
