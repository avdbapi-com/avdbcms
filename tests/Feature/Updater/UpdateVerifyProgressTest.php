<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\Checksum;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * RP4-01 (since 1.2.2): the verify step hashed the whole zip and read its listing in one request with nothing saved, so
 * on a slow-I/O host where the hash plus the listing exceed the time limit every time (a 25 s hash, then the phar://
 * listing pushes it over 30 s; in the first RP4 run the hash alone exceeded 30 s, twelve kills), a killed request
 * re-hashed the whole zip from the start on every retry and verify could never finish. Now the hash is read in pieces
 * with the progress kept in plan.json after each piece and stops at the step's deadline like the download (a killed
 * request loses one piece at most), and the verified checksum is kept with the zip's size and mtime right after the
 * hash, so a retry whose request is cut off in the listing skips the hash and goes straight to the listing.
 */
class UpdateVerifyProgressTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        // The tests of the hash in pieces get a release with an extra file of incompressible noise: a zip of three pieces.
        $this->makeSite(str_contains($this->name(), 'pieces') ? 2 * Checksum::PIECE_BYTES + 512 * 1024 : 0);
    }

    protected function tearDown(): void
    {
        $this->removeSite();
        parent::tearDown();
        Updater::$stepSeconds = null;
    }

    /** The update.log lines of the verify step that say what happened to the hash. */
    private function hashLines(): array
    {
        $lines = file($this->jobFolder().'/update.log', FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'SHA-256') || str_contains($line, 'hashed ')));
    }

    public function test_the_verify_step_keeps_the_zip_checksum_with_its_size_and_mtime(): void
    {
        $updater = $this->updater();
        $updater->start($this->admin);

        // Step up to the moment verify finishes (it sets the status to the next step and returns).
        $this->stepUntil($updater, fn (array $answer): bool => in_array($answer['status'], ['unpacking', 'backing-up', 'preparing', 'swapping'], true));

        $zip = $this->zipFile();
        $this->assertFileExists($zip);
        $plan = $this->storedPlan();

        $this->assertIsArray($plan['verified'] ?? null, 'the verify step kept the checksum in plan.json (RP4-01)');
        $this->assertSame(Checksum::file($zip), $plan['verified']['sha256'] ?? null, 'the kept hash is the zip\'s SHA-256');
        $this->assertSame((int) filesize($zip), $plan['verified']['size'] ?? null, 'kept with the zip\'s size');
        $this->assertSame((int) filemtime($zip), $plan['verified']['mtime'] ?? null, 'kept with the zip\'s mtime');
    }

    public function test_a_retry_uses_the_kept_checksum_and_does_not_read_the_whole_zip_again(): void
    {
        $updater = $this->updater();
        $updater->start($this->admin);
        $this->stepUntil($updater, fn (array $answer): bool => in_array($answer['status'], ['unpacking', 'backing-up', 'preparing', 'swapping'], true));

        $zip = $this->zipFile();
        $plan = $this->storedPlan();
        $this->assertIsArray($plan['verified'] ?? null);
        $size = (int) filesize($zip);
        $mtime = (int) filemtime($zip);

        // The request was "cut off in the listing": back to verifying, with the kept checksum in the plan.
        $this->changePlan(['status' => 'verifying']);

        // The zip's bytes are now different (same length, same mtime), so a re-hash would NOT match the checksum. With
        // the kept hash, verify gets past the SHA-256 check and fails later, at the zip's listing, not at the hash.
        file_put_contents($zip, str_repeat('X', $size));
        touch($zip, $mtime);
        clearstatcache(true, $zip);
        $this->assertSame($size, (int) filesize($zip), 'the replaced zip has the same size');
        $this->assertSame($mtime, (int) filemtime($zip), 'and the same mtime');

        $answer = $updater->step($this->admin);

        // The verify step did not reject the download as damaged: it used the kept hash (a re-hash of the garbage would
        // have said "its SHA-256 does not match"). It stopped later, at the listing of the now-invalid zip.
        $this->assertStringNotContainsString('SHA-256', (string) ($answer['message'] ?? ''), 'the hash was not read again (RP4-01)');
        $this->assertStringNotContainsString('does not match', (string) ($answer['message'] ?? ''));
    }

    public function test_a_retry_with_the_kept_checksum_says_so_in_the_log_and_goes_on_to_the_next_step(): void
    {
        $updater = $this->updater();
        $updater->start($this->admin);
        $this->stepUntil($updater, fn (array $answer): bool => in_array($answer['status'], ['unpacking', 'backing-up', 'preparing', 'swapping'], true));
        $this->assertIsArray($this->storedPlan()['verified'] ?? null);
        $this->assertSame(1, count(array_filter($this->hashLines(), fn (string $line): bool => str_contains($line, 'SHA-256 matches'))), 'one hash so far');

        // The request was cut off in the listing: back to verifying with the kept checksum and the zip untouched.
        $this->changePlan(['status' => 'verifying']);
        $answer = $updater->step($this->admin);

        $this->assertSame('unpacking', $answer['status'], 'the retry got past verify with the kept hash: '.json_encode($answer));
        $lines = $this->hashLines();
        $this->assertSame(1, count(array_filter($lines, fn (string $line): bool => str_contains($line, 'SHA-256 matches'))), 'the hash was not read a second time: '.implode(' | ', $lines));
        $this->assertSame(1, count(array_filter($lines, fn (string $line): bool => str_contains($line, 'SHA-256 kept from the earlier try'))), 'and the log says the kept hash was used: '.implode(' | ', $lines));
    }

    public function test_the_hash_is_read_in_pieces_across_requests_and_a_cut_off_request_loses_one_piece_at_most(): void
    {
        $updater = $this->updater();
        $updater->start($this->admin);
        $this->stepUntil($updater, fn (array $answer): bool => $answer['status'] === 'verifying');

        $zip = $this->zipFile();
        $size = (int) filesize($zip);
        $mtime = (int) filemtime($zip);
        $this->assertGreaterThan(2 * Checksum::PIECE_BYTES, $size, 'a zip of three pieces');
        $this->assertLessThanOrEqual(3 * Checksum::PIECE_BYTES, $size);
        $sha256 = Checksum::file($zip);

        // A host whose limit leaves no time: each request hashes one piece and stops (the download does the same).
        Updater::$stepSeconds = 0.0;

        $answer = $updater->step($this->admin);
        $this->assertSame(['verifying', 'step'], [$answer['status'], $answer['next']], 'the step stopped in time, the page sends the next one: '.json_encode($answer));
        $plan = $this->storedPlan();
        $this->assertSame(Checksum::PIECE_BYTES, $plan['hashing']['done'] ?? null, 'one piece hashed, kept in plan.json');
        $this->assertSame([$size, $mtime], [$plan['hashing']['size'] ?? null, $plan['hashing']['mtime'] ?? null]);
        $this->assertIsString($plan['hashing']['context'] ?? null, 'with the hash context, for the next request');
        $this->assertArrayNotHasKey('verified', $plan, 'nothing verified yet');
        $this->assertSame(['done' => Checksum::PIECE_BYTES, 'total' => $size], $answer['progress'], 'the page shows how far the hash is');
        $this->assertSame(__('Checking the download: :done of :total', ['done' => Updater::size(Checksum::PIECE_BYTES), 'total' => Updater::size($size)]), $answer['label']);
        $this->assertStringContainsString('hashed '.Updater::size(Checksum::PIECE_BYTES).' of '.Updater::size($size).', the next request goes on', implode(' | ', $this->hashLines()));

        // The host killed the next request half-way through the second piece: what the first one saved is what the
        // retry finds. To prove the retry does not start from the first byte again, the first bytes of the zip are
        // damaged for the second piece and put back before the third (same size, same mtime): a hash that read the
        // first piece again would be wrong and the download would be refused as damaged.
        $original = file_get_contents($zip, false, null, 0, 1024);
        $handle = fopen($zip, 'r+b');
        fwrite($handle, str_repeat('X', 1024));
        fclose($handle);
        touch($zip, $mtime);
        clearstatcache(true, $zip);
        $this->assertSame([$size, $mtime], [(int) filesize($zip), (int) filemtime($zip)]);

        $answer = $updater->step($this->admin);
        $this->assertSame('verifying', $answer['status'], json_encode($answer));
        $this->assertSame(2 * Checksum::PIECE_BYTES, $this->storedPlan()['hashing']['done'] ?? null, 'the second piece went on from the first');

        $handle = fopen($zip, 'r+b');
        fwrite($handle, $original);
        fclose($handle);
        touch($zip, $mtime);
        clearstatcache(true, $zip);
        $this->assertSame($sha256, Checksum::file($zip), 'the zip is whole again');

        // The last piece finishes the hash; the same request then checks it, keeps it and reads the listing.
        Updater::$stepSeconds = null;
        $answer = $updater->step($this->admin);
        $this->assertSame('unpacking', $answer['status'], 'the hash of the three pieces matched and the listing was read: '.json_encode($answer));
        $plan = $this->storedPlan();
        $this->assertArrayNotHasKey('hashing', $plan, 'the progress is gone once the hash is done');
        $this->assertSame(['sha256' => $sha256, 'size' => $size, 'mtime' => $mtime], $plan['verified'] ?? null, 'the hash of the pieces is the zip\'s SHA-256, kept with its size and mtime');
        $lines = $this->hashLines();
        $this->assertSame(1, count(array_filter($lines, fn (string $line): bool => str_contains($line, 'SHA-256 matches'))), 'one "SHA-256 matches" for the whole chain: '.implode(' | ', $lines));
        $this->assertSame(1, count(array_filter($lines, fn (string $line): bool => str_contains($line, 'reading the SHA-256'))), 'the hash was started once: '.implode(' | ', $lines));
        $this->assertSame(2, count(array_filter($lines, fn (string $line): bool => str_contains($line, 'the next request goes on'))), 'two requests stopped with the hash unfinished: '.implode(' | ', $lines));
    }

    public function test_a_hash_read_in_pieces_still_refuses_a_damaged_download(): void
    {
        $updater = $this->updater();
        $updater->start($this->admin);
        $this->stepUntil($updater, fn (array $answer): bool => $answer['status'] === 'verifying');

        // The zip's bytes are wrong from the start (same size, same mtime): the hash of the pieces must not match.
        $zip = $this->zipFile();
        $size = (int) filesize($zip);
        $mtime = (int) filemtime($zip);
        file_put_contents($zip, str_repeat('X', $size));
        touch($zip, $mtime);
        clearstatcache(true, $zip);

        Updater::$stepSeconds = 0.0;
        $this->assertSame('verifying', $updater->step($this->admin)['status']);
        $this->assertSame(Checksum::PIECE_BYTES, $this->storedPlan()['hashing']['done'] ?? null);

        Updater::$stepSeconds = null;
        $answer = $updater->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame('failed', $answer['status']);
        $this->assertStringContainsString('SHA-256 does not match', (string) $answer['message']);
        $this->assertFileDoesNotExist($zip, 'a damaged zip is not kept');
        $plan = $this->storedPlan();
        $this->assertArrayNotHasKey('hashing', $plan);
        $this->assertArrayNotHasKey('verified', $plan);
    }
}
