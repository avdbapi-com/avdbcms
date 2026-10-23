<?php

namespace Tests\Unit;

use App\Support\Updater\Manifest;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * scripts/release-manifest.php, the manifest and checksum tool of the release build (scripts/build-community-release.ps1):
 * the avdbcms-manifest.json it writes is what the updater reads (App\Support\Updater\Manifest), it refuses a package
 * the updater would refuse (ZipEntries, Manifest::safePath), and the .sha256 files it accepts are the exact sha256sum
 * lines the updater's Checksum::parse() takes. The script runs in its own PHP process, as the build runs it.
 */
class ReleaseManifestTest extends TestCase
{
    public function test_version_prints_app_version_current_and_refuses_any_other_tag(): void
    {
        $stage = $this->stage('1.2.3');

        $this->assertSame([0, '1.2.3'], $this->tool('version', '--stage=' . $stage));
        $this->assertSame([0, '1.2.3'], $this->tool('version', '--stage=' . $stage, '--tag=v1.2.3'));
        foreach (['v1.2.4', '1.2.3', 'V1.2.3', 'v1.2.3-beta', ''] as $tag) {
            [$code, $out] = $this->tool('version', '--stage=' . $stage, '--tag=' . $tag);
            $this->assertSame(1, $code, "tag '{$tag}': {$out}");
            $this->assertStringContainsString('is not "v1.2.3"', $out);
        }

        $this->assertSame(2, $this->tool('nonsense')[0]);
        $this->assertSame(2, $this->tool('version', '--stage=' . $stage, '--tagg=v1.2.3')[0]);
        $this->assertSame(1, $this->tool('version', '--stage=' . $stage . '/missing')[0]);
    }

    public function test_the_manifest_lists_every_file_except_bin_storage_and_the_installer_folder(): void
    {
        $stage = $this->stage('1.2.3', [
            'index.php' => "<?php\n",
            'lang/vi.json' => "{\"Hello\": \"Xin ch\u{e0}o\"}\n",
            'public/build/assets/app-1.js' => "console.log(1);\n",
            'public/images/a b.png' => random_bytes(64),
            'resources/js/empty.js' => '',
            'bootstrap/cache/.gitkeep' => '',
            'bin/linux-x64/ffmpeg' => 'binary',
            'storage/app/.gitignore' => "*\n",
            'storage/logs/.gitkeep' => '',
            'database/install/example.sql' => "-- seed\n",
            'avdbcms-manifest.json' => '{"stale": true}',
        ], ['storage/app/chunks', 'resources/empty']);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('avdbcms-manifest.json: AVDBCMS 1.2.3', $out);

        $json = File::get($stage . '/avdbcms-manifest.json');
        $data = json_decode($json, true);
        $this->assertSame(['product', 'version', 'requires_php', 'min_from', 'built_at', 'files', 'removed'], array_keys($data));
        $this->assertSame('AVDBCMS', $data['product']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['built_at']);

        $manifest = Manifest::parse($json);
        $this->assertSame(['1.2.3', '8.3.0', '1.2.0', []], [$manifest->version, $manifest->requiresPhp, $manifest->minFrom, $manifest->removed]);

        $expected = [];
        $listed = ['index.php', 'lang/vi.json', 'public/build/assets/app-1.js', 'public/images/a b.png', 'resources/js/empty.js', 'bootstrap/cache/.gitkeep'];
        foreach (File::allFiles($stage . '/app', true) as $file) {
            $listed[] = 'app/' . str_replace('\\', '/', $file->getRelativePathname());
        }
        foreach ($listed as $path) {
            $expected[$path] = hash_file('sha256', $stage . '/' . $path);
        }
        ksort($expected, SORT_STRING);
        // Same paths, same hashes, in byte order (the stale manifest, bin/, storage/ and database/install/ are not listed).
        $this->assertSame($expected, $manifest->files);
    }

    public function test_requires_php_and_min_from_can_be_set_but_min_from_not_above_the_release(): void
    {
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n"]);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--requires-php=8.4.0', '--min-from=1.1.0');
        $this->assertSame(0, $code, $out);
        $manifest = Manifest::parse(File::get($stage . '/avdbcms-manifest.json'));
        $this->assertSame(['8.4.0', '1.1.0'], [$manifest->requiresPhp, $manifest->minFrom]);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--min-from=1.3.0');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is newer than this release (1.2.3)', $out);
        $this->assertSame(2, $this->tool('manifest', '--stage=' . $stage, '--requires-php=8.3')[0]);
    }

    public function test_removed_names_what_the_previous_release_shipped_or_removed_and_this_one_does_not_ship(): void
    {
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n", 'app/Kept.php' => "<?php\n"]);
        // index.php was removed by 1.2.2 and is back now: not removed again.
        $previous = $this->previousManifest($stage, '1.2.2', ['app/Kept.php', 'app/Gone.php', 'public/build/assets/app-0.js'], ['app/Older.php', 'index.php']);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--previous=' . $previous);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('3 removed since 1.2.2', $out);
        $this->assertSame(['app/Gone.php', 'app/Older.php', 'public/build/assets/app-0.js'],
            Manifest::parse(File::get($stage . '/avdbcms-manifest.json'))->removed);
    }

    public function test_the_previous_manifest_can_be_read_from_one_of_its_zips(): void
    {
        $this->requireZip();
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n"]);
        $json = $this->previousManifest($stage, '1.2.2', ['index.php', 'app/Gone.php'], []);
        $previousZip = dirname($stage) . '/avdbcms-update-1.2.2.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($previousZip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($json, 'avdbcms-manifest.json');
        $zip->addFromString('index.php', "<?php\n");
        $zip->close();

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--previous=' . $previousZip);
        $this->assertSame(0, $code, $out);
        $this->assertSame(['app/Gone.php'], Manifest::parse(File::get($stage . '/avdbcms-manifest.json'))->removed);
    }

    public function test_a_previous_manifest_that_is_not_older_or_not_valid_is_refused(): void
    {
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n"]);

        foreach (['1.2.3' => 'which is not older than 1.2.3', '1.3.0' => 'which is not older than 1.2.3'] as $version => $reason) {
            [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--previous=' . $this->previousManifest($stage, $version, ['index.php'], []));
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString($reason, $out);
        }

        $foreign = dirname($stage) . '/foreign.json';
        File::put($foreign, json_encode(['product' => 'Other', 'version' => '1.0.0', 'requires_php' => '8.3.0', 'min_from' => '1.0.0', 'files' => ['a.php' => str_repeat('a', 64)]]));
        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--previous=' . $foreign);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not valid: manifest: another product', $out);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage, '--previous=' . dirname($stage) . '/nothing.json');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No previous manifest at', $out);
        $this->assertFileDoesNotExist($stage . '/avdbcms-manifest.json');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedFiles(): array
    {
        return [
            'site settings file' => ['.user.ini', 'a site settings file'],
            'copy of the .env' => ['public/.env.local', 'a site settings file'],
            'real file in storage' => ['storage/app/videos/clip.mp4', 'a file inside storage/'],
            'unknown top folder' => ['themes/readme.txt', 'a folder a release does not have'],
            'compiled cache' => ['bootstrap/cache/config.php', 'a compiled cache file'],
            'upload folder' => ['public/uploads/x.jpg', 'a path an update never writes'],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_a_file_the_updater_would_refuse_stops_the_manifest(string $path, string $reason): void
    {
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n", $path => 'x']);

        [$code, $out] = $this->tool('manifest', '--stage=' . $stage);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString($path . ': ' . $reason, $out);
        $this->assertFileDoesNotExist($stage . '/avdbcms-manifest.json');
    }

    public function test_verify_takes_zips_of_the_stage_and_refuses_bin_in_an_update_or_a_changed_or_missing_file(): void
    {
        $this->requireZip();
        $stage = $this->stage('1.2.3', [
            'index.php' => "<?php\n",
            'public/build/manifest.json' => '{}',
            'bin/linux-x64/ffmpeg' => 'binary',
            'storage/app/.gitkeep' => '',
            'database/install/example.sql' => "-- seed\n",
        ], ['storage/app/chunks', 'bin/windows']);
        $this->assertSame(0, $this->tool('manifest', '--stage=' . $stage)[0]);

        [$code, $out] = $this->tool('verify', '--stage=' . $stage, '--zip=' . $this->zip($stage, 'full.zip', true), '--kind=full');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('2 entries under bin/', $out);
        [$code, $out] = $this->tool('verify', '--stage=' . $stage, '--zip=' . $this->zip($stage, 'update.zip', false), '--kind=update');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('the updater skips 3 placeholders', $out);

        $cases = [
            'must not carry bin/' => ['full.zip', true, []],
            'index.php differs from the staged file' => ['changed.zip', false, ['index.php' => "<?php // changed\n"]],
            'missing in the zip (1): index.php' => ['missing.zip', false, ['index.php' => null]],
        ];
        foreach ($cases as $reason => [$name, $withBin, $replace]) {
            [$code, $out] = $this->tool('verify', '--stage=' . $stage, '--zip=' . $this->zip($stage, $name, $withBin, $replace), '--kind=update');
            $this->assertSame(1, $code, $reason);
            $this->assertStringContainsString($reason, $out);
        }
    }

    public function test_accepts_runs_the_updater_of_the_previous_release_on_the_new_update_zip(): void
    {
        $this->requireZip();
        $previous = $this->stage('1.2.2');
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n"]);
        $this->assertSame(0, $this->tool('manifest', '--stage=' . $stage)[0]);

        [$code, $out] = $this->tool('accepts', '--stage=' . $previous, '--zip=' . $this->zip($stage, 'update.zip', false));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('the updater of AVDBCMS 1.2.2 takes it', $out);
        $this->assertStringNotContainsString('WARNING', $out);

        [$code, $out] = $this->tool('accepts', '--stage=' . $this->stage('1.2.3'), '--zip=' . $this->zip($stage, 'update.zip', false));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('which is not newer than 1.2.3', $out);

        // A min_from above the previous version may be meant: only reported.
        $this->assertSame(0, $this->tool('manifest', '--stage=' . $stage, '--min-from=1.2.3')[0]);
        [$code, $out] = $this->tool('accepts', '--stage=' . $previous, '--zip=' . $this->zip($stage, 'update.zip', false));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('WARNING: sites on AVDBCMS 1.2.2 cannot install this update with one click', $out);

        // A top-level folder only the new release's own guard allows: sites on the previous release refuse the zip.
        $guard = $stage . '/app/Support/Updater/ZipEntries.php';
        File::put($guard, preg_replace('/(public const FOLDERS = \[)/', "\$1'plugins', ", File::get($guard), 1, $count));
        $this->assertSame(1, $count, 'ZipEntries::FOLDERS not found');
        File::ensureDirectoryExists($stage . '/plugins');
        File::put($stage . '/plugins/x.php', "<?php\n");
        $this->assertSame(0, $this->tool('manifest', '--stage=' . $stage)[0]);
        [$code, $out] = $this->tool('accepts', '--stage=' . $previous, '--zip=' . $this->zip($stage, 'update.zip', false));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('(plugins/x.php)', $out);
        $this->assertStringContainsString('a folder a release does not have', $out);
    }

    public function test_the_checksum_file_must_be_the_exact_sha256sum_line_of_its_zip(): void
    {
        $stage = $this->stage('1.2.3', ['index.php' => "<?php\n"]);
        $zip = dirname($stage) . '/avdbcms-update-1.2.3.zip';
        File::put($zip, 'the sha256 command only hashes the file');
        $hash = hash_file('sha256', $zip);

        $cases = [
            "{$hash}  avdbcms-update-1.2.3.zip\n" => 0,
            strtoupper($hash) . "  avdbcms-update-1.2.3.zip\n" => 1,
            "{$hash}  avdbcms-update-1.2.3.zip\r\n" => 1,
            "{$hash}  avdbcms-update-1.2.3.zip" => 1,
            "{$hash}\n" => 1,
            "{$hash}  avdbcms-1.2.3.zip\n" => 1,
            str_repeat('0', 64) . "  avdbcms-update-1.2.3.zip\n" => 1,
            "{$hash}  avdbcms-update-1.2.3.zip\nmore\n" => 1,
        ];
        foreach ($cases as $text => $expected) {
            File::put($zip . '.sha256', $text);
            [$code, $out] = $this->tool('sha256', '--stage=' . $stage, '--zip=' . $zip);
            $this->assertSame($expected, $code, json_encode($text) . ' ' . $out);
        }

        File::delete($zip . '.sha256');
        [$code, $out] = $this->tool('sha256', '--stage=' . $stage, '--zip=' . $zip);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('avdbcms-update-1.2.3.zip.sha256 is missing', $out);
    }

    /**
     * A throwaway staging folder: app/Support/AppVersion.php with CURRENT = $version, app/Support/Updater (the tool
     * loads the updater classes from the stage), then $files (path => content) and $empty folders.
     *
     * @param array<string, string> $files
     * @param array<int, string> $empty
     */
    private function stage(string $version, array $files = [], array $empty = []): string
    {
        $root = storage_path('framework/testing/release-' . bin2hex(random_bytes(4)));
        $stage = $root . '/stage';
        File::ensureDirectoryExists($stage . '/app/Support/Updater');
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($root));

        $appVersion = preg_replace("/public const CURRENT = '[^']+';/", "public const CURRENT = '{$version}';", File::get(base_path('app/Support/AppVersion.php')), 1, $count);
        $this->assertSame(1, $count, 'AppVersion::CURRENT line not found');
        File::put($stage . '/app/Support/AppVersion.php', $appVersion);
        File::copyDirectory(base_path('app/Support/Updater'), $stage . '/app/Support/Updater');
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($stage . '/' . $path));
            File::put($stage . '/' . $path, $content);
        }
        foreach ($empty as $folder) {
            File::ensureDirectoryExists($stage . '/' . $folder);
        }

        return str_replace('\\', '/', $stage);
    }

    /**
     * A previous release's manifest next to the stage.
     *
     * @param array<int, string> $files
     * @param array<int, string> $removed
     */
    private function previousManifest(string $stage, string $version, array $files, array $removed): string
    {
        $path = dirname($stage) . "/manifest-{$version}.json";
        File::put($path, json_encode([
            'product' => 'AVDBCMS',
            'version' => $version,
            'requires_php' => '8.3.0',
            'min_from' => '1.2.0',
            'built_at' => '2026-10-23T00:00:00Z',
            'files' => array_fill_keys($files, str_repeat('a', 64)),
            'removed' => $removed,
        ], JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * A zip of the stage as the build writes one: forward slashes, a "dir/" entry for each empty folder.
     *
     * @param array<string, string|null> $replace path => other content, or null to leave the file out
     */
    private function zip(string $stage, string $name, bool $withBin, array $replace = []): string
    {
        $path = dirname($stage) . '/' . $name;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $pending = [''];
        while ($pending !== []) {
            $rel = array_pop($pending);
            $names = array_values(array_diff(scandir($rel === '' ? $stage : $stage . '/' . $rel), ['.', '..']));
            if ($names === [] && $rel !== '' && ($withBin || !str_starts_with($rel, 'bin/'))) {
                $zip->addEmptyDir($rel);
            }
            foreach ($names as $entry) {
                $child = $rel === '' ? $entry : $rel . '/' . $entry;
                if (is_dir($stage . '/' . $child)) {
                    $pending[] = $child;
                } elseif ($withBin || !str_starts_with($child, 'bin/')) {
                    if (!array_key_exists($child, $replace)) {
                        $zip->addFile($stage . '/' . $child, $child);
                    } elseif ($replace[$child] !== null) {
                        $zip->addFromString($child, $replace[$child]);
                    }
                }
            }
        }
        $zip->close();

        return $path;
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is needed to build the test zips.');
        }
    }

    /** @return array{0: int, 1: string} exit code and output (stdout + stderr) of scripts/release-manifest.php */
    private function tool(string ...$args): array
    {
        // OPcache off for the child process, as the build runs it (a stale CLI cache must never answer for the script).
        $command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg(base_path('scripts/release-manifest.php'));
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $lines, $code);

        return [$code, trim(implode("\n", $lines))];
    }
}
