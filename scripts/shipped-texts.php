<?php

/*
|--------------------------------------------------------------------------
| The texts older releases shipped (run by scripts/build-community-release.ps1)
|--------------------------------------------------------------------------
|
| Plain PHP (no Laravel boot). Makes app/Support/Updater/shipped-texts.json of the tree given with --root: for every
| lang/<locale>.json of the tree and every key whose text an older release shipped differently, the tree's text ("new")
| and each older shipped text ("old"). When an update finishes, App\Support\Updater\ShippedTexts gives a language file
| the owner edited the new text wherever it still has exactly an old one; the owner's own texts stay.
|
|   php scripts/shipped-texts.php --root=DIR [--previous=FILE]... [--check]
|
|   --root=DIR       the project or a build's staging folder: its lang/*.json are the new texts; the old texts its own
|                    shipped-texts.json already lists stay (so a release only needs the zip of the release before it)
|   --previous=FILE  a previous release's zip (avdbcms-X.Y.Z.zip or avdbcms-update-X.Y.Z.zip) or a folder it was
|                    unpacked into, any number of times: its lang/*.json texts are old texts, and so is everything its
|                    own shipped-texts.json lists (old and new texts alike: that release shipped them all)
|   --check          write nothing; exit 1 when the file in the tree is not what this run would write
|
| The code that makes the file is the tree's own App\Support\Updater\ShippedTexts (loaded from DIR/app), so the file
| always has the format the tree reads. The release build runs it on every build with -PreviousManifest (the previous
| release's zip): the next release needs no hand work. Run it by hand only to make the file again from several zips:
|
|   php scripts/shipped-texts.php --root=. --previous=avdbcms-1.2.0.zip --previous=avdbcms-1.2.1.zip --previous=avdbcms-1.2.2.zip
|
| Exit 0 = OK (or --check: the file is up to date), 1 = a problem (the reason on STDERR), 2 = usage error.
|
*/

namespace Avd\ShippedTexts {

use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Something is wrong: nothing is written, the build stops with this reason. */
final class Failed extends RuntimeException
{
}

final class Usage extends RuntimeException
{
}

/** A release's language files and its own data file, from its zip or from a folder it was unpacked into. */
final class Release
{
    /**
     * @param  array<string, array<string, string>>  $lang  locale => key => text
     * @param  array<string, array<string, list<string>>>  $listed  what its shipped-texts.json lists: locale => key => texts
     * @param  list<string>  $versions  the versions its texts are from (its own and those its data file names)
     * @param  array<string, list<string>>  $removed  the removed keys its shipped-texts.json lists (ShippedTexts::decodeRemoved())
     */
    public function __construct(
        public readonly string $version,
        public readonly array $lang,
        public readonly array $listed,
        public readonly array $versions,
        public readonly array $removed = [],
    ) {
    }

    public static function open(string $path): self
    {
        if (is_dir($path)) {
            return self::fromFolder($path);
        }

        if (! is_file($path)) {
            throw new Failed("No such release zip or folder: {$path}");
        }

        return self::fromZip($path);
    }

    private static function fromFolder(string $folder): self
    {
        $folder = rtrim(str_replace('\\', '/', $folder), '/');
        $lang = [];

        foreach (glob($folder.'/lang/*.json') ?: [] as $file) {
            $locale = basename($file, '.json');

            if (ShippedTexts::isLocale($locale)) {
                $lang[$locale] = self::language((string) file_get_contents($file), $folder.'/lang/'.$locale.'.json');
            }
        }

        $data = is_file($folder.'/'.ShippedTexts::PATH) ? (string) file_get_contents($folder.'/'.ShippedTexts::PATH) : null;
        $manifest = is_file($folder.'/avdbcms-manifest.json') ? (string) file_get_contents($folder.'/avdbcms-manifest.json') : null;
        $appVersion = is_file($folder.'/app/Support/AppVersion.php') ? (string) file_get_contents($folder.'/app/Support/AppVersion.php') : null;

        return self::make($folder, $lang, $data, $manifest, $appVersion);
    }

    private static function fromZip(string $zip): self
    {
        $entries = [];
        $wanted = static fn (string $name): bool => preg_match('#^lang/[A-Za-z0-9_-]{1,32}\.json\z#', $name) === 1
            || in_array($name, [ShippedTexts::PATH, 'avdbcms-manifest.json', 'app/Support/AppVersion.php'], true);

        if (class_exists(ZipArchive::class)) {
            $archive = new ZipArchive();

            if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
                throw new Failed('ZipArchive cannot open '.basename($zip).'.');
            }

            try {
                for ($i = 0; $i < $archive->numFiles; $i++) {
                    $name = (string) $archive->getNameIndex($i);

                    if ($wanted($name)) {
                        $content = $archive->getFromIndex($i);

                        if (! is_string($content)) {
                            throw new Failed(basename($zip).": cannot read {$name}.");
                        }

                        $entries[$name] = $content;
                    }
                }
            } finally {
                $archive->close();
            }
        } elseif (class_exists(\PharData::class)) {
            $phar = new \PharData($zip);
            $base = 'phar://'.str_replace('\\', '/', (string) realpath($zip)).'/';

            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                $name = substr(str_replace('\\', '/', (string) $file->getPathname()), strlen($base));

                if ($wanted($name)) {
                    $entries[$name] = (string) file_get_contents($base.$name);
                }
            }
        } else {
            throw new Failed('This PHP can open zip files neither with ZipArchive nor with PharData.');
        }

        $lang = [];

        foreach ($entries as $name => $content) {
            if (str_starts_with($name, 'lang/')) {
                $lang[basename($name, '.json')] = self::language($content, basename($zip).':'.$name);
            }
        }

        return self::make($zip, $lang, $entries[ShippedTexts::PATH] ?? null, $entries['avdbcms-manifest.json'] ?? null, $entries['app/Support/AppVersion.php'] ?? null);
    }

    /** @param  array<string, array<string, string>>  $lang */
    private static function make(string $where, array $lang, ?string $data, ?string $manifest, ?string $appVersion): self
    {
        $version = null;

        if ($manifest !== null) {
            $decoded = json_decode($manifest, true);
            $version = is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
        }

        if ($version === null && $appVersion !== null && preg_match("/const\\s+CURRENT\\s*=\\s*'(\\d+\\.\\d+\\.\\d+)'/", $appVersion, $match) === 1) {
            $version = $match[1];
        }

        if ($version === null || preg_match('/^\d+\.\d+\.\d+\z/', $version) !== 1) {
            throw new Failed("{$where}: no AVDBCMS version found (avdbcms-manifest.json or app/Support/AppVersion.php).");
        }

        if ($lang === []) {
            throw new Failed("{$where}: no lang/<locale>.json files.");
        }

        $listed = [];
        $versions = [$version];

        if ($data !== null) {
            $texts = ShippedTexts::decode($data);

            if ($texts === null) {
                throw new Failed("{$where}: its ".ShippedTexts::PATH.' is not a data file of format '.ShippedTexts::FORMAT.'.');
            }

            foreach ($texts as $locale => $entries) {
                foreach ($entries as $key => $entry) {
                    // That release shipped its "new" texts, and its data file lists the older ones.
                    $listed[$locale][(string) $key] = [$entry['new'], ...$entry['old']];
                }
            }

            $named = ShippedTexts::versions($data);
            $versions = [...$versions, ...$named['versions'], ...($named['for'] !== null ? [$named['for']] : [])];
        }

        return new self($version, $lang, $listed, array_values(array_unique($versions)), $data !== null ? ShippedTexts::decodeRemoved($data) : []);
    }

    /** @return array<string, string> */
    public static function language(string $json, string $where): array
    {
        $data = TranslationMerge::decode($json);

        if ($data === null) {
            throw new Failed("{$where} is not a language file (a flat JSON object of texts).");
        }

        return $data;
    }
}

final class Cli
{
    private const USAGE = <<<'TXT'
usage: php scripts/shipped-texts.php --root=DIR [--previous=FILE]... [--check]

TXT;

    /** @param  array<int, string>  $argv */
    public static function main(array $argv): int
    {
        try {
            if (PHP_VERSION_ID < 80300) {
                throw new Failed('scripts/shipped-texts.php needs PHP 8.3 or newer (this is '.PHP_VERSION.').');
            }

            [$root, $previous, $check] = self::parse($argv);
            self::load($root);
            $version = self::treeVersion($root);
            $current = [];

            foreach (ShippedTexts::files($root) as $locale => $file) {
                $current[$locale] = Release::language((string) file_get_contents($file), 'lang/'.$locale.'.json');
            }

            if ($current === []) {
                throw new Failed("{$root} has no lang/<locale>.json files.");
            }

            $target = $root.'/'.ShippedTexts::PATH;
            $existing = is_file($target) ? (string) file_get_contents($target) : null;
            $shipped = [];
            $versions = [];
            // The keys older releases shipped that the tree no longer has (judge R3-026): those its own data file lists
            // stay listed, and every previous release's own language files add theirs.
            $releases = [];
            $carried = $existing !== null ? ShippedTexts::decodeRemoved($existing) : [];

            if ($existing !== null) {
                $texts = ShippedTexts::decode($existing);

                if ($texts === null) {
                    throw new Failed(ShippedTexts::PATH.' in the tree is not a data file of format '.ShippedTexts::FORMAT.': delete it and run this again with every previous release zip.');
                }

                // Only its old texts: they came from release zips. Its new texts are the tree's, whatever they were then.
                $old = [];

                foreach ($texts as $locale => $entries) {
                    foreach ($entries as $key => $entry) {
                        $old[$locale][(string) $key] = $entry['old'];
                    }
                }

                $shipped[] = $old;
                $versions = ShippedTexts::versions($existing)['versions'];
            }

            foreach ($previous as $path) {
                $release = Release::open($path);

                if (version_compare($release->version, $version, '>')) {
                    throw new Failed("{$path} is AVDBCMS {$release->version}, newer than the tree ({$version}).");
                }

                $shipped[] = $release->lang;
                $shipped[] = $release->listed;
                $versions = [...$versions, ...$release->versions];
                $releases[] = $release->lang;

                foreach ($release->removed as $key => $texts) {
                    $carried[$key] = array_values(array_unique([...($carried[$key] ?? []), ...$texts]));
                }

                fwrite(STDOUT, "  shipped texts of AVDBCMS {$release->version}: ".count($release->lang).' languages'.($release->listed !== [] ? ', and the older texts its own data file lists' : '')."\n");
            }

            $data = ShippedTexts::build($current, $shipped, $versions, $version, $releases, $carried);
            $json = ShippedTexts::encodeData($data);
            $counts = ShippedTexts::counts($data['texts']);
            $summary = [];

            foreach ($counts as $locale => $count) {
                $summary[] = $locale.' '.$count['keys'];
            }

            fwrite(STDOUT, '  '.ShippedTexts::PATH.' for AVDBCMS '.$version.', old texts of '.(implode(', ', $data['versions']) ?: 'no release').': '
                .array_sum(array_column($counts, 'keys')).' texts in '.count($counts).' languages ('.(implode(', ', $summary) ?: 'none').")\n");
            fwrite(STDOUT, '  keys older releases shipped that this one no longer has (removed from edited language files while unchanged): '
                .count($data['removed'] ?? []).' ('.array_sum(array_map('count', $data['removed'] ?? [])).' texts)'."\n");

            if ($check) {
                if ($existing === $json) {
                    fwrite(STDOUT, "  up to date\n");

                    return 0;
                }

                throw new Failed(ShippedTexts::PATH.' is not up to date: run scripts/shipped-texts.php --root=... with the previous release zip.');
            }

            if ($existing === $json) {
                fwrite(STDOUT, "  unchanged\n");

                return 0;
            }

            self::write($target, $json);
            fwrite(STDOUT, '  written ('.strlen($json)." bytes)\n");

            return 0;
        } catch (Usage $e) {
            fwrite(STDERR, 'shipped-texts: '.$e->getMessage().PHP_EOL.self::USAGE);

            return 2;
        } catch (Throwable $e) {
            fwrite(STDERR, 'shipped-texts: '.($e instanceof Failed ? '' : get_class($e).': ').$e->getMessage().PHP_EOL);

            return 1;
        }
    }

    /**
     * @param  array<int, string>  $argv
     * @return array{0: string, 1: list<string>, 2: bool}
     */
    private static function parse(array $argv): array
    {
        $root = null;
        $previous = [];
        $check = false;

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--check') {
                $check = true;
            } elseif (preg_match('/^--(root|previous)=(.+)$/s', (string) $arg, $m) === 1) {
                if ($m[1] === 'root') {
                    $root = $m[2];
                } else {
                    $previous[] = $m[2];
                }
            } else {
                throw new Usage("Unknown option {$arg}.");
            }
        }

        if ($root === null) {
            throw new Usage('--root=DIR is required.');
        }

        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new Failed("No such folder: {$root}");
        }

        return [rtrim(str_replace('\\', '/', $real), '/'), $previous, $check];
    }

    /** The tree's own ShippedTexts and TranslationMerge (its data file must be in the format its code reads). */
    private static function load(string $root): void
    {
        foreach (['TranslationMerge', 'ShippedTexts'] as $class) {
            $file = $root.'/app/Support/Updater/'.$class.'.php';

            if (! is_file($file)) {
                throw new Failed("app/Support/Updater/{$class}.php is missing in {$root}: only a tree with ShippedTexts gets a data file.");
            }

            require_once $file;
        }
    }

    private static function treeVersion(string $root): string
    {
        $code = is_file($root.'/app/Support/AppVersion.php') ? (string) file_get_contents($root.'/app/Support/AppVersion.php') : '';

        if (preg_match("/const\\s+CURRENT\\s*=\\s*'(\\d+\\.\\d+\\.\\d+)'/", $code, $match) !== 1) {
            throw new Failed("{$root}/app/Support/AppVersion.php has no CURRENT version.");
        }

        return $match[1];
    }

    private static function write(string $file, string $json): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new Failed("Cannot write {$file}.");
        }
    }
}

}

namespace {

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && ($self = realpath((string) $_SERVER['argv'][0])) !== false && strcasecmp($self, __FILE__) === 0) {
    exit(\Avd\ShippedTexts\Cli::main($_SERVER['argv']));
}

}
