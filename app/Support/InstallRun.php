<?php

namespace App\Support;

use Throwable;

/**
 * The state of one installation by the web installer, kept between the requests that do it (R1B-001).
 *
 * The installer does its database work in steps over several requests (App\Support\Installer::runStep()), each one a
 * few seconds long, because a host ends a request that runs too long: PHP-FPM's request_terminate_timeout, a web
 * server's or a proxy's timeout. Such an end runs no PHP code at all (no "finally", no shutdown function), so this file
 * is written BEFORE each piece of work starts ("running": what is being done) and cleared when the request stops by
 * itself. A request that finds "running" still set knows that the one before it was ended from outside, and where.
 *
 * It holds no secret: the database host, name and user (never its password), the import position, the steps done and
 * the texts of the result page. The passwords stay in the owner's session. Writes go to a temporary file that is then
 * renamed over this one, so a request ended half-way never leaves half a file. Without a path (install() in one process:
 * the command line, tests) it lives in memory only.
 */
final class InstallRun
{
    public const VERSION = 1;

    /** @var array<string, mixed> */
    public array $data;

    /** @param array<string, mixed> $data */
    private function __construct(private readonly ?string $path, array $data)
    {
        $this->data = $data;
    }

    /** @param array<string, mixed> $data */
    public static function memory(array $data): self
    {
        return new self(null, $data + ['version' => self::VERSION]);
    }

    /** @param array<string, mixed> $data */
    public static function create(string $path, array $data): self
    {
        $run = new self($path, $data + ['version' => self::VERSION]);
        $run->save();

        return $run;
    }

    /** The run kept at $path, or null: none, or a file this version cannot read. */
    public static function load(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        // A reader can meet the moment a writer renames its new copy over the file (Windows): one more try.
        for ($try = 0; $try < 3; $try++) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            if (is_array($data)) {
                return ($data['version'] ?? null) === self::VERSION ? new self($path, $data) : null;
            }

            usleep(20000);
        }

        return null;
    }

    public function persistent(): bool
    {
        return $this->path !== null;
    }

    /** Reads the file again (another request may have written it since this one read it). */
    public function reload(): void
    {
        if ($this->path === null) {
            return;
        }

        $fresh = self::load($this->path);

        if ($fresh !== null) {
            $this->data = $fresh->data;
        }
    }

    public function save(): void
    {
        $this->data['updated_at'] = time();

        if ($this->path === null) {
            return;
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($json)) {
            throw new \RuntimeException('The installer could not record its progress.');
        }

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) === strlen($json)) {
            // The new copy replaces the file in one step. On Windows a reader that has the file open makes the rename
            // fail for a moment: a few short tries, then the plain write below.
            for ($try = 0; $try < 5; $try++) {
                if (@rename($temporary, $this->path)) {
                    return;
                }

                usleep(10000);
            }
        }

        @unlink($temporary);

        if (@file_put_contents($this->path, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('The installer could not record its progress in '.basename($this->path).'. Check that PHP can write storage/framework.');
        }
    }

    public function delete(): void
    {
        if ($this->path === null) {
            return;
        }

        try {
            @unlink($this->path);
        } catch (Throwable) {
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /** A line of the result page (once; a step done again after an ended request does not repeat it). */
    public function message(string $text): void
    {
        $messages = (array) ($this->data['messages'] ?? []);

        if (! in_array($text, $messages, true)) {
            $messages[] = $text;
        }

        $this->data['messages'] = array_values($messages);
    }

    /** A note for the progress page and the result page (once). */
    public function note(string $text): void
    {
        $notes = (array) ($this->data['notes'] ?? []);

        if (! in_array($text, $notes, true)) {
            $notes[] = $text;
        }

        $this->data['notes'] = array_values($notes);
    }
}
