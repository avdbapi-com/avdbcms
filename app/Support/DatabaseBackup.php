<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Database backups in storage/app/backups (Tools > Backups lists every file there): mysqldump / mariadb-dump when the
 * host lets PHP start processes, else a pure-PHP PDO dump.
 *
 * create() makes a backup in one request ("Back up now"). begin() + resume() make one in short steps for the updater
 * (Tools > Updates), so a big database never meets a host's or Cloudflare's time limit: table by table, in
 * primary-key ranges, each step appending to the same file (a .sql.gz then holds one gzip member per step, which
 * gunzip, zcat and phpMyAdmin read as one file). A dump is complete only when its last line is FOOTER.
 */
class DatabaseBackup
{
    public const FOOTER = '-- Backup completed successfully';

    private const DIRECTORY_PERMISSIONS = 0755;

    /** Rows read per query (keyset pages); a multiple of ROWS_PER_INSERT, so INSERT batches never split. */
    private const ROWS_PER_QUERY = 1000;

    private const ROWS_PER_INSERT = 100;

    public function directory(): string
    {
        return storage_path('app/backups');
    }

    /**
     * One backup in this request: mysqldump (streamed into the file) or the pure-PHP dump.
     *
     * @return array{ok: bool, file: ?string, name: ?string, size: int, method: ?string, message: string}
     */
    public function create(string $prefix = 'backup_'): array
    {
        $directory = $this->directory();

        if (! $this->ensureDirectory($directory)) {
            return $this->result(false, null, null, __('Could not create the backups/ directory under storage/app.'));
        }

        $useGz = self::gzipAvailable();
        $filename = $prefix.date('Y-m-d_His').($useGz ? '.sql.gz' : '.sql');
        $fullPath = $directory.DIRECTORY_SEPARATOR.$filename;

        // 1. mysqldump / mariadb-dump first when the host allows processes and the binary exists.
        if (ShellRunner::available()) {
            $binary = Environment::mysqldumpBinary();

            if ($binary !== '') {
                if ($this->viaMysqldump($binary, $fullPath, $useGz) && is_file($fullPath) && filesize($fullPath) > 100) {
                    return $this->result(true, $fullPath, 'mysqldump', __('Database backup created via mysqldump: :filename (:size)', [
                        'filename' => $filename,
                        'size' => self::megabytes((int) filesize($fullPath)),
                    ]));
                }

                // If the binary cannot authenticate (or the host stops it), the pure-PHP dump below still runs.
                @unlink($fullPath);
            }
        }

        // 2. Pure PHP PDO fallback (works on any default panel install without mysqldump or shell permissions).
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }

            $this->dumpAll($fullPath, $useGz);

            if (! is_file($fullPath) || filesize($fullPath) < 50) {
                @unlink($fullPath);

                return $this->result(false, null, null, __('Pure-PHP backup produced an empty file. Check database connection permissions.'));
            }

            return $this->result(true, $fullPath, 'Pure-PHP', __('Database backup created via Pure-PHP: :filename (:size)', [
                'filename' => $filename,
                'size' => self::megabytes((int) filesize($fullPath)),
            ]));
        } catch (Throwable $e) {
            Log::error('Pure-PHP Database Backup Failed: '.$e->getMessage(), ['exception' => $e]);
            @unlink($fullPath);

            return $this->result(false, null, null, __('Backup failed: :err', ['err' => $e->getMessage()]));
        }
    }

    /**
     * Start a backup made in steps: the file with its header and the list of tables to dump. Continue with resume().
     *
     * @return array<string, mixed> the state to hand to resume()
     */
    public function begin(string $prefix): array
    {
        $directory = $this->directory();

        if (! $this->ensureDirectory($directory)) {
            throw new RuntimeException(__('Could not create the backups/ directory under storage/app.'));
        }

        $useGz = self::gzipAvailable();
        $name = $prefix.date('Y-m-d_His').($useGz ? '.sql.gz' : '.sql');
        $file = $directory.DIRECTORY_SEPARATOR.$name;
        $tables = $this->tables();
        $handle = $this->open($file, $useGz, false);

        try {
            $this->write($handle, $useGz, $this->header());
            $this->flush($handle);
        } finally {
            $this->close($handle, $useGz);
        }

        // The header's gzip member reads back whole like every step's (resume()): a full disk can cut off its end when
        // the file is closed, and every later member would be appended after the broken one.
        if ($useGz && ! self::wholeMember($file, 0)) {
            @unlink($file);

            throw new RuntimeException('The backup file could not be written whole. The disk may be full.');
        }

        clearstatcache(true, $file);

        return [
            'file' => $file,
            'name' => $name,
            'gzip' => $useGz,
            'tables' => $tables,
            'table' => 0,
            'structure' => false,
            'after' => null,
            'offset' => 0,
            'rows' => 0,
            'done' => false,
            'method' => 'Pure-PHP',
            // The size of the file when this state was made: the next step starts exactly there (see resume()).
            'bytes' => (int) filesize($file),
        ];
    }

    /**
     * Continue a backup started by begin() until $deadline (a microtime() value) or the end; the state says 'done'
     * once the footer is written. Every write is checked, so a full disk or account quota stops it with an exception.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function resume(array $state, float $deadline): array
    {
        if (! empty($state['done'])) {
            return $state;
        }

        $file = (string) ($state['file'] ?? '');

        // begin() wrote the header: without that file, appending would make a dump that only looks whole.
        if (! is_file($file)) {
            throw new RuntimeException('The backup file of this step is gone: '.basename($file));
        }

        // A step that died after it wrote (a killed process, a memory limit: no exception ran) left rows the saved
        // state does not know of, maybe half a gzip member: the file goes back to where that state ends, so no row is
        // written twice and no broken member stays.
        clearstatcache(true, $file);
        $from = isset($state['bytes']) ? (int) $state['bytes'] : (int) filesize($file);
        self::cutBack($file, $from);

        $useGz = (bool) $state['gzip'];
        $handle = $this->open($file, $useGz, true);

        try {
            $state = $this->dumpTables($handle, $useGz, $state, $deadline);
            $this->flush($handle);
        } finally {
            $this->close($handle, $useGz);
        }

        // What this step wrote reads back whole (its gzip member up to the checksum at its end), before the state
        // that says so is handed back.
        if ($useGz && ! self::wholeMember($file, $from)) {
            throw new RuntimeException('The backup file could not be written whole. The disk may be full.');
        }

        clearstatcache(true, $file);
        $state['bytes'] = (int) filesize($file);

        return $state;
    }

    /** Delete the file of a backup that did not finish (it would not restore a whole database). */
    public function discard(array $state): void
    {
        $file = (string) ($state['file'] ?? '');

        if ($file !== '' && empty($state['done']) && dirname($file) === $this->directory()) {
            @unlink($file);
        }
    }

    /**
     * The newest backup in storage/app/backups made in the last $hours hours (the updater offers to skip its own
     * backup then).
     *
     * @return array{name: string, date: int, size: int}|null
     */
    public function recent(int $hours = 24): ?array
    {
        $newest = null;

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $name = basename($file);

            if (! is_file($file) || ! preg_match('/\.sql(\.gz)?$/', $name) || (int) @filesize($file) < 100) {
                continue;
            }

            $date = (int) @filemtime($file);

            if ($date >= time() - $hours * 3600 && ($newest === null || $date > $newest['date'])) {
                $newest = ['name' => $name, 'date' => $date, 'size' => (int) filesize($file)];
            }
        }

        return $newest;
    }

    public static function gzipAvailable(): bool
    {
        return extension_loaded('zlib') && function_exists('gzopen');
    }

    /** About how many bytes the database holds (data + indexes), or null when the server does not tell. */
    public function size(): ?int
    {
        try {
            $connection = DB::connection();

            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }

            $row = DB::selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                [$connection->getDatabaseName()]
            );

            return (int) ($row->bytes ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * mysqldump into the file as its output arrives (the process keeps no copy: its buffer is cleared per chunk).
     * The password goes through MYSQL_PWD (honoured by MySQL and MariaDB clients), never the process list.
     */
    protected function viaMysqldump(string $binary, string $fullPath, bool $useGz): bool
    {
        $password = (string) config('database.connections.mysql.password');
        $command = [
            $binary,
            '-h', (string) config('database.connections.mysql.host'),
            '-P', (string) config('database.connections.mysql.port'),
            '-u', (string) config('database.connections.mysql.username'),
            '--skip-ssl',
            '--no-tablespaces',
            '--single-transaction',
            '--default-character-set=utf8mb4',
            (string) config('database.connections.mysql.database'),
        ];

        try {
            $handle = $this->open($fullPath, $useGz, false, 9);
        } catch (Throwable) {
            return false;
        }

        $failed = false;

        try {
            $process = new Process($command, base_path(), $password !== '' ? ['MYSQL_PWD' => $password] : null, null, 1800);
            $process->run(function (string $type, string $buffer) use ($handle, $useGz, &$failed, $process): void {
                if ($type !== Process::OUT || $failed) {
                    return;
                }

                try {
                    $this->write($handle, $useGz, $buffer);
                } catch (Throwable) {
                    $failed = true;
                }

                $process->clearOutput();
            });

            return ! $failed && $process->isSuccessful();
        } catch (Throwable) {
            return false;
        } finally {
            $this->close($handle, $useGz);
        }
    }

    /** The whole pure-PHP dump in one pass and one file handle (one gzip member, as before). */
    protected function dumpAll(string $fullPath, bool $useGz): void
    {
        $state = ['tables' => $this->tables(), 'table' => 0, 'structure' => false, 'after' => null, 'offset' => 0, 'rows' => 0, 'done' => false];
        $handle = $this->open($fullPath, $useGz, false, 9);

        try {
            $this->write($handle, $useGz, $this->header());
            $this->dumpTables($handle, $useGz, $state, INF);
        } finally {
            $this->close($handle, $useGz);
        }
    }

    /**
     * Table structure and rows from where $state stopped, until $deadline; the footer after the last table.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function dumpTables($handle, bool $useGz, array $state, float $deadline): array
    {
        $pdo = DB::connection()->getPdo();
        $tables = (array) $state['tables'];

        while ((int) $state['table'] < count($tables)) {
            [$table, $key] = $tables[(int) $state['table']];
            $quoted = self::quoteName($table);

            if (! $state['structure']) {
                $create = $pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
                $this->write($handle, $useGz, "-- --------------------------------------------------------\n"
                    ."-- Table structure for `{$table}`\n"
                    ."-- --------------------------------------------------------\n"
                    ."DROP TABLE IF EXISTS `{$table}`;\n"
                    .($create[1] ?? '').";\n\n"
                    ."-- Dumping data for table `{$table}`\n");
                $state['structure'] = true;
            }

            $rows = $this->page($pdo, $quoted, (array) $key, $state);
            $batch = [];

            foreach ($rows as $row) {
                $values = [];

                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_numeric($value) && ! is_string($value)) {
                        $values[] = $value;
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }

                $batch[] = '('.implode(', ', $values).')';

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $this->write($handle, $useGz, "INSERT INTO `{$table}` VALUES \n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->write($handle, $useGz, "INSERT INTO `{$table}` VALUES \n".implode(",\n", $batch).";\n");
            }

            $state['rows'] = (int) $state['rows'] + count($rows);

            if (count($rows) < self::ROWS_PER_QUERY) {
                // This table is done.
                $this->write($handle, $useGz, "\n");
                $state['table'] = (int) $state['table'] + 1;
                $state['structure'] = false;
                $state['after'] = null;
                $state['offset'] = 0;
            } elseif (count((array) $key) === 1) {
                $last = $rows[count($rows) - 1];
                $state['after'] = $last[$state['key_index'] ?? 0] ?? null;
            } else {
                $state['offset'] = (int) $state['offset'] + count($rows);
            }

            if (microtime(true) >= $deadline) {
                return $state;
            }
        }

        $this->write($handle, $useGz, "COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n".self::FOOTER."\n");
        $state['done'] = true;

        return $state;
    }

    /**
     * The next ROWS_PER_QUERY rows of a table: after the last primary key value (one-column key), else by offset in
     * key order (or table order when the table has no primary key).
     *
     * @param  array<int, string>  $key
     * @param  array<string, mixed>  $state  gets key_index (the key column's position in a row)
     * @return array<int, array<int, mixed>>
     */
    private function page(PDO $pdo, string $table, array $key, array &$state): array
    {
        $limit = ' LIMIT '.self::ROWS_PER_QUERY;
        $order = $key !== [] ? ' ORDER BY '.implode(', ', array_map(self::quoteName(...), $key)) : '';

        if (count($key) === 1) {
            if ($state['after'] === null) {
                $statement = $pdo->query('SELECT * FROM '.$table.$order.$limit);
            } else {
                $statement = $pdo->prepare('SELECT * FROM '.$table.' WHERE '.self::quoteName($key[0]).' > ?'.$order.$limit);
                $statement->execute([$state['after']]);
            }

            // The key column's position in the row (SELECT * keeps the table's column order).
            $state['key_index'] = 0;

            for ($i = 0; $i < $statement->columnCount(); $i++) {
                if (($statement->getColumnMeta($i)['name'] ?? null) === $key[0]) {
                    $state['key_index'] = $i;
                    break;
                }
            }
        } else {
            $statement = $pdo->query('SELECT * FROM '.$table.$order.$limit.' OFFSET '.(int) $state['offset']);
        }

        return $statement->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<int, array{0: string, 1: array<int, string>}> base tables with their primary key columns */
    private function tables(): array
    {
        $pdo = DB::connection()->getPdo();
        $tables = [];
        $statement = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            $key = [];

            foreach ($pdo->query('SHOW KEYS FROM '.self::quoteName((string) $row[0])." WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $key[(int) ($column['Seq_in_index'] ?? count($key) + 1)] = (string) $column['Column_name'];
            }

            ksort($key);
            $tables[] = [(string) $row[0], array_values($key)];
        }

        return $tables;
    }

    private function header(): string
    {
        $database = (string) config('database.connections.mysql.database');

        return "-- AVDBCMS Pure-PHP Database Backup\n"
            .'-- Generated: '.date('Y-m-d H:i:s')."\n"
            ."-- Database: `{$database}`\n"
            ."-- --------------------------------------------------------\n\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n"
            ."SET NAMES utf8mb4;\n"
            ."SET AUTOCOMMIT=0;\n"
            ."START TRANSACTION;\n\n";
    }

    /** @return resource */
    private function open(string $file, bool $useGz, bool $append, int $level = 6)
    {
        $handle = $useGz ? @gzopen($file, ($append ? 'ab' : 'wb').$level) : @fopen($file, $append ? 'ab' : 'wb');

        if (! $handle) {
            throw new RuntimeException('Cannot open file for writing: '.basename($file));
        }

        return $handle;
    }

    /** @param  resource  $handle */
    private function write($handle, bool $useGz, string $text): void
    {
        if ($text === '') {
            return;
        }

        $written = $useGz ? gzwrite($handle, $text) : fwrite($handle, $text);

        if ($written === false || $written < strlen($text)) {
            throw new RuntimeException('Could not write the backup file. The disk may be full.');
        }
    }

    /** @param  resource  $handle */
    private function close($handle, bool $useGz): void
    {
        $useGz ? @gzclose($handle) : @fclose($handle);
    }

    /**
     * Write out what is buffered and say when that fails (a full disk or quota): fclose() and gzclose() never tell.
     * On a gzip stream this is a sync flush, so a later close adds only the end of the member.
     *
     * @param  resource  $handle
     */
    private function flush($handle): void
    {
        if (! @fflush($handle)) {
            throw new RuntimeException('Could not write the backup file. The disk may be full.');
        }
    }

    /** Cut a backup file back to $bytes (what a step left that its state does not know of goes). */
    private static function cutBack(string $file, int $bytes): void
    {
        $size = (int) filesize($file);

        if ($size === $bytes) {
            return;
        }

        if ($size < $bytes || ! function_exists('ftruncate')) {
            throw new RuntimeException('The backup file is not as its last step left it: '.basename($file));
        }

        $handle = @fopen($file, 'r+b');
        $cut = $handle !== false && @ftruncate($handle, $bytes);

        if ($handle !== false) {
            fclose($handle);
        }

        clearstatcache(true, $file);

        if (! $cut || (int) filesize($file) !== $bytes) {
            throw new RuntimeException('The backup file could not be cut back to its last step: '.basename($file));
        }
    }

    /**
     * Does the gzip member that starts at byte $from (the one a step wrote) read back to its end, checksum included?
     * Without inflate_init() (an old zlib build) nothing can be said: true.
     */
    private static function wholeMember(string $file, int $from): bool
    {
        if (! function_exists('inflate_init') || ($handle = @fopen($file, 'rb')) === false) {
            return ! function_exists('inflate_init');
        }

        try {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);

            if ($inflate === false || fseek($handle, $from) !== 0) {
                return false;
            }

            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);

                if ($chunk === false || @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH) === false) {
                    return false;
                }

                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    // The member ended (checksum checked): it must be the rest of the file, nothing after it.
                    clearstatcache(true, $file);

                    return inflate_get_read_len($inflate) === (int) filesize($file) - $from;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, self::DIRECTORY_PERMISSIONS, true) || is_dir($directory);
    }

    /** @return array{ok: bool, file: ?string, name: ?string, size: int, method: ?string, message: string} */
    private function result(bool $ok, ?string $file, ?string $method, string $message): array
    {
        return [
            'ok' => $ok,
            'file' => $file,
            'name' => $file !== null ? basename($file) : null,
            'size' => $file !== null && is_file($file) ? (int) filesize($file) : 0,
            'method' => $method,
            'message' => $message,
        ];
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 2).' MB';
    }

    private static function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
