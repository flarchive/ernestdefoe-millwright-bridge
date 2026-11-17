<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Dump and restore the forum's tables with PDO alone.
 *
 * No mysqldump: shared hosts rarely let PHP run it. One statement per line,
 * values quoted by PDO (which escapes newlines), so the restore can read the
 * file a line at a time without loading it.
 *
 * 🚨 Only the forum's tables, chosen by its prefix. A database shared with
 * another application must come back exactly as it was, so the restore drops
 * only tables that were dumped or that appeared under the prefix after the
 * dump (the ones Flarum 2's migrations created).
 */
final class Database
{
    public function __construct(private \PDO $pdo, private string $prefix)
    {
    }

    /** @return list<string> */
    public function tables(): array
    {
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->prefix).'%';
        $rows = $this->pdo->query('SHOW TABLES LIKE '.$this->pdo->quote($like))->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $rows));
    }

    /** Write every forum table to $file (gzip). Returns the table list. */
    public function dump(string $file): array
    {
        $tables = $this->tables();
        $gz = gzopen($file.'.tmp', 'wb6');

        if ($gz === false) {
            throw new \RuntimeException('Could not write the database backup to '.$file);
        }

        gzwrite($gz, '-- Millwright Bridge backup. Tables: '.json_encode($tables)."\n");

        foreach ($tables as $table) {
            $q = '`'.str_replace('`', '``', $table).'`';
            $create = $this->pdo->query('SHOW CREATE TABLE '.$q)->fetch(\PDO::FETCH_NUM)[1];

            gzwrite($gz, 'DROP TABLE IF EXISTS '.$q.";\n");
            gzwrite($gz, str_replace("\n", ' ', $create).";\n");

            $stmt = $this->pdo->query('SELECT * FROM '.$q, \PDO::FETCH_NUM);
            $batch = [];

            foreach ($stmt as $row) {
                $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), $row)).')';

                if (count($batch) >= 200) {
                    gzwrite($gz, 'INSERT INTO '.$q.' VALUES '.implode(',', $batch).";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                gzwrite($gz, 'INSERT INTO '.$q.' VALUES '.implode(',', $batch).";\n");
            }
        }

        gzclose($gz);
        rename($file.'.tmp', $file);

        return $tables;
    }

    /** Put the forum's tables back as $file has them. */
    public function restore(string $file): void
    {
        $gz = gzopen($file, 'rb');

        if ($gz === false) {
            throw new \RuntimeException('The database backup '.$file.' could not be read.');
        }

        $header = (string) gzgets($gz);
        $dumped = preg_match('/Tables: (\[.*\])/', $header, $m) ? (array) json_decode($m[1], true) : [];

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // Tables Flarum 2 created since the backup go too; nothing else is touched.
        foreach (array_diff($this->tables(), $dumped) as $added) {
            $this->pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', $added).'`');
        }

        $statement = '';

        while (($line = gzgets($gz)) !== false) {
            $statement .= $line;

            if (str_ends_with(rtrim($line, "\n"), ';')) {
                $this->pdo->exec($statement);
                $statement = '';
            }
        }

        gzclose($gz);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
