<?php

namespace Tests\Feature\Updater\Support;

use RuntimeException;

/**
 * Writes zip files in plain PHP (no ext-zip), so the PharData tests also run where ZipArchive is missing, and so a
 * test can make entries a normal zip writer refuses: absolute names, "..", backslashes, NUL bytes, symbolic links,
 * encrypted flags. Entries are written like the release build writes them (MS-DOS "made by", no Unix modes) unless an
 * entry says otherwise.
 */
final class ZipBuilder
{
    /** 2026-10-23 12:00:00 in MS-DOS time and date fields. */
    private const DOS_TIME = 12 << 11;

    private const DOS_DATE = ((2026 - 1980) << 9) | (10 << 5) | 23;

    /** @var list<array{name: string, data: string, method: int, flags: int, system: int, external: int}> */
    private array $entries = [];

    public function file(string $name, string $data, bool $deflate = true): self
    {
        return $this->raw($name, $data, ['method' => $deflate && $data !== '' ? 8 : 0]);
    }

    public function dir(string $name): self
    {
        return $this->raw(rtrim($name, '/').'/', '', ['method' => 0, 'external' => 0x10]);
    }

    /** A Unix symbolic link entry (mode 0120777) whose data is its target. */
    public function symlink(string $name, string $target): self
    {
        return $this->raw($name, $target, ['method' => 0, 'system' => 3, 'external' => (0120777 << 16)]);
    }

    /**
     * Any entry. Options: method (0 stored, 8 deflated), flags (bit 0 = encrypted), system (0 MS-DOS, 3 Unix),
     * external (the external attributes field).
     *
     * @param  array{method?: int, flags?: int, system?: int, external?: int}  $options
     */
    public function raw(string $name, string $data, array $options = []): self
    {
        $this->entries[] = [
            'name' => $name,
            'data' => $data,
            'method' => $options['method'] ?? 8,
            'flags' => $options['flags'] ?? 0,
            'system' => $options['system'] ?? 0,
            'external' => $options['external'] ?? 0,
        ];

        return $this;
    }

    /** Every file of a folder tree (relative names, forward slashes), sorted, as deflated entries. */
    public function tree(string $root, string $prefix = ''): self
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }

        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $this->file($prefix.$file, (string) file_get_contents($root.'/'.$file));
        }

        return $this;
    }

    /** Write the zip; returns its path. */
    public function save(string $path): string
    {
        $out = '';
        $directory = '';

        foreach ($this->entries as $entry) {
            $data = $entry['data'];
            $packed = $entry['method'] === 8 ? (string) gzdeflate($data, 6) : $data;
            $crc = crc32($data);
            $offset = strlen($out);
            $name = $entry['name'];

            $out .= pack('VvvvvvVVVvv', 0x04034B50, 20, $entry['flags'], $entry['method'], self::DOS_TIME, self::DOS_DATE,
                $crc, strlen($packed), strlen($data), strlen($name), 0).$name.$packed;

            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, ($entry['system'] << 8) | 20, 20, $entry['flags'], $entry['method'],
                self::DOS_TIME, self::DOS_DATE, $crc, strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0,
                $entry['external'], $offset).$name;
        }

        $count = count($this->entries);
        $out .= $directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), strlen($out), 0);

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create '.dirname($path));
        }

        if (file_put_contents($path, $out) !== strlen($out)) {
            throw new RuntimeException('Cannot write '.$path);
        }

        return $path;
    }
}
