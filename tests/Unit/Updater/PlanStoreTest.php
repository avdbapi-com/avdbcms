<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\PlanStore;
use App\Support\Updater\UpdateException;
use App\Support\Updater\UpdatePaths;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The updater's own files (F8 14): the update lock tells "another step holds it" (busy, the page waits and asks again)
 * from "this disk cannot lock files at all" (some network file systems: refused with the reason, never "busy" for good),
 * and plan.json is never written empty.
 */
class PlanStoreTest extends TestCase
{
    private UpdaterSandbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
    }

    protected function tearDown(): void
    {
        if (in_array('avdnolock', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('avdnolock');
        }

        $this->box->delete();
        parent::tearDown();
    }

    public function test_a_lock_another_step_holds_is_busy_and_free_again_after_it(): void
    {
        $store = new PlanStore($this->box->paths());
        $held = $store->lock();
        $this->assertIsResource($held);

        $this->assertNull($store->lock(), 'busy: another step holds it');

        $store->unlock($held);
        $again = $store->lock();
        $this->assertIsResource($again);
        $store->unlock($again);
    }

    public function test_a_disk_that_cannot_lock_files_is_named_instead_of_busy_for_good(): void
    {
        stream_wrapper_register('avdnolock', NoLockStream::class);
        $store = new PlanStore(new UpdatePaths('avdnolock://site'));
        // PHP leaves errno as it was for a stream that cannot lock: a failed open first makes it "no such file", as on
        // a network share whose flock() fails with ENOLCK (never "would block").
        @fopen($this->box->path('not-there/x'), 'rb');

        try {
            $store->lock();
            $this->fail('A disk without locks must be named, not answered "busy".');
        } catch (UpdateException $e) {
            $this->assertSame('This server cannot lock files in storage/app/updates, so an update cannot run safely there. Ask your host about file locking (flock) on this disk.', $e->getMessage());
            $this->assertFalse($e->retry);
            $this->assertSame('flock not supported', $e->detail);
        }
    }

    public function test_a_plan_is_never_written_empty(): void
    {
        // A failure reason quoted from an error may not be UTF-8: json_encode() would give false, and "" was written.
        $file = $this->box->path('plan.json');

        PlanStore::write($file, ['status' => 'failed', 'failure' => ['reason' => "cannot read \xC3\x28 here"]]);

        $plan = PlanStore::read($file);
        $this->assertSame('failed', $plan['status']);
        $this->assertSame("cannot read \u{FFFD}( here", $plan['failure']['reason']);
    }
}

/** A file system whose files cannot be locked (flock() fails, and not because another holder makes it wait). */
final class NoLockStream
{
    /** @var resource|null */
    public $context;

    /** @var resource */
    private $inner;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->inner = fopen('php://memory', 'w+b');

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return $operation === LOCK_UN;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->inner, $count);
    }

    public function stream_write(string $data): int
    {
        return (int) fwrite($this->inner, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->inner);
    }

    public function stream_close(): void
    {
        fclose($this->inner);
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 040755];
    }
}
