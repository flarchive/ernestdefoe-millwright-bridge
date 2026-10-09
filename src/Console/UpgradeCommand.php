<?php

namespace ErnestDefoe\MillwrightBridge\Console;

use ErnestDefoe\MillwrightBridge\Bridge;
use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Symfony\Component\Console\Input\InputOption;

class UpgradeCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('bridge:upgrade')
            ->setDescription('Upgrade this forum to Flarum 2: check, build Flarum 2 beside it, then swap it in. Puts everything back if anything fails.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report whether this forum can upgrade.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask before swapping Flarum 2 in.');
    }

    protected function fire(): int
    {
        $bridge = new Bridge(resolve(Paths::class));
        $report = $bridge->check();

        $this->info('PHP '.$report['php']);

        foreach ($report['extensions'] as $name => $r) {
            $label = $name === 'ernestdefoe/millwright-bridge' ? 'removed by the upgrade; Millwright takes over' : match ($r['status']) {
                'ok' => 'ok'.($r['version'] ? ' ('.$r['version'].')' : ''),
                'none' => 'NO FLARUM 2 RELEASE',
                'replaced' => 'replaced by '.$r['replacement'],
                'retired' => $r['replacement'] ? 'removed by the upgrade, replaced by '.$r['replacement'] : 'removed by the upgrade: not needed on Flarum 2',
                default => 'not on Packagist, checked when building',
            };
            $this->info(sprintf('  %-42s %s', $name, $label));
        }

        foreach ($report['notes'] as $note) {
            $this->info('Note: '.$note);
        }

        foreach ($report['blockers'] as $blocker) {
            $this->error('Blocked: '.$blocker);
        }

        if (! $report['ok']) {
            return 1;
        }

        if ($this->input->getOption('check')) {
            $this->info('This forum can upgrade to Flarum 2.');

            return 0;
        }

        $this->info('Building Flarum 2 beside the forum. The forum keeps running.');
        $staged = $bridge->stage();

        if (! $staged['ok']) {
            $this->error($staged['error']);
            $this->error($staged['output'] ?? '');

            return 1;
        }

        $this->info('Built Flarum '.$staged['core'].'.');

        if (! $this->input->getOption('yes') && ! $this->confirm('Back up and swap Flarum 2 in now? The forum is briefly unavailable while the database is updated.')) {
            $this->info('Stopped. Flarum 2 stays built, ready for next time; nothing on the forum has changed.');

            return 0;
        }

        $result = $bridge->upgrade();

        foreach ($result['log'] ?? [] as $line) {
            $this->info('  '.$line);
        }

        if (! $result['ok']) {
            $this->error($result['error']);

            return 1;
        }

        $this->info('This forum now runs Flarum '.$result['version'].'. Millwright is installed to keep it up to date.');

        return 0;
    }
}
