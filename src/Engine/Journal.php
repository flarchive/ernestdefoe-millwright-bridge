<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * What the upgrade has done so far, written BEFORE each act.
 *
 * 🚨 Before, not after. The request doing the swap can be killed at any point
 * by a host's time limit. A note of an intention that never completed is
 * something undo.php can act on; a changed tree with no note is not. Kept as
 * one small JSON file so the standalone undo script needs nothing to read it.
 */
final class Journal
{
    public function __construct(private string $file)
    {
    }

    /** @return array{steps: array<string,string>, log: list<string>, state: string} */
    public function read(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);

        return is_array($data) ? $data + ['steps' => [], 'log' => [], 'state' => 'idle'] : ['steps' => [], 'log' => [], 'state' => 'idle'];
    }

    public function intend(string $step): void
    {
        $this->write(fn (array $d) => ['steps' => [$step => 'started'] + $d['steps']] + $d);
    }

    public function done(string $step): void
    {
        $this->write(function (array $d) use ($step) {
            $d['steps'][$step] = 'done';

            return $d;
        });
    }

    public function has(string $step): bool
    {
        return isset($this->read()['steps'][$step]);
    }

    public function log(string $line): void
    {
        $this->write(function (array $d) use ($line) {
            $d['log'][] = $line;

            return $d;
        });
    }

    /** A value for undo.php to find, such as the backup folder's random name. */
    public function remember(string $key, string $value): void
    {
        $this->write(fn (array $d) => [$key => $value] + $d);
    }

    public function state(string $state): void
    {
        $this->write(fn (array $d) => ['state' => $state] + $d);
    }

    public function reset(): void
    {
        @unlink($this->file);
    }

    private function write(callable $change): void
    {
        $dir = dirname($this->file);

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $next = $change($this->read());
        $tmp = $this->file.'.tmp';

        file_put_contents($tmp, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->file);   // atomic: a reader sees the old or the new, never half
    }
}
