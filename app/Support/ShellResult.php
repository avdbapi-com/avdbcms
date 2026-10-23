<?php

namespace App\Support;

/**
 * Structured outcome of a ShellRunner call.
 *
 * `unavailable = true` means the host refused subprocess execution
 * (typical on locked-down shared hosts where proc_open / exec are
 * in `disable_functions`).
 */
final readonly class ShellResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $unavailable,
        public ?string $reason,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(
            exitCode: -1,
            stdout: '',
            stderr: $reason,
            unavailable: true,
            reason: $reason,
        );
    }

    public function successful(): bool
    {
        return ! $this->unavailable && $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function output(): string
    {
        if ($this->stdout !== '' && $this->stderr !== '') {
            return $this->stdout . "\n" . $this->stderr;
        }

        return $this->stdout !== '' ? $this->stdout : $this->stderr;
    }
}
