<?php

namespace App\Support\Updater;

use RuntimeException;
use Throwable;

/**
 * A reason the updater stops, already worded for the administrator (translated where it was thrown). $step names
 * where it stopped (download, verify, unpack, database, prepare, swap, finish), $retry says whether "Try again" can
 * help (a dropped connection) or not (a damaged package, PHP too old); $detail is a technical note for the step log.
 */
final class UpdateException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $step = 'update',
        public readonly bool $retry = true,
        public readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
