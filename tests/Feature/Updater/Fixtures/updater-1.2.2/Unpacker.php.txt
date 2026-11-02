<?php

namespace App\Support\Updater;

use PharData;
use Throwable;
use ZipArchive;

/**
 * Unpacks an update zip into the staging folder (storage/app/updates/<version>/staging, on the site's disk), never
 * straight over the live files. ZipArchive when the zip extension is there, else PharData (built into PHP; two of the
 * test servers have no zip extension). Entry by entry with streams, so memory stays flat, about 2,000 files or 20
 * seconds per request; each file is hashed while it is written and must match the package's manifest. Files get 0644
 * (artisan 0755) and folders 0755: the zip carries no Unix modes (it is built on Windows).
 */
final class Unpacker
{
    public const MAX_FILES = 2000;

    private const CHUNK = 1024 * 1024;

    private ?ZipArchive $zip = null;

    public function __construct(private readonly string $file, private readonly string $engine)
    {
        if (! in_array($engine, ['ZipArchive', 'PharData'], true)) {
            throw new UpdateException(self::noEngineMessage(), 'unpack', false);
        }
    }

    /** ZipArchive, else PharData, else null (then the update has to be uploaded by hand). */
    public static function engine(): ?string
    {
        return class_exists(ZipArchive::class) ? 'ZipArchive' : (class_exists(PharData::class) ? 'PharData' : null);
    }

    public static function noEngineMessage(): string
    {
        return __('This server cannot open zip files. Upload the update zip with your file manager, then click Finish update on Home.');
    }

    public function __destruct()
    {
        $this->zip?->close();
    }

    /** The package's avdbcms-manifest.json. */
    public function manifest(): Manifest
    {
        $stream = $this->open(Manifest::FILE);

        try {
            $json = (string) stream_get_contents($stream, 32 * 1024 * 1024);
        } finally {
            fclose($stream);
        }

        return Manifest::parse($json);
    }

    /**
     * Write $files[$from...] into $staging, each checked against the manifest (the manifest itself is staged as it
     * is), until $deadline or MAX_FILES files. Returns the index to go on from (count($files) when done).
     *
     * @param  list<string>  $files
     */
    public function stage(array $files, int $from, string $staging, Manifest $manifest, float $deadline, int $max = self::MAX_FILES): int
    {
        $staging = rtrim(str_replace('\\', '/', $staging), '/');
        $count = count($files);
        $done = 0;

        for ($i = $from; $i < $count; $i++) {
            if ($done >= $max || ($done > 0 && microtime(true) >= $deadline)) {
                return $i;
            }

            $name = $files[$i];
            $expected = $name === Manifest::FILE ? null : $manifest->hash($name);

            if ($name !== Manifest::FILE && $expected === null) {
                throw new UpdateException(__('The update package is not complete. Nothing on your site was changed.'), 'unpack', false, $name.' is not in the manifest');
            }

            $this->extract($name, $staging.'/'.$name, $expected);
            $done++;
        }

        return $count;
    }

    /** Write one entry to $target (through a .part file) and check its hash. */
    private function extract(string $name, string $target, ?string $expected): void
    {
        $folder = dirname($target);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw $this->writeFailed($name);
        }

        $in = $this->open($name);
        $out = @fopen($target.'.part', 'wb');

        if ($out === false) {
            fclose($in);

            throw $this->writeFailed($name);
        }

        $context = hash_init('sha256');

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new UpdateException(__('The update package is damaged: :file cannot be read. Nothing on your site was changed. Try again.', ['file' => $name]), 'unpack', true);
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($context, $chunk);

                if (@fwrite($out, $chunk) !== strlen($chunk)) {
                    throw $this->writeFailed($name);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        $hash = hash_final($context);

        if ($expected !== null && ! hash_equals($expected, $hash)) {
            @unlink($target.'.part');

            throw new UpdateException(__('The update package is damaged: :file does not match its checksum. Nothing on your site was changed.', ['file' => $name]), 'unpack', false);
        }

        if (is_file($target)) {
            @unlink($target);
        }

        if (! @rename($target.'.part', $target)) {
            @unlink($target.'.part');

            throw $this->writeFailed($name);
        }

        // chmod() may be in disable_functions (then it does not exist and calling it would throw).
        if (function_exists('chmod')) {
            @chmod($target, basename($name) === 'artisan' ? 0755 : 0644);
        }
    }

    /** @return resource a stream of one entry */
    private function open(string $name)
    {
        try {
            if ($this->engine === 'ZipArchive') {
                $stream = $this->archive()->getStream($name);
            } else {
                $stream = @fopen('phar://'.str_replace('\\', '/', $this->file).'/'.$name, 'rb');
            }
        } catch (Throwable) {
            $stream = false;
        }

        if (! is_resource($stream)) {
            throw new UpdateException(__('The update package is damaged: :file cannot be read. Nothing on your site was changed. Try again.', ['file' => $name]), 'unpack', true);
        }

        return $stream;
    }

    private function archive(): ZipArchive
    {
        if ($this->zip === null) {
            $zip = new ZipArchive();

            if ($zip->open($this->file, ZipArchive::RDONLY) !== true) {
                throw new UpdateException(__('The update package is damaged: its list of files cannot be read. Nothing on your site was changed. Try again.'), 'unpack', true);
            }

            $this->zip = $zip;
        }

        return $this->zip;
    }

    private function writeFailed(string $name): UpdateException
    {
        return new UpdateException(__('Could not write :file while unpacking. The disk may be full. Nothing on your site was changed.', ['file' => $name]), 'unpack', true);
    }
}
