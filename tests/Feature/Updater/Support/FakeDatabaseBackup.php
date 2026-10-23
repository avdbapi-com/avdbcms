<?php

namespace Tests\Feature\Updater\Support;

use App\Support\DatabaseBackup;
use RuntimeException;

/**
 * The database backup of updater tests: the dump code is MySQL-only (the tests run on SQLite), so this writes a small
 * dump of three fake "tables" in steps, one table per resume(), into the sandbox's storage/app/backups, with the same
 * state keys and the same footer as the real one. $failOnTable makes resume() throw when it reaches that table;
 * $databaseSize is what size() reports (the updater's disk-space estimate counts the dump in).
 */
final class FakeDatabaseBackup extends DatabaseBackup
{
    public ?int $failOnTable = null;

    public ?int $databaseSize = 4096;

    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly string $folder)
    {
    }

    public function directory(): string
    {
        return $this->folder;
    }

    public function begin(string $prefix): array
    {
        $this->calls[] = 'begin';

        if (! is_dir($this->folder) && ! mkdir($this->folder, 0755, true) && ! is_dir($this->folder)) {
            throw new RuntimeException('Cannot create '.$this->folder);
        }

        $name = $prefix.date('Y-m-d_His').'.sql';
        file_put_contents($this->folder.'/'.$name, "-- AVDBCMS test dump\nSET FOREIGN_KEY_CHECKS=0;\n");

        return ['file' => $this->folder.'/'.$name, 'name' => $name, 'gzip' => false, 'tables' => [['users', ['id']], ['videos', ['id']], ['settings', ['id']]],
            'table' => 0, 'structure' => false, 'after' => null, 'offset' => 0, 'rows' => 0, 'done' => false, 'method' => 'Pure-PHP'];
    }

    public function resume(array $state, float $deadline): array
    {
        $this->calls[] = 'resume';

        if (! empty($state['done'])) {
            return $state;
        }

        if (! is_file((string) $state['file'])) {
            throw new RuntimeException('The backup file of this step is gone: '.basename((string) $state['file']));
        }

        $table = (int) $state['table'];

        if ($this->failOnTable === $table) {
            throw new RuntimeException('Lost connection to the database server during the backup');
        }

        [$name] = $state['tables'][$table];
        file_put_contents((string) $state['file'], "DROP TABLE IF EXISTS `{$name}`;\nINSERT INTO `{$name}` VALUES (1);\n", FILE_APPEND);
        $state['table'] = $table + 1;
        $state['rows'] = (int) $state['rows'] + 1;

        if ($state['table'] >= count($state['tables'])) {
            file_put_contents((string) $state['file'], "COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n".self::FOOTER."\n", FILE_APPEND);
            $state['done'] = true;
        }

        return $state;
    }

    public function size(): ?int
    {
        return $this->databaseSize;
    }
}
