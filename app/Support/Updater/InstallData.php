<?php

namespace App\Support\Updater;

use PharData;
use Throwable;
use ZipArchive;

/**
 * database/install/example.sql: the data a new install starts with when the installer imports the bundled database.
 * Updates never write database/install/ (Manifest::preserved(): a site may keep its own install data there), so a
 * site updated with one click kept the copy of the release it was installed with, and a new install made later from
 * that folder imported old data (up to 1.2.2: the retired Upload18 key, so no uploads).
 *
 * The one-click update's Finish (Updater::refreshInstallData()) gives a site whose example.sql is still exactly a copy
 * an earlier release shipped (SHIPPED, line endings aside) the copy of this release, read from the update's zip (its
 * SHA-256 was checked against the release's). A copy the owner changed, or one this release does not know, stays as
 * it is. An upload by hand copies the file itself. The copy it replaces goes into the update's file backup first
 * (FinishFiles), so "Undo this update" brings it back.
 */
final class InstallData
{
    public const PATH = 'database/install/example.sql';

    /**
     * SHA-256 of example.sql, with line endings as "\n", as each earlier release shipped it: the community packages up
     * to v7.1, AVDBCMS 1.2.0 and 1.2.1 (the same file), 1.2.2. The copy of this release comes from its own zip.
     */
    public const SHIPPED = [
        'f4d266d09caf20843f6f60c31e99335569490ba021b95f52b0a126a6d3f7b7ed', // community package (first, canary)
        '8c2cab231671dcc97b325516e951c8037e0645e77e229112979dfeae47bdff0b', // community package v2
        '254db92a25d60b8270d6f3d1ded032e0b63d5e232240ad9d9e7092608f643fe5', // community package v3
        '20bf688424a5cb33b5111b48b8bdb181eb1f14e2112a39e18cd069651a09fbe9', // community packages v4, v5, v6
        '375889b539de49a25972d47e7decbf2718f978afcfbf5575824d4db26450826f', // community package v7 (first candidate)
        'dc390ef21e9e057d8d32133e853c3c7a5fb659d93935c80292dae11af9b22048', // community packages v7, v7.1
        '3e938eec8a2dbb17dab7c8204c44171fc610fd4cb1d633f8a1612049232dc136', // AVDBCMS 1.2.0 and 1.2.1
        'a3c3b24acda63fe3ca75ee1d622a18b7e4fa77234887fbc781982a6383ddf829', // AVDBCMS 1.2.2
    ];

    /** A bigger entry is not install data (the file is about 140 KB). */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** Tests: another list of shipped copies. */
    public static ?array $shippedHashes = null;

    /** Tests: read the zip as a host without ZipArchive does (PharData), as the updater can. */
    public static bool $withoutZipArchive = false;

    /** The SHA-256 of a text with its line endings as "\n". */
    public static function hash(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    /** Is this exactly an example.sql an earlier release shipped (line endings aside)? */
    public static function shipped(string $content): bool
    {
        return in_array(self::hash($content), self::$shippedHashes ?? self::SHIPPED, true);
    }

    /**
     * The site's example.sql gets the copy of $zip (the update package) when it is still a copy an earlier release
     * shipped. What happened: replaced, current (it is this release's already), kept (the owner's own, or none at all:
     * a site may have deleted it), missing (the package has none, or not a plausible one), failed (could not be written:
     * the file stays as it was).
     */
    public static function refresh(string $root, string $zip, ?FinishFiles $backup = null): string
    {
        $live = rtrim(str_replace('\\', '/', $root), '/').'/'.self::PATH;

        if (is_link($live) || ! is_file($live)) {
            return 'kept';
        }

        $now = @file_get_contents($live);

        if (! is_string($now)) {
            return 'kept';
        }

        $new = self::fromZip($zip);

        if ($new === null) {
            return 'missing';
        }

        if (self::hash($now) === self::hash($new)) {
            return 'current';
        }

        if (! self::shipped($now)) {
            return 'kept';
        }

        // Next to it first, then one rename: a new install never finds half a file.
        $temp = $live.'.avdbcms-'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temp, $new) !== strlen($new)) {
            @unlink($temp);

            return 'failed';
        }

        @chmod($temp, (@fileperms($live) ?: 0644) & 0777);

        // The copy from before goes into the update's file backup first, so Undo brings it back (judge R3-029).
        if ($backup !== null && ! $backup->keepBefore($root, self::PATH, hash('sha256', $new))) {
            @unlink($temp);

            return 'failed';
        }

        if (! PlanStore::replace($temp, $live)) {
            // Windows: a rename does not replace a file that is open elsewhere.
            @unlink($temp);

            return 'failed';
        }

        return 'replaced';
    }

    /** database/install/example.sql of an update package (ZipArchive, else PharData), or null when it has none. */
    public static function fromZip(string $zip): ?string
    {
        if (! is_file($zip)) {
            return null;
        }

        try {
            $data = null;

            if (class_exists(ZipArchive::class) && ! self::$withoutZipArchive) {
                $archive = new ZipArchive();

                if ($archive->open($zip, ZipArchive::RDONLY) === true) {
                    try {
                        $stat = $archive->statName(self::PATH);

                        if (is_array($stat) && (int) $stat['size'] > 0 && (int) $stat['size'] <= self::MAX_BYTES) {
                            $read = $archive->getFromName(self::PATH);
                            $data = is_string($read) ? $read : null;
                        }
                    } finally {
                        $archive->close();
                    }

                    return self::plausible($data);
                }
            }

            if (class_exists(PharData::class)) {
                $entry = 'phar://'.str_replace('\\', '/', $zip).'/'.self::PATH;

                if (! @is_file($entry) || (int) @filesize($entry) <= 0 || (int) @filesize($entry) > self::MAX_BYTES) {
                    return null;
                }

                $read = @file_get_contents($entry);

                return self::plausible(is_string($read) ? $read : null);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** A database dump of AVDBCMS (its tables), or null. */
    private static function plausible(?string $data): ?string
    {
        return is_string($data) && $data !== '' && str_contains($data, 'CREATE TABLE') && str_contains($data, '`settings`') ? $data : null;
    }
}
