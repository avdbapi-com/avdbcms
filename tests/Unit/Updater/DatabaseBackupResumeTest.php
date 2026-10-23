<?php

namespace Tests\Unit\Updater;

use App\Support\DatabaseBackup;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The update's database backup in steps (F8 10): a step that died after it wrote (a killed process or a memory limit:
 * no exception ran, the saved state is the one from before) never leaves rows twice or a broken gzip member in the
 * dump, because the next step first cuts the file back to where the saved state ends; each step's gzip member must read
 * back whole. The dump code itself is MySQL-only, so these states list no tables: resume() then writes only the end
 * of the dump, through the same open / cut / flush / check path as every step.
 */
class DatabaseBackupResumeTest extends TestCase
{
    private const HEADER = "-- AVDBCMS Pure-PHP Database Backup\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n\n";

    private UpdaterSandbox $box;

    private DatabaseBackup $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
        $folder = $this->box->path('backups');
        mkdir($folder, 0755, true);
        $this->backup = new class($folder) extends DatabaseBackup
        {
            public function __construct(private readonly string $folder)
            {
            }

            public function directory(): string
            {
                return $this->folder;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->box->delete();
        parent::tearDown();
    }

    public function test_a_gzip_step_that_died_after_it_wrote_is_cut_back_and_nothing_is_written_twice(): void
    {
        $this->requireGzip();
        [$file, $state] = $this->started(true);
        // The killed step: its rows reached the file (half a gzip member), its new state never reached plan.json.
        file_put_contents($file, substr((string) gzencode("INSERT INTO `users` VALUES (1);\n"), 0, 14), FILE_APPEND);

        $state = $this->backup->resume($state, INF);

        $this->assertTrue($state['done']);
        clearstatcache();
        $this->assertSame(filesize($file), $state['bytes'], 'the next step would start exactly here');
        $dump = implode('', gzfile($file) ?: []);
        $this->assertSame(self::HEADER."COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n".DatabaseBackup::FOOTER."\n", $dump, 'the half member is gone, every member reads back');
    }

    public function test_a_plain_dump_is_cut_back_the_same_way(): void
    {
        [$file, $state] = $this->started(false);
        file_put_contents($file, "INSERT INTO `users` VALUES (1),\n(2", FILE_APPEND);

        $state = $this->backup->resume($state, INF);

        $this->assertTrue($state['done']);
        $this->assertSame(self::HEADER."COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n".DatabaseBackup::FOOTER."\n", file_get_contents($file));
    }

    public function test_a_file_shorter_than_its_last_step_left_it_is_no_backup(): void
    {
        [, $state] = $this->started(false);
        $state['bytes'] += 100;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The backup file is not as its last step left it');

        $this->backup->resume($state, INF);
    }

    public function test_a_gzip_member_is_whole_only_up_to_its_checksum_with_nothing_after_it(): void
    {
        $this->requireGzip();
        $whole = new ReflectionMethod(DatabaseBackup::class, 'wholeMember');
        $file = $this->box->path('member.gz');
        $member = (string) gzencode(str_repeat("INSERT INTO `videos` VALUES (1, 'x');\n", 2000));
        file_put_contents($file, 'earlier members'.$member);

        $this->assertTrue($whole->invoke(null, $file, strlen('earlier members')));

        file_put_contents($file, 'earlier members'.substr($member, 0, -4));
        $this->assertFalse($whole->invoke(null, $file, strlen('earlier members')), 'cut before its checksum and size');

        $broken = $member;
        $broken[strlen($member) - 6] = chr(ord($broken[strlen($member) - 6]) ^ 0xFF);
        file_put_contents($file, 'earlier members'.$broken);
        $this->assertFalse($whole->invoke(null, $file, strlen('earlier members')), 'a wrong checksum');

        file_put_contents($file, 'earlier members'.$member.'more');
        $this->assertFalse($whole->invoke(null, $file, strlen('earlier members')), 'something after the member');
    }

    /**
     * A dump as begin() leaves it (the header, the state with the file's size), listing no tables.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function started(bool $gzip): array
    {
        $name = 'backup_before_update_9.9.9_2026-10-23_120000.sql'.($gzip ? '.gz' : '');
        $file = $this->backup->directory().'/'.$name;

        if ($gzip) {
            $handle = gzopen($file, 'wb6');
            gzwrite($handle, self::HEADER);
            gzclose($handle);
        } else {
            file_put_contents($file, self::HEADER);
        }

        clearstatcache();

        return [$file, ['file' => $file, 'name' => $name, 'gzip' => $gzip, 'tables' => [], 'table' => 0, 'structure' => false,
            'after' => null, 'offset' => 0, 'rows' => 0, 'done' => false, 'method' => 'Pure-PHP', 'bytes' => (int) filesize($file)]];
    }

    private function requireGzip(): void
    {
        if (! DatabaseBackup::gzipAvailable() || ! function_exists('inflate_init')) {
            $this->markTestSkipped('This PHP has no zlib.');
        }
    }
}
