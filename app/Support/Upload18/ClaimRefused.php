<?php

namespace App\Support\Upload18;

use RuntimeException;

/**
 * An Upload18 upload the site will not attach to this member: another member's video (claimed), a video Upload18 handed
 * back instead of the file (duplicate), or one that is not the file the browser sent (mismatch, vid_mismatch). The
 * answer carries UploadClaims::message() and the reason as "code".
 */
final class ClaimRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status)
    {
        parent::__construct($reason);
    }

    public function text(): string
    {
        return UploadClaims::message($this->reason);
    }

    /** @return array{message: string, code: string} */
    public function payload(): array
    {
        return ['message' => $this->text(), 'code' => $this->reason];
    }
}
