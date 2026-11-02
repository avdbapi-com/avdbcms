<?php

namespace Tests\Unit;

use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * scripts/shipped-texts.php, run by the release build (scripts/build-community-release.ps1) with the previous release's
 * zip: it makes app/Support/Updater/shipped-texts.json from the tree's language files (the new texts) and the texts the
 * previous releases shipped (their zips, and what the tree's own data file and a zip's data file already list), so the
 * next release needs no hand work. The script runs in its own PHP process, as the build runs it.
 */
class ShippedTextsScriptTest extends TestCase
{
    public function test_it_makes_the_data_file_from_a_previous_release_zip_and_checks_it(): void
    {
        $tree = $this->tree('1.2.3', [
            'de' => ['Save' => 'Speichern', 'Delete' => 'Löschen', 'Home' => 'Start', 'New' => 'Neu'],
            'fr' => ['Save' => 'Enregistrer', 'Home' => 'Accueil'],
        ]);
        $zip = $this->release('1.2.2', ['de' => ['Save' => 'Sichern', 'Delete' => 'Löschen', 'Home' => 'Start'], 'fr' => ['Save' => 'Sauver', 'Home' => 'Accueil']]);

        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$zip);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('shipped texts of AVDBCMS 1.2.2: 2 languages', $out);
        $this->assertStringContainsString('for AVDBCMS 1.2.3, old texts of 1.2.2: 2 texts in 2 languages (de 1, fr 1)', $out);
        $this->assertStringContainsString('written', $out);
        $json = File::get($tree.'/'.ShippedTexts::PATH);
        $this->assertSame(['de' => ['Save' => ['new' => 'Speichern', 'old' => ['Sichern']]], 'fr' => ['Save' => ['new' => 'Enregistrer', 'old' => ['Sauver']]]], ShippedTexts::decode($json));
        $this->assertSame(['for' => '1.2.3', 'versions' => ['1.2.2']], ShippedTexts::versions($json));

        // The same run again: nothing changes; --check agrees.
        $this->assertSame(0, $this->tool('--root='.$tree, '--previous='.$zip)[0]);
        $this->assertSame($json, File::get($tree.'/'.ShippedTexts::PATH));
        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$zip, '--check');
        $this->assertSame([0, true], [$code, str_contains($out, 'up to date')], $out);

        // A text of the tree changed since: --check says so and writes nothing.
        File::put($tree.'/lang/de.json', TranslationMerge::encode(['Save' => 'Abspeichern', 'Delete' => 'Löschen', 'Home' => 'Start', 'New' => 'Neu']));
        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$zip, '--check');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('is not up to date', $out);
        $this->assertSame($json, File::get($tree.'/'.ShippedTexts::PATH));
    }

    public function test_the_next_release_keeps_the_older_texts_with_only_its_previous_zip(): void
    {
        // 1.2.3 is made from three zips (the first data file), then 1.2.4 from the 1.2.3 zip only.
        $tree = $this->tree('1.2.3', ['de' => ['Save' => 'Speichern', 'Delete' => 'Löschen']]);
        $zips = [
            $this->release('1.2.0', ['de' => ['Save' => 'Sichern', 'Delete' => 'Entfernen']]),
            $this->release('1.2.1', ['de' => ['Save' => 'Abspeichern', 'Delete' => 'Entfernen']]),
            $this->release('1.2.2', ['de' => ['Save' => 'Abspeichern', 'Delete' => 'Löschen']]),
        ];
        [$code, $out] = $this->tool('--root='.$tree, ...array_map(fn (string $zip): string => '--previous='.$zip, $zips));
        $this->assertSame(0, $code, $out);
        $first = File::get($tree.'/'.ShippedTexts::PATH);
        $this->assertSame(['de' => [
            'Delete' => ['new' => 'Löschen', 'old' => ['Entfernen']],
            'Save' => ['new' => 'Speichern', 'old' => ['Abspeichern', 'Sichern']],
        ]], ShippedTexts::decode($first));
        $this->assertSame(['1.2.0', '1.2.1', '1.2.2'], ShippedTexts::versions($first)['versions']);

        // The 1.2.3 release zip (its data file inside), then the tree of 1.2.4: Save corrected again, Delete reverted.
        $released = $this->release('1.2.3', ['de' => ['Save' => 'Speichern', 'Delete' => 'Löschen']], $first);
        $this->writeVersion($tree, '1.2.4');
        File::put($tree.'/lang/de.json', TranslationMerge::encode(['Save' => 'Sichern!', 'Delete' => 'Entfernen']));

        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$released);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('and the older texts its own data file lists', $out);
        $next = File::get($tree.'/'.ShippedTexts::PATH);
        $this->assertSame(['de' => [
            // 1.2.3's own text is old now; the text of 1.2.4 is never among the old ones (Delete went back to 1.2.0's).
            'Delete' => ['new' => 'Entfernen', 'old' => ['Löschen']],
            'Save' => ['new' => 'Sichern!', 'old' => ['Abspeichern', 'Sichern', 'Speichern']],
        ]], ShippedTexts::decode($next));
        $this->assertSame(['for' => '1.2.4', 'versions' => ['1.2.0', '1.2.1', '1.2.2', '1.2.3']], ShippedTexts::versions($next));

        // The same from an unpacked folder of that zip.
        $folder = dirname($released).'/unpacked-1.2.3';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($released));
        $zip->extractTo($folder);
        $zip->close();
        File::delete($tree.'/'.ShippedTexts::PATH);
        File::put($tree.'/'.ShippedTexts::PATH, $first);
        $this->assertSame(0, $this->tool('--root='.$tree, '--previous='.$folder)[0]);
        $this->assertSame($next, File::get($tree.'/'.ShippedTexts::PATH));
    }

    public function test_it_refuses_what_it_cannot_use(): void
    {
        $tree = $this->tree('1.2.3', ['de' => ['Save' => 'Speichern']]);

        // Usage.
        $this->assertSame(2, $this->tool()[0]);
        $this->assertSame(2, $this->tool('--root='.$tree, '--nonsense')[0]);
        // A missing tree, a missing zip, a zip of a newer release, a zip without language files.
        $this->assertSame(1, $this->tool('--root='.$tree.'/missing')[0]);
        $this->assertSame(1, $this->tool('--root='.$tree, '--previous='.$tree.'/missing.zip')[0]);
        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$this->release('1.2.4', ['de' => ['Save' => 'X']]));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('newer than the tree (1.2.3)', $out);
        [$code, $out] = $this->tool('--root='.$tree, '--previous='.$this->release('1.2.2', []));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no lang/<locale>.json files', $out);

        // A broken language file of the tree, or a data file in the tree that is not one: nothing is written.
        File::put($tree.'/lang/fr.json', '{"Save": ');
        [$code, $out] = $this->tool('--root='.$tree);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('lang/fr.json is not a language file', $out);
        File::delete($tree.'/lang/fr.json');
        File::put($tree.'/'.ShippedTexts::PATH, '{"format": 9}');
        [$code, $out] = $this->tool('--root='.$tree);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not a data file', $out);
        $this->assertSame('{"format": 9}', File::get($tree.'/'.ShippedTexts::PATH));

        // Without any previous release: a data file with no old texts.
        File::delete($tree.'/'.ShippedTexts::PATH);
        $this->assertSame(0, $this->tool('--root='.$tree)[0]);
        $this->assertSame([], ShippedTexts::decode(File::get($tree.'/'.ShippedTexts::PATH)));
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * A project tree: AppVersion (CURRENT = $version), this code's ShippedTexts and TranslationMerge, its language files.
     *
     * @param  array<string, array<string, string>>  $languages
     */
    private function tree(string $version, array $languages): string
    {
        $base = str_replace('\\', '/', storage_path('framework/testing/shipped-texts-script-'.bin2hex(random_bytes(4))));
        $tree = $base.'/tree';
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($base));
        File::ensureDirectoryExists($tree.'/app/Support/Updater');
        File::ensureDirectoryExists($tree.'/lang');
        $this->writeVersion($tree, $version);

        foreach (['ShippedTexts', 'TranslationMerge'] as $class) {
            File::copy(base_path('app/Support/Updater/'.$class.'.php'), $tree.'/app/Support/Updater/'.$class.'.php');
        }

        foreach ($languages as $locale => $texts) {
            File::put($tree.'/lang/'.$locale.'.json', TranslationMerge::encode($texts));
        }

        $this->base = $base;

        return $tree;
    }

    private string $base = '';

    private function writeVersion(string $tree, string $version): void
    {
        $code = preg_replace("/public const CURRENT = '[^']+';/", "public const CURRENT = '{$version}';", File::get(base_path('app/Support/AppVersion.php')), 1, $count);
        $this->assertSame(1, $count, 'AppVersion::CURRENT line not found');
        File::put($tree.'/app/Support/AppVersion.php', $code);
    }

    /**
     * A release zip next to the tree: its avdbcms-manifest.json (the version), its language files, its data file.
     *
     * @param  array<string, array<string, string>>  $languages
     */
    private function release(string $version, array $languages, ?string $data = null): string
    {
        $path = $this->base.'/avdbcms-'.$version.'-'.bin2hex(random_bytes(2)).'.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('avdbcms-manifest.json', (string) json_encode(['product' => 'AVDBCMS', 'version' => $version]));
        $zip->addFromString('index.php', "<?php\n");

        foreach ($languages as $locale => $texts) {
            $zip->addFromString('lang/'.$locale.'.json', TranslationMerge::encode($texts));
        }

        $zip->addFromString('lang/de/auth.php', "<?php return [];\n");

        if ($data !== null) {
            $zip->addFromString(ShippedTexts::PATH, $data);
        }

        $zip->close();

        return $path;
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function tool(string ...$args): array
    {
        // OPcache off for the child process, as the build runs it.
        $command = escapeshellarg(PHP_BINARY).' -d opcache.enable_cli=0 '.escapeshellarg(base_path('scripts/shipped-texts.php'));

        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }

        exec($command.' 2>&1', $lines, $code);

        return [$code, trim(implode("\n", $lines))];
    }
}
