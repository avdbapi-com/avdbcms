<?php

namespace App\Support\Upload18;

use RuntimeException;

/**
 * Upload18 did not give a usable answer (no connection, an HTTP error, an empty or non-JSON body). Its message names
 * the HTTP status and Upload18's error code at most: never a key, a token or a member's data.
 */
final class Upload18Unavailable extends RuntimeException
{
}
