<?php

namespace App\Support\Upload18;

use RuntimeException;

/**
 * @internal Thrown inside UploadClaims::createFinished() when the same upload was finished twice at the same moment: the
 * transaction of the second one is rolled back and the first video is answered.
 */
final class ExistingUpload extends RuntimeException
{
    public function __construct(public readonly int $videoId)
    {
        parent::__construct('existing upload');
    }
}
