<?php

namespace App\Support\Updater;

/**
 * The entries of an update zip, read from its central directory (no unpacking: works the same whatever opens the zip
 * later, ZipArchive or PharData), and the guard that decides whether the zip may be unpacked at all.
 *
 * A zip is refused as a whole when any entry is unsafe: an absolute name, "..", a backslash, a drive letter, a NUL or
 * other control character, a symlink, an encrypted entry, a top-level folder that a release does not have, a site
 * secret (.env*, except .env.example; .user.ini), a compiled Laravel cache (bootstrap/cache/*.php), anything under
 * bin/ (the update package never carries the bundled FFmpeg) or a real file under storage/. The empty placeholders
 * under storage/ (.gitkeep, .gitignore, folders) and database/install/ are skipped, not refused.
 */
final class ZipEntries
{
    /** The top-level folders of a release (the update zip is the full package without bin/). */
    public const FOLDERS = [
        'app', 'bootstrap', 'config', 'css', 'database', 'docker', 'docs', 'helpers', 'icons', 'images', 'lang',
        'public', 'resources', 'routes', 'sounds', 'storage', 'vendor',
    ];

    public const MAX_ENTRIES = 40000;

    /** Unpacked size of the whole zip (the update package is about 220 MB) and of one file. */
    public const MAX_TOTAL_BYTES = 1024 * 1024 * 1024;

    public const MAX_FILE_BYTES = 256 * 1024 * 1024;

    private const MAX_DIRECTORY_BYTES = 64 * 1024 * 1024;

    /**
     * Every entry of the zip.
     *
     * @return list<array{name: string, dir: bool, link: bool, size: int, csize: int, method: int, flags: int, crc: int}>
     */
    public static function read(string $zip): array
    {
        $size = @filesize($zip);
        $handle = @fopen($zip, 'rb');

        if ($size === false || $handle === false || $size < 22) {
            throw self::damaged();
        }

        try {
            $tail = min($size, 22 + 65535);
            fseek($handle, $size - $tail);
            $data = (string) fread($handle, $tail);
            $at = strrpos($data, "PK\x05\x06");

            if ($at === false || strlen($data) - $at < 22) {
                throw self::damaged();
            }

            $end = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLength', substr($data, $at, 22));

            if ($end['disk'] !== 0 || $end['cdDisk'] !== 0) {
                throw self::damaged();
            }

            $count = $end['entries'];
            $cdSize = $end['cdSize'];
            $cdOffset = $end['cdOffset'];

            // ZIP64: the real numbers sit in the ZIP64 end record that the locator right before this one points to.
            if ($count === 0xFFFF || $cdSize === 0xFFFFFFFF || $cdOffset === 0xFFFFFFFF) {
                if ($at < 20) {
                    throw self::damaged();
                }

                $locator = unpack('Vsig/Vdisk/Poffset/Vdisks', substr($data, $at - 20, 20));

                if ($locator['sig'] !== 0x07064B50 || $locator['offset'] < 0 || $locator['offset'] > $size - 56) {
                    throw self::damaged();
                }

                fseek($handle, $locator['offset']);
                $record = unpack('Vsig/Psize/vmade/vneed/Vdisk/VcdDisk/PdiskEntries/Pentries/PcdSize/PcdOffset', (string) fread($handle, 56));

                if ($record['sig'] !== 0x06064B50) {
                    throw self::damaged();
                }

                [$count, $cdSize, $cdOffset] = [$record['entries'], $record['cdSize'], $record['cdOffset']];
            }

            if ($count > self::MAX_ENTRIES || $cdSize > self::MAX_DIRECTORY_BYTES || $cdOffset < 0 || $cdOffset + $cdSize > $size) {
                throw self::damaged();
            }

            fseek($handle, $cdOffset);
            $directory = $cdSize > 0 ? (string) fread($handle, $cdSize) : '';
        } finally {
            fclose($handle);
        }

        if (strlen($directory) !== $cdSize) {
            throw self::damaged();
        }

        $entries = [];
        $pos = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($pos + 46 > $cdSize) {
                throw self::damaged();
            }

            $head = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/VcSize/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', substr($directory, $pos, 46));

            if ($head['sig'] !== 0x02014B50 || $pos + 46 + $head['nameLength'] + $head['extraLength'] + $head['commentLength'] > $cdSize) {
                throw self::damaged();
            }

            $name = substr($directory, $pos + 46, $head['nameLength']);
            $extra = substr($directory, $pos + 46 + $head['nameLength'], $head['extraLength']);
            [$fullSize, $packedSize] = self::zip64Sizes($head['size'], $head['cSize'], $extra);
            $system = $head['made'] >> 8;
            $mode = ($head['external'] >> 16) & 0xF000;

            $entries[] = [
                'name' => $name,
                // MS-DOS entries mark folders with the attribute bit 0x10, every writer with a trailing slash.
                'dir' => str_ends_with($name, '/') || ($system === 0 && ($head['external'] & 0x10) !== 0),
                'link' => $system === 3 && $mode === 0xA000,
                'size' => $fullSize,
                'csize' => $packedSize,
                'method' => $head['method'],
                'flags' => $head['flags'],
                'crc' => $head['crc'],
            ];

            $pos += 46 + $head['nameLength'] + $head['extraLength'] + $head['commentLength'];
        }

        return $entries;
    }

    /**
     * The files to unpack (in zip order) and the entries skipped, after the whole zip passed the guard.
     *
     * @param  list<array{name: string, dir: bool, link: bool, size: int, csize: int, method: int, flags: int, crc: int}>  $entries
     * @return array{files: list<string>, skipped: list<string>, size: int}
     */
    public static function guard(array $entries): array
    {
        $files = [];
        $skipped = [];
        $seen = [];
        $total = 0;

        foreach ($entries as $entry) {
            $name = (string) $entry['name'];
            $reason = self::refusal($name, (bool) $entry['dir'])
                ?? ($entry['link'] ? 'a symbolic link' : null)
                ?? (($entry['flags'] & 1) !== 0 ? 'an encrypted entry' : null)
                ?? (! $entry['dir'] && ! in_array($entry['method'], [0, 8], true) ? 'an unsupported compression' : null)
                ?? ($entry['size'] > self::MAX_FILE_BYTES ? 'a file that is too big' : null);

            $key = strtolower(rtrim($name, '/'));

            if ($reason === null && isset($seen[$key])) {
                $reason = 'a name that is in the zip twice';
            }

            if ($reason !== null) {
                throw new UpdateException(__('The update package is not safe to unpack (:entry). Nothing on your site was changed.', [
                    'entry' => self::printable($name),
                ]), 'verify', false, 'refused zip entry: '.$reason);
            }

            $seen[$key] = true;

            if (self::skipped($name, (bool) $entry['dir'])) {
                $skipped[] = $name;

                continue;
            }

            $files[] = $name;
            $total += (int) $entry['size'];
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            throw new UpdateException(__('The update package is not safe to unpack (:entry). Nothing on your site was changed.', [
                'entry' => 'zip',
            ]), 'verify', false, 'more than 1 GB unpacked');
        }

        return ['files' => $files, 'skipped' => $skipped, 'size' => $total];
    }

    /** Why an entry name makes the zip unsafe, or null when it may be in an update package. */
    public static function refusal(string $name, bool $dir = false): ?string
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return 'a control character';
        }

        if (str_contains($name, '\\')) {
            return 'a backslash';
        }

        if (str_starts_with($name, '/')) {
            return 'an absolute path';
        }

        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            return 'a drive letter';
        }

        $parts = explode('/', $dir ? rtrim($name, '/') : $name);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return 'an empty, "." or ".." folder name';
            }
        }

        $leaf = $parts[count($parts) - 1];
        // Never-written names compare without case: where the file system ignores it (Windows, macOS), .ENV is .env.
        $lowerLeaf = strtolower($leaf);
        $lowerName = strtolower($name);

        if (count($parts) === 1 && ! $dir) {
            if (preg_match('/^\.?[A-Za-z0-9][A-Za-z0-9._-]*$/', $leaf) !== 1) {
                return 'an unexpected file at the top';
            }
        } elseif (! in_array($parts[0], self::FOLDERS, true)) {
            return 'a folder a release does not have';
        }

        if ((str_starts_with($lowerLeaf, '.env') && $lowerLeaf !== '.env.example') || $lowerLeaf === '.user.ini') {
            return 'a site settings file';
        }

        if (! $dir && str_starts_with($lowerName, 'bootstrap/cache/') && str_ends_with($lowerName, '.php')) {
            return 'a compiled cache file';
        }

        if (! $dir && $parts[0] === 'storage' && ! in_array($leaf, ['.gitkeep', '.gitignore'], true)) {
            return 'a file inside storage/';
        }

        return null;
    }

    /** Entries the updater never writes: folders (made as needed), the storage placeholders, database/install/. */
    public static function skipped(string $name, bool $dir = false): bool
    {
        $name = strtolower($name);

        return $dir || str_starts_with($name, 'storage/') || str_starts_with($name, 'database/install/');
    }

    /** @return array{0: int, 1: int} the real sizes of an entry whose 32-bit fields point to its ZIP64 extra field */
    private static function zip64Sizes(int $size, int $packed, string $extra): array
    {
        if ($size !== 0xFFFFFFFF && $packed !== 0xFFFFFFFF) {
            return [$size, $packed];
        }

        $pos = 0;

        while ($pos + 4 <= strlen($extra)) {
            $field = unpack('vid/vlength', substr($extra, $pos, 4));

            if ($field['id'] === 0x0001) {
                $data = substr($extra, $pos + 4, $field['length']);
                $at = 0;

                // The ZIP64 field holds only the values whose 32-bit field is full, in this order.
                if ($size === 0xFFFFFFFF && strlen($data) >= $at + 8) {
                    $size = unpack('P', substr($data, $at, 8))[1];
                    $at += 8;
                }

                if ($packed === 0xFFFFFFFF && strlen($data) >= $at + 8) {
                    $packed = unpack('P', substr($data, $at, 8))[1];
                }

                break;
            }

            $pos += 4 + $field['length'];
        }

        return [$size, $packed];
    }

    private static function printable(string $name): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $name);

        return mb_strlen($clean) > 120 ? mb_substr($clean, 0, 117).'...' : $clean;
    }

    private static function damaged(): UpdateException
    {
        return new UpdateException(__('The update package is damaged: its list of files cannot be read. Nothing on your site was changed. Try again.'), 'verify', true);
    }
}
