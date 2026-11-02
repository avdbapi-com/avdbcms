<?php

namespace App\Helpers;

use App\Models\Video;
use App\Support\LocalNumber;

class Number
{
    /** Draws of unique() before it keeps the last one (a draw is a video's link already at odds of videos : 10^10). */
    private const UNIQUE_TRIES = 8;

    /**
     * The cards' short count ("18.8 k", "2.5 M", "999") in the reader's language (R1-063: "18,8 k" in French, "18.8 ألف"
     * in Arabic, where "18.8 k" read "k 18.8"): App\Support\LocalNumber::compact. No trailing space under 1,000 ('1 ' made
     * '1  subscriber' in trans_choice texts, channel-19 / watch-17).
     */
    public static function humanize(int $number): string {
        return LocalNumber::compact($number);
    }

    /**
     * Ten digits: the link token of a video a member uploads (/video/{slug}-{uuid}, Video::UUID_PATTERN; the upload
     * code names its drafts with it), also a slug's and a generated picture's suffix. It was a shuffle of the ten digits
     * of the current Unix time - at most 10! orders per second of upload time, about 10^4-10^5 with the repeated digits,
     * from PHP's non-cryptographic generator - so whoever knew roughly when a video was uploaded could try its unlisted
     * link (owner question SV-RV-04, round 3). Now ten digits from the system's cryptographic generator (10^10 values),
     * never one that an existing video, a deleted one too, already has as its link (one look-up on the indexed column).
     */
    public static function unique(): string {
        $token = '';

        for ($try = 0; $try < self::UNIQUE_TRIES; $try++) {
            $token = '';

            for ($digit = 0; $digit < 10; $digit++) {
                $token .= (string) random_int(0, 9);
            }

            if (! self::takenAsVideoLink($token)) {
                break;
            }
        }

        return $token;
    }

    /** Whether a video (a deleted one too) already has the token as its link; without a database nothing has. */
    public static function takenAsVideoLink(string $token): bool {
        try {
            return Video::withoutGlobalScopes()->where('uuid', $token)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
