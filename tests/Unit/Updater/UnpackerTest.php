<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Manifest;
use App\Support\Updater\Unpacker;
use App\Support\Updater\UpdateException;
use App\Support\Updater\ZipEntries;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\TestCase;

/**
 * Unpacking (F8 8, 18): ZipArchive and PharData stage byte-identical trees from the same zip (the zip is written in
 * plain PHP, so the PharData case runs on every PHP; the ZipArchive case needs ext-zip), every file is checked
 * against the package's manifest, and the work can stop and go on at any file.
 */
class UnpackerTest extends TestCase
{
    private UpdaterSandbox $box;

    /** @var array<string, string> */
    private array $files;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
        $this->files = [
            'app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\nclass User {}\n",
            'app/Support/Deep/Er/Still.php' => '<?php // deep',
            'public/build/assets/app-4f2a.js' => str_repeat("console.log('x');\n", 4000),
            'public/images/logo.png' => self::binary(70000),
            'vendor/big/blob.bin' => self::binary(2_500_000),
            'resources/views/empty.blade.php' => '',
            'lang/vi.json' => "{\n    \"Hello\": \"Xin chào\"\n}\n",
            'artisan' => "#!/usr/bin/env php\n<?php // artisan\n",
            '.htaccess' => "Options -Indexes\r\n",
        ];
        $this->zip = $this->box->releaseZip('1.0.1', $this->files);
    }

    protected function tearDown(): void
    {
        $this->box->delete();
        parent::tearDown();
    }

    public function test_phar_data_stages_every_file_checked_against_the_manifest(): void
    {
        $tree = $this->stage('PharData', 'phar');

        $this->assertSame($this->expected(), $tree);
    }

    public function test_zip_archive_and_phar_data_stage_byte_identical_trees(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('This PHP has no zip extension (ZipArchive); PharData is tested on its own.');
        }

        $zipArchive = $this->stage('ZipArchive', 'zip');
        $pharData = $this->stage('PharData', 'phar');

        $this->assertSame($this->expected(), $zipArchive);
        $this->assertSame($zipArchive, $pharData);
    }

    public function test_the_engine_is_zip_archive_when_present_else_phar_data(): void
    {
        $this->assertSame(class_exists(\ZipArchive::class) ? 'ZipArchive' : 'PharData', Unpacker::engine());

        $this->expectExceptionMessage('This server cannot open zip files. Upload the update zip with your file manager, then click Finish update on Home.');
        new Unpacker($this->zip, 'shell-unzip');
    }

    public function test_files_and_folders_get_fixed_modes(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows has no Unix file modes.');
        }

        $staging = $this->box->path('modes');
        $this->stage('PharData', 'modes');

        $this->assertSame(0755, fileperms($staging.'/artisan') & 0777);
        $this->assertSame(0644, fileperms($staging.'/app/Models/User.php') & 0777);
        $this->assertSame(0755, fileperms($staging.'/app/Models') & 0777);
    }

    public function test_the_work_stops_at_any_file_and_goes_on_from_there(): void
    {
        $files = ZipEntries::guard(ZipEntries::read($this->zip))['files'];
        $unpacker = new Unpacker($this->zip, 'PharData');
        $manifest = $unpacker->manifest();
        $staging = $this->box->path('steps');

        $next = $unpacker->stage($files, 0, $staging, $manifest, INF, 3);
        $this->assertSame(3, $next);
        $next = $unpacker->stage($files, $next, $staging, $manifest, INF, 4);
        $this->assertSame(7, $next);
        // A deadline that passed still writes one file per request, so the work always moves on.
        $next = $unpacker->stage($files, $next, $staging, $manifest, 0.0);
        $this->assertSame(8, $next);
        $next = $unpacker->stage($files, $next, $staging, $manifest, INF);
        $this->assertSame(count($files), $next);

        $this->assertSame($this->expected(), $this->box->snapshot([], $staging));
    }

    public function test_a_file_that_does_not_match_the_manifest_stops_the_unpack(): void
    {
        $manifest = UpdaterSandbox::manifest('1.0.1', ['app/Models/User.php' => 'something else'] + $this->files);
        $zip = $this->box->releaseZip('1.0.1', $this->files, [], $manifest);
        $files = ZipEntries::guard(ZipEntries::read($zip))['files'];
        $staging = $this->box->path('damaged');

        foreach ($this->engines() as $engine) {
            $unpacker = new Unpacker($zip, $engine);

            try {
                $unpacker->stage($files, 0, $staging, $unpacker->manifest(), INF);
                $this->fail($engine.': a damaged file was staged');
            } catch (UpdateException $e) {
                $this->assertSame('The update package is damaged: app/Models/User.php does not match its checksum. Nothing on your site was changed.', $e->getMessage());
                $this->assertFalse($e->retry);
            }

            $this->assertFileDoesNotExist($staging.'/app/Models/User.php');
            $this->assertFileDoesNotExist($staging.'/app/Models/User.php.part');
        }
    }

    public function test_a_file_the_manifest_does_not_list_stops_the_unpack(): void
    {
        $manifest = UpdaterSandbox::manifest('1.0.1', array_diff_key($this->files, ['lang/vi.json' => true]));
        $zip = $this->box->releaseZip('1.0.1', $this->files, [], $manifest);
        $files = ZipEntries::guard(ZipEntries::read($zip))['files'];
        $unpacker = new Unpacker($zip, 'PharData');

        $this->expectExceptionMessage('The update package is not complete. Nothing on your site was changed.');
        $unpacker->stage($files, 0, $this->box->path('incomplete'), $unpacker->manifest(), INF);
    }

    public function test_the_manifest_of_the_package_is_read_from_the_zip(): void
    {
        foreach ($this->engines() as $engine) {
            $manifest = (new Unpacker($this->zip, $engine))->manifest();

            $this->assertSame('1.0.1', $manifest->version, $engine);
            $this->assertSame(hash('sha256', $this->files['vendor/big/blob.bin']), $manifest->hash('vendor/big/blob.bin'), $engine);
        }
    }

    /** Stage the whole zip with one engine; the staged tree as path => SHA-256. */
    private function stage(string $engine, string $folder): array
    {
        $files = ZipEntries::guard(ZipEntries::read($this->zip))['files'];
        $unpacker = new Unpacker($this->zip, $engine);
        $staging = $this->box->path($folder);

        $this->assertSame(count($files), $unpacker->stage($files, 0, $staging, $unpacker->manifest(), INF, 100000));

        return $this->box->snapshot([], $staging);
    }

    /** @return array<string, string> */
    private function expected(): array
    {
        $tree = [];

        foreach ($this->files as $path => $content) {
            $tree[$path] = hash('sha256', $content);
        }

        $tree[Manifest::FILE] = hash('sha256', UpdaterSandbox::manifest('1.0.1', $this->files));
        ksort($tree, SORT_STRING);

        return $tree;
    }

    /** @return list<string> */
    private function engines(): array
    {
        return class_exists(\ZipArchive::class) ? ['ZipArchive', 'PharData'] : ['PharData'];
    }

    /** Bytes that do not compress well, the same on every run. */
    private static function binary(int $length): string
    {
        $out = '';

        for ($i = 0; strlen($out) < $length; $i++) {
            $out .= hash('sha256', 'updater-fixture-'.$i, true);
        }

        return substr($out, 0, $length);
    }
}
