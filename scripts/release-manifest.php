<?php

/*
|--------------------------------------------------------------------------
| Release manifest and checksums (run by scripts/build-community-release.ps1)
|--------------------------------------------------------------------------
|
| Plain PHP (no Laravel boot). Every command reads the AVDBCMS code of the folder given with --stage (the build's
| staging folder, or a project root): app/Support/AppVersion.php and the updater classes of app/Support/Updater
| (loaded from DIR/app on demand), so a package is checked by the same code that unpacks it on a site (Tools > Updates).
|
|   version  --stage=DIR [--tag=vX.Y.Z]
|       Prints AppVersion::CURRENT. With --tag it fails unless the tag is exactly "v" + CURRENT.
|   manifest --stage=DIR [--previous=FILE] [--requires-php=8.3.0] [--min-from=1.2.0]
|       Writes DIR/avdbcms-manifest.json {product, version, requires_php, min_from, built_at, files, removed}:
|       files = path => SHA-256 of every file of the stage except bin/, storage/, database/install/ and the manifest
|       itself; removed = the paths the previous release's manifest names (its files and its own removed list) that
|       this release does not ship, so a site that skipped a release loses them too. FILE = the previous release's
|       avdbcms-manifest.json, or one of its zips. Fails first when the updater would refuse the update zip of this
|       stage (ZipEntries::refusal, Manifest::safePath); the written file is read back through Manifest::parse().
|   verify   --stage=DIR --zip=FILE --kind=full|update [--engine=all|auto|ZipArchive|PharData]
|       The zip against the stage: the same files and empty folders (update: without bin/) with the same sizes and
|       CRC-32; entry names the updater accepts (update: the whole ZipEntries guard and the updater's "every file of
|       the zip is in the manifest and the other way round" rule); the manifest equal to the staged one; every listed
|       file read back through ZipArchive and/or PharData (both with --engine=all, the default) with the SHA-256 the
|       manifest gives.
|   sha256   --stage=DIR --zip=FILE
|       FILE.sha256 must be exactly "<64 lower-case hex>  <zip name>" and a line feed (sha256sum format), accepted for
|       that name by the updater's Checksum::parse(), and its hash must be the zip's SHA-256.
|   accepts  --stage=DIR --zip=FILE
|       Would the updater of the AVDBCMS version in DIR (the previous release's code, unpacked by the build from its
|       zip) take FILE as an update? Sites unpack an update with the guard of the version they run, not the new one:
|       its ZipEntries guard, its Manifest::parse() of the new manifest, its "every file is in the manifest" rule must
|       pass. A min_from above that version is only reported (WARNING line): such a release cannot be one-click
|       installed over it, which may be meant.
|
| Exit 0 = OK, 1 = a check failed (the reason on STDERR), 2 = usage error.
|
*/

namespace Avd\Release {

use App\Support\AppVersion;
use App\Support\Updater\Checksum;
use App\Support\Updater\Manifest;
use App\Support\Updater\UpdateException;
use App\Support\Updater\ZipEntries;
use RuntimeException;
use Throwable;
use ZipArchive;

/** A check failed: the build stops with this reason. */
final class Failed extends RuntimeException
{
}

/** The command line is wrong. */
final class Usage extends RuntimeException
{
}

/**
 * The AVDBCMS classes of the tree being packaged, loaded on demand from its app/ folder (App\ namespace only; no
 * vendor/). They need nothing from Laravel except __() for their messages, which this script defines (end of file).
 */
final class Code
{
    public const APP_VERSION = 'App\\Support\\AppVersion';

    public const CHECKSUM = 'App\\Support\\Updater\\Checksum';

    /** The classes the commands use. */
    private const CLASSES = [
        self::APP_VERSION,
        'App\\Support\\Updater\\UpdateException',
        'App\\Support\\Updater\\Manifest',
        'App\\Support\\Updater\\ZipEntries',
        self::CHECKSUM,
    ];

    private static ?string $root = null;

    /** @param array<int, string> $only the classes needed; empty = all of them */
    public static function load(string $stage, array $only = []): void
    {
        if (self::$root === null) {
            self::$root = $stage;
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'App\\')) {
                    $file = self::$root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            });
        }
        foreach ($only !== [] ? $only : self::CLASSES as $class) {
            if (!class_exists($class)) {
                $file = 'app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                throw new Failed("{$file} is missing in {$stage} (or does not define {$class}): only AVDBCMS 1.2.0 or newer can be packaged by this build.");
            }
        }
    }

    /** AppVersion::CURRENT of the loaded tree. */
    public static function version(): string
    {
        $version = AppVersion::CURRENT;
        if (!AppVersion::isVersion($version)) {
            throw new Failed("AppVersion::CURRENT ({$version}) is not a plain X.Y.Z version.");
        }

        return $version;
    }
}

/** The files and empty folders of a tree, as the zip writer of the build sees them. */
final class Tree
{
    /**
     * Every file (relative path => absolute path) and every empty folder under $root, with "/" separators, sorted by
     * byte order. A link (symlink, junction) stops the build: a release holds real files only.
     *
     * @return array{files: array<string, string>, empty: array<int, string>}
     */
    public static function scan(string $root): array
    {
        $files = [];
        $empty = [];
        $pending = [''];
        while ($pending !== []) {
            $rel = array_pop($pending);
            $names = @scandir($rel === '' ? $root : $root . '/' . $rel);
            if ($names === false) {
                throw new Failed('Cannot read the folder ' . ($rel === '' ? $root : $rel) . '.');
            }
            $names = array_values(array_diff($names, ['.', '..']));
            if ($names === [] && $rel !== '') {
                $empty[] = $rel;
                continue;
            }
            foreach ($names as $name) {
                $path = $rel === '' ? $name : $rel . '/' . $name;
                $abs = $root . '/' . $path;
                if (self::isLink($abs)) {
                    throw new Failed("{$path} is a link: a release holds real files only.");
                }
                if (is_dir($abs)) {
                    $pending[] = $path;
                } elseif (is_file($abs)) {
                    $files[$path] = $abs;
                } else {
                    throw new Failed("{$path} is neither a file nor a folder.");
                }
            }
        }
        ksort($files, SORT_STRING);
        sort($empty, SORT_STRING);

        return ['files' => $files, 'empty' => $empty];
    }

    /** On Windows a junction is not is_link(), and readlink() answers a plain file or folder with its own path. */
    public static function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        $target = @readlink($path);

        return is_string($target) && self::key($target) !== self::key($path);
    }

    private static function key(string $path): string
    {
        $path = str_replace('/', '\\', $path);
        if (str_starts_with($path, '\\\\?\\')) {
            $path = substr($path, 4);
        }

        return strtolower(rtrim($path, '\\'));
    }
}

/** avdbcms-manifest.json of a package (the format App\Support\Updater\Manifest reads). */
final class Manifests
{
    public const FILE = 'avdbcms-manifest.json';

    /** bin/: the bundled FFmpeg, never in the update zip (the build leaves out every path whose first part is "bin"). */
    public static function inBin(string $path): bool
    {
        return $path === 'bin' || str_starts_with($path, 'bin/');
    }

    /**
     * Whether a file of the package is listed under "files": not the manifest itself, not bin/, and not the storage/
     * placeholders and database/install/ the updater skips (ZipEntries::skipped).
     */
    public static function listed(string $path): bool
    {
        return $path !== self::FILE && !self::inBin($path)
            && !str_starts_with($path, 'storage/') && !str_starts_with($path, 'database/install/');
    }

    /**
     * Why the updater would refuse the update zip of $tree (the tree without bin/): an entry ZipEntries refuses, a
     * listed file Manifest::safePath() refuses, a name that is not UTF-8. At most $max reasons.
     *
     * @param array{files: array<string, string>, empty: array<int, string>} $tree
     * @return array<int, string>
     */
    public static function refusals(array $tree, int $max = 20): array
    {
        $entries = [];
        foreach (array_keys($tree['files']) as $path) {
            $entries[] = [(string) $path, false];
        }
        foreach ($tree['empty'] as $path) {
            $entries[] = [$path . '/', true];
        }
        $why = [];
        foreach ($entries as [$name, $dir]) {
            $path = rtrim($name, '/');
            if (self::inBin($path)) {
                continue;
            }
            if (preg_match('//u', $name) !== 1) {
                $why[] = "{$path}: not a UTF-8 name";
            } elseif (($reason = ZipEntries::refusal($name, $dir)) !== null) {
                $why[] = "{$path}: {$reason}";
            } elseif (!$dir && self::listed($path) && !Manifest::safePath($path)) {
                $why[] = "{$path}: a path an update never writes (site settings, compiled caches, upload folders)";
            }
            if (count($why) >= $max) {
                break;
            }
        }

        return $why;
    }

    /**
     * The manifest of $tree. removed = what the previous manifest names (files + removed) that this one does not ship
     * and the updater may still delete (Manifest::safePath).
     *
     * @param array{files: array<string, string>, empty: array<int, string>} $tree
     * @return array{product: string, version: string, requires_php: string, min_from: string, built_at: string, files: array<string, string>, removed: array<int, string>}
     */
    public static function build(array $tree, string $version, string $requiresPhp, string $minFrom, ?Manifest $previous, string $builtAt): array
    {
        $files = [];
        foreach ($tree['files'] as $path => $abs) {
            $path = (string) $path;
            if (!self::listed($path)) {
                continue;
            }
            $hash = @hash_file('sha256', $abs);
            if (!is_string($hash)) {
                throw new Failed("Cannot read {$path}.");
            }
            $files[$path] = $hash;
        }
        if ($files === []) {
            throw new Failed('The stage has no file a manifest would list.');
        }

        $removed = [];
        if ($previous !== null) {
            foreach (array_merge(array_keys($previous->files), $previous->removed) as $path) {
                $path = (string) $path;
                if (!isset($files[$path]) && $path !== self::FILE && Manifest::safePath($path)) {
                    $removed[$path] = true;
                }
            }
        }
        $removed = array_map('strval', array_keys($removed));
        sort($removed, SORT_STRING);

        return [
            'product' => AppVersion::PRODUCT,
            'version' => $version,
            'requires_php' => $requiresPhp,
            'min_from' => $minFrom,
            'built_at' => $builtAt,
            'files' => $files,
            'removed' => $removed,
        ];
    }

    /** Pretty-printed like the lang files (4 spaces, unescaped slashes and UTF-8), keys in build() order. */
    public static function encode(array $data): string
    {
        $data['files'] = (object) $data['files'];
        $data['removed'] = array_values($data['removed']);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Write the manifest into $stage (through a temporary file) and read it back the way the updater does. */
    public static function write(string $stage, array $data): Manifest
    {
        $json = self::encode($data);
        $target = $stage . '/' . self::FILE;
        if (is_dir($target)) {
            throw new Failed("{$target} is a folder.");
        }
        $tmp = $target . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new Failed("Cannot write {$target}.");
        }
        $manifest = Manifest::parse((string) file_get_contents($target));
        if ($manifest->version !== $data['version'] || $manifest->files !== $data['files'] || $manifest->removed !== $data['removed']) {
            throw new Failed("{$target} does not read back as it was written.");
        }

        return $manifest;
    }

    /** The manifest of the previous release: its avdbcms-manifest.json, or the one inside one of its zips. */
    public static function previous(string $file): Manifest
    {
        if (!is_file($file)) {
            throw new Failed("No previous manifest at {$file}.");
        }
        if (strtolower(substr($file, -4)) === '.zip') {
            $json = (new Reader($file, Zips::engines('auto')[0]))->read(self::FILE);
            if ($json === null) {
                throw new Failed("{$file} has no readable " . self::FILE . ' at its root.');
            }
        } else {
            $json = (string) file_get_contents($file);
        }
        try {
            return Manifest::parse($json);
        } catch (UpdateException $e) {
            throw new Failed("The previous manifest ({$file}) is not valid: " . ($e->detail ?? $e->getMessage()));
        }
    }
}

/** One open zip, read entry by entry the way the updater's Unpacker does (ZipArchive::getStream, or phar://). */
final class Reader
{
    private ?ZipArchive $zip = null;

    public function __construct(private string $file, public string $engine)
    {
        if ($engine === 'ZipArchive') {
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new Failed('ZipArchive cannot open ' . basename($file) . '.');
            }
            $this->zip = $zip;
        }
    }

    public function __destruct()
    {
        $this->zip?->close();
    }

    /** @return resource|null */
    public function open(string $name)
    {
        try {
            $stream = $this->zip !== null
                ? $this->zip->getStream($name)
                : @fopen('phar://' . str_replace('\\', '/', $this->file) . '/' . $name, 'rb');
        } catch (Throwable) {
            $stream = false;
        }

        return is_resource($stream) ? $stream : null;
    }

    /** SHA-256 of one entry as this engine unpacks it, or null when it cannot be read. */
    public function hash(string $name): ?string
    {
        $stream = $this->open($name);
        if ($stream === null) {
            return null;
        }
        try {
            $context = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    return null;
                }
                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /** The content of one entry (at most $max bytes), or null. */
    public function read(string $name, int $max = 33554432): ?string
    {
        $stream = $this->open($name);
        if ($stream === null) {
            return null;
        }
        try {
            $data = stream_get_contents($stream, $max + 1);
        } finally {
            fclose($stream);
        }

        return is_string($data) && strlen($data) <= $max ? $data : null;
    }
}

/** The checks of a built zip. */
final class Zips
{
    /**
     * The engines to read with: all of ZipArchive and PharData this PHP has (the updater takes ZipArchive when it can,
     * else PharData, so a release must read the same through both), the first one, or the one named.
     *
     * @return array<int, string>
     */
    public static function engines(string $wanted): array
    {
        $have = array_values(array_filter(['ZipArchive', 'PharData'], static fn (string $class) => class_exists($class)));
        if ($have === []) {
            throw new Failed('This PHP can open zip files neither with ZipArchive nor with PharData.');
        }
        if ($wanted === 'all') {
            return $have;
        }
        if ($wanted === 'auto') {
            return [$have[0]];
        }
        if (!in_array($wanted, ['ZipArchive', 'PharData'], true)) {
            throw new Usage("--engine must be all, auto, ZipArchive or PharData, not {$wanted}.");
        }
        if (!in_array($wanted, $have, true)) {
            throw new Failed("{$wanted} is not available in this PHP.");
        }

        return [$wanted];
    }

    /**
     * @param array<int, string> $engines
     * @return array{files: int, dirs: int, bin: int, listed: int, skipped: int, engines: array<int, string>}
     */
    public static function verify(string $stage, string $zip, string $kind, array $engines): array
    {
        $label = basename($zip);
        $entries = ZipEntries::read($zip);
        $files = [];
        $dirs = [];
        $seen = [];
        $bin = 0;
        foreach ($entries as $entry) {
            $name = (string) $entry['name'];
            $path = rtrim($name, '/');
            if (Manifests::inBin($path)) {
                $bin++;
                if ($kind === 'update') {
                    throw new Failed("{$label} has {$name}: the update zip must not carry bin/.");
                }
                // bin/ is no folder the updater takes; the rest of the name must pass its rules all the same.
                $why = ZipEntries::refusal('app' . substr($name, 3), (bool) $entry['dir']);
            } else {
                $why = ZipEntries::refusal($name, (bool) $entry['dir']);
            }
            if ($why === null && $entry['link']) {
                $why = 'a symbolic link';
            }
            if ($why === null && isset($seen[strtolower($path)])) {
                $why = 'a name that is in the zip twice';
            }
            if ($why !== null) {
                throw new Failed("{$label}: {$name}: {$why}.");
            }
            $seen[strtolower($path)] = true;
            if ($entry['dir']) {
                $dirs[$path] = true;
            } else {
                $files[$path] = $entry;
            }
        }

        // The zip holds exactly the staged tree (update: without bin/), byte for byte (size + CRC-32).
        $tree = Tree::scan($stage);
        $want = $tree['files'];
        $wantDirs = array_fill_keys($tree['empty'], true);
        if ($kind === 'update') {
            $want = array_filter($want, static fn (string $path) => !Manifests::inBin($path), ARRAY_FILTER_USE_KEY);
            $wantDirs = array_filter($wantDirs, static fn (string $path) => !Manifests::inBin($path), ARRAY_FILTER_USE_KEY);
        }
        self::same($label, 'files', $want, $files);
        self::same($label, 'empty folders', $wantDirs, $dirs);
        foreach ($want as $path => $abs) {
            if ($files[$path]['size'] !== filesize($abs) || sprintf('%08x', $files[$path]['crc']) !== hash_file('crc32b', $abs)) {
                throw new Failed("{$label}: {$path} differs from the staged file.");
            }
        }

        // The manifest: the staged one, naming exactly the files the updater takes from the zip.
        if (!isset($files[Manifests::FILE])) {
            throw new Failed("{$label} has no " . Manifests::FILE . ' at its root.');
        }
        $staged = (string) @file_get_contents($stage . '/' . Manifests::FILE);
        $manifest = Manifest::parse($staged);
        $version = Code::version();
        if ($manifest->version !== $version) {
            throw new Failed("{$label}: the manifest says {$manifest->version}, the code says {$version}.");
        }
        $listed = array_fill_keys(array_filter(array_keys($files), static fn ($path) => Manifests::listed((string) $path)), true);
        $skipped = 0;
        if ($kind === 'update') {
            // What the updater does with this zip (Updater::verify): the guard, then guarded files minus the manifest
            // must be the manifest's files, nothing more and nothing less.
            $guard = ZipEntries::guard($entries);
            $listed = array_fill_keys($guard['files'], true);
            unset($listed[Manifests::FILE]);
            $skipped = count($guard['skipped']);
        }
        self::same($label, 'files of the manifest', $manifest->files, $listed);

        foreach ($engines as $engine) {
            $reader = new Reader($zip, $engine);
            if ($reader->read(Manifests::FILE) !== $staged) {
                throw new Failed("{$label}: {$engine} reads another " . Manifests::FILE . ' than the staged one.');
            }
            foreach ($manifest->files as $path => $hash) {
                $actual = $reader->hash((string) $path);
                if ($actual === null) {
                    throw new Failed("{$label}: {$engine} cannot read {$path}.");
                }
                if (!hash_equals($hash, $actual)) {
                    throw new Failed("{$label}: {$engine} unpacks {$path} with SHA-256 {$actual}; the manifest says {$hash}.");
                }
            }
            unset($reader);
        }

        return [
            'files' => count($files),
            'dirs' => count($dirs),
            'bin' => $bin,
            'listed' => count($manifest->files),
            'skipped' => $skipped,
            'engines' => $engines,
        ];
    }

    /** Both sides name the same paths (the values do not matter); else the first differences. */
    public static function same(string $label, string $what, array $expected, array $actual): void
    {
        $missing = array_keys(array_diff_key($expected, $actual));
        $extra = array_keys(array_diff_key($actual, $expected));
        if ($missing === [] && $extra === []) {
            return;
        }
        $show = static fn (array $paths) => implode(', ', array_slice(array_map('strval', $paths), 0, 10)) . (count($paths) > 10 ? ', ...' : '');
        throw new Failed("{$label}: the {$what} differ"
            . ($missing !== [] ? '; missing in the zip (' . count($missing) . '): ' . $show($missing) : '')
            . ($extra !== [] ? '; not expected (' . count($extra) . '): ' . $show($extra) : '') . '.');
    }
}

/** The .sha256 asset of a zip. */
final class Checksums
{
    /** The hash of $zip after its .sha256 file passed every check. */
    public static function check(string $zip): string
    {
        $name = basename($zip);
        $text = @file_get_contents($zip . '.sha256');
        if (!is_string($text)) {
            throw new Failed("{$name}.sha256 is missing.");
        }
        $hash = Checksum::parse($text, $name);
        if ($hash === null) {
            throw new Failed("{$name}.sha256 is not a sha256sum line for {$name} (the updater's Checksum::parse() refuses it).");
        }
        $actual = Checksum::file($zip);
        if (!Checksum::matches($hash, $actual)) {
            throw new Failed("{$name}.sha256 says {$hash}, but the SHA-256 of the zip is {$actual}.");
        }
        if ($text !== $actual . '  ' . $name . "\n") {
            throw new Failed("{$name}.sha256 must be exactly \"<64 lower-case hex>  {$name}\" and one line feed (sha256sum format).");
        }

        return $actual;
    }
}

final class Cli
{
    private const COMMANDS = [
        'version' => ['stage', 'tag'],
        'manifest' => ['stage', 'previous', 'requires-php', 'min-from'],
        'verify' => ['stage', 'zip', 'kind', 'engine'],
        'sha256' => ['stage', 'zip'],
        'accepts' => ['stage', 'zip'],
    ];

    private const USAGE = <<<'TXT'
usage: php scripts/release-manifest.php version  --stage=DIR [--tag=vX.Y.Z]
       php scripts/release-manifest.php manifest --stage=DIR [--previous=FILE] [--requires-php=8.3.0] [--min-from=1.2.0]
       php scripts/release-manifest.php verify   --stage=DIR --zip=FILE --kind=full|update [--engine=all|auto|ZipArchive|PharData]
       php scripts/release-manifest.php sha256   --stage=DIR --zip=FILE
       php scripts/release-manifest.php accepts  --stage=DIR --zip=FILE

TXT;

    /** @param array<int, string> $argv */
    public static function main(array $argv): int
    {
        try {
            if (PHP_VERSION_ID < 80300) {
                throw new Failed('scripts/release-manifest.php needs PHP 8.3 or newer (this is ' . PHP_VERSION . ').');
            }
            [$command, $opt] = self::parse($argv);
            $stage = self::folder($opt['stage'] ?? null);

            switch ($command) {
                case 'version':
                    return self::version($stage, $opt);
                case 'manifest':
                    return self::manifest($stage, $opt);
                case 'verify':
                    return self::verify($stage, $opt);
                case 'accepts':
                    return self::accepts($stage, $opt);
                default:
                    return self::sha256($stage, $opt);
            }
        } catch (Usage $e) {
            fwrite(STDERR, 'release-manifest: ' . $e->getMessage() . PHP_EOL . self::USAGE);

            return 2;
        } catch (UpdateException $e) {
            // The updater's own wording, plus its technical note.
            fwrite(STDERR, 'release-manifest: ' . $e->getMessage() . ($e->detail !== null ? ' [' . $e->detail . ']' : '') . PHP_EOL);

            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, 'release-manifest: ' . ($e instanceof Failed ? '' : get_class($e) . ': ') . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private static function version(string $stage, array $opt): int
    {
        Code::load($stage, [Code::APP_VERSION]);
        $version = Code::version();
        if (array_key_exists('tag', $opt) && $opt['tag'] !== 'v' . $version) {
            throw new Failed("The tag \"{$opt['tag']}\" is not \"v{$version}\" (AppVersion::CURRENT of {$stage}). Tag the release v{$version},"
                . ' or set AppVersion::CURRENT to the version you mean and build again.');
        }
        echo $version, PHP_EOL;

        return 0;
    }

    private static function manifest(string $stage, array $opt): int
    {
        Code::load($stage);
        $version = Code::version();
        $requiresPhp = self::versionOption($opt, 'requires-php', '8.3.0');
        $minFrom = self::versionOption($opt, 'min-from', '1.2.0');
        if (version_compare($minFrom, $version, '>')) {
            throw new Failed("--min-from={$minFrom} is newer than this release ({$version}).");
        }
        $previous = null;
        if (array_key_exists('previous', $opt)) {
            $previous = Manifests::previous($opt['previous']);
            if (!version_compare($previous->version, $version, '<')) {
                throw new Failed("The previous manifest is AVDBCMS {$previous->version}, which is not older than {$version}.");
            }
        }

        $tree = Tree::scan($stage);
        $refusals = Manifests::refusals($tree);
        if ($refusals !== []) {
            throw new Failed("The updater would refuse the update zip of this stage:\n  " . implode("\n  ", $refusals));
        }
        $data = Manifests::build($tree, $version, $requiresPhp, $minFrom, $previous, gmdate('Y-m-d\TH:i:s\Z'));
        $manifest = Manifests::write($stage, $data);

        $bytes = 0;
        foreach (array_keys($manifest->files) as $path) {
            $bytes += (int) filesize($tree['files'][$path]);
        }
        printf(
            "%s: AVDBCMS %s (PHP %s or newer, installs over %s or newer), %d files (%.1f MB), %d removed%s\n",
            Manifests::FILE,
            $manifest->version,
            $manifest->requiresPhp,
            $manifest->minFrom,
            count($manifest->files),
            $bytes / 1048576,
            count($manifest->removed),
            $previous !== null ? ' since ' . $previous->version : ''
        );

        // A file renamed by case only (SEOController.php -> SeoController.php): the updater replaces it in place, and
        // where the file system tells case apart the old name stays behind, unused. Worth a look before publishing.
        if ($previous !== null) {
            $before = [];
            foreach (array_keys($previous->files) as $path) {
                $before[strtolower((string) $path)] = (string) $path;
            }
            foreach (array_keys($manifest->files) as $path) {
                $old = $before[strtolower((string) $path)] ?? null;
                if ($old !== null && $old !== $path) {
                    echo 'WARNING: ', $path, ' was ', $old, ' in AVDBCMS ', $previous->version,
                        ' (renamed by case only): sites whose file system tells case apart keep the old file, unused.', PHP_EOL;
                }
            }
        }

        return 0;
    }

    private static function verify(string $stage, array $opt): int
    {
        $zip = self::file($opt['zip'] ?? null, '--zip');
        $kind = $opt['kind'] ?? '';
        if (!in_array($kind, ['full', 'update'], true)) {
            throw new Usage('--kind must be full or update.');
        }
        Code::load($stage);
        $result = Zips::verify($stage, $zip, $kind, Zips::engines($opt['engine'] ?? 'all'));
        printf(
            "%s (%s): %d files and %d empty folders, as staged%s; %d files match %s through %s%s\n",
            basename($zip),
            $kind,
            $result['files'],
            $result['dirs'],
            $kind === 'full' ? ' (' . $result['bin'] . ' entries under bin/)' : ', no bin/',
            $result['listed'],
            Manifests::FILE,
            implode(' and ', $result['engines']),
            $kind === 'update' ? '; the updater skips ' . $result['skipped'] . ' placeholders' : ''
        );

        return 0;
    }

    private static function sha256(string $stage, array $opt): int
    {
        $zip = self::file($opt['zip'] ?? null, '--zip');
        Code::load($stage, [Code::CHECKSUM]);
        $hash = Checksums::check($zip);
        echo basename($zip), '.sha256: ', $hash, '  ', basename($zip), ' (matches the zip)', PHP_EOL;

        return 0;
    }

    private static function accepts(string $stage, array $opt): int
    {
        $zip = self::file($opt['zip'] ?? null, '--zip');
        Code::load($stage, [Code::APP_VERSION, 'App\\Support\\Updater\\UpdateException', 'App\\Support\\Updater\\Manifest', 'App\\Support\\Updater\\ZipEntries']);
        $installed = Code::version();
        $label = basename($zip);

        // What Updater::verify() of that version does with the zip.
        $guard = ZipEntries::guard(ZipEntries::read($zip));
        $json = (new Reader($zip, Zips::engines('auto')[0]))->read(Manifests::FILE);
        if ($json === null) {
            throw new Failed("{$label} has no " . Manifests::FILE . ' at its root.');
        }
        $manifest = Manifest::parse($json);
        $inZip = array_fill_keys($guard['files'], true);
        unset($inZip[Manifests::FILE]);
        Zips::same($label, "files of the manifest (as AVDBCMS {$installed} reads them)", $manifest->files, $inZip);
        if (!version_compare($manifest->version, $installed, '>')) {
            throw new Failed("{$label} is AVDBCMS {$manifest->version}, which is not newer than {$installed}.");
        }

        printf("%s: the updater of AVDBCMS %s takes it (%d files, %d placeholders skipped)\n", $label, $installed, count($guard['files']), count($guard['skipped']));
        try {
            // PHP is the site's matter here: only min_from and the version order are checked.
            $manifest->assertInstallable($installed, $manifest->requiresPhp);
        } catch (UpdateException $e) {
            echo 'WARNING: sites on AVDBCMS ', $installed, ' cannot install this update with one click: ', $e->getMessage(), PHP_EOL;
        }

        return 0;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function parse(array $argv): array
    {
        $args = array_slice($argv, 1);
        $command = (string) array_shift($args);
        if (!isset(self::COMMANDS[$command])) {
            throw new Usage($command === '' ? 'No command given.' : "Unknown command {$command}.");
        }
        $opt = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)=(.*)$/s', (string) $arg, $m) !== 1 || !in_array($m[1], self::COMMANDS[$command], true)) {
                throw new Usage("Unknown option {$arg} for {$command}.");
            }
            $opt[$m[1]] = $m[2];
        }

        return [$command, $opt];
    }

    private static function folder(?string $value): string
    {
        if ($value === null || $value === '') {
            throw new Usage('--stage=DIR is required.');
        }
        $real = realpath($value);
        if ($real === false || !is_dir($real)) {
            throw new Failed("No such folder: {$value}");
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    private static function file(?string $value, string $option): string
    {
        if ($value === null || $value === '') {
            throw new Usage("{$option}=FILE is required.");
        }
        $real = realpath($value);
        if ($real === false || !is_file($real)) {
            throw new Failed("No such file: {$value}");
        }

        return str_replace('\\', '/', $real);
    }

    private static function versionOption(array $opt, string $name, string $default): string
    {
        $value = $opt[$name] ?? $default;
        if (!AppVersion::isVersion($value)) {
            throw new Usage("--{$name} must be a plain X.Y.Z version, not {$value}.");
        }

        return $value;
    }
}

}

namespace {

if (!function_exists('__')) {
    /** The updater classes word their reasons with Laravel's __(); here the English text with its placeholders filled. */
    function __($key = null, $replace = [], $locale = null)
    {
        $text = (string) $key;
        $replace = (array) $replace;
        uksort($replace, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && ($self = realpath((string) $_SERVER['argv'][0])) !== false && strcasecmp($self, __FILE__) === 0) {
    exit(\Avd\Release\Cli::main($_SERVER['argv']));
}

}
