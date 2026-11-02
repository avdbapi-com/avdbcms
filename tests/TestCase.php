<?php

namespace Tests;

use App\Services\BrevoService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FakeSmsClient;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var list<string> */
    private array $uploadFixturePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // App\Support\TimeBudget keeps the PHP time limit its request raised (set_time_limit: PendingMigrations::run(),
        // Updater::finish(), the installer) and caps every later budget of that request by it. The test process runs
        // every test in one PHP: without this, a test 290-300 s after another one raised it got a budget of a few
        // seconds ("Some of the database changes are done": UP-T3). Each test starts as a fresh request does.
        (new \ReflectionProperty(\App\Support\TimeBudget::class, 'raised'))->setValue(null, null);
        \App\Support\TimeBudget::clear();
        // No test reaches the SMS service (1.2.3 round 3, R3-059): the phone codes a test sends are recorded by a fake
        // client (Tests\Support\FakeSmsClient: app(BrevoService::class)->sent). The real client posted them to Brevo's
        // API with the tests' made-up keys, so the suite depended on the network and logged Brevo's refusals.
        $this->app->instance(BrevoService::class, new FakeSmsClient());
        // No test writes the copy's own robots.txt (round 4, R3M-02): a save of the SEO settings writes the site's file
        // (App\Support\RobotsTxt) into this scratch folder; a test of that file names folders of its own.
        $robots = storage_path('framework/testing/robots-txt');
        // each test starts without the file an earlier test wrote there (the database starts empty too)
        File::deleteDirectory($robots);
        File::ensureDirectoryExists($robots.'/public');
        $this->app->instance(\App\Support\RobotsTxt::FOLDERS, ['public' => $robots.'/public', 'project' => $robots]);
    }

    protected function tearDown(): void
    {
        foreach ($this->uploadFixturePaths as $path) {
            File::delete($path);
        }

        $this->uploadFixturePaths = [];

        parent::tearDown();
    }

    /**
     * Build a Laravel testing file backed by a writable project-local stream.
     *
     * A PHP CLI whose sys_temp_dir is a protected directory (some Windows
     * web server packages set one) makes tmpfile() (used by UploadedFile::fake()) return
     * false. Keeping the fixture path under storage/framework/testing makes
     * these tests independent of that machine-level setting.
     */
    protected function fakeUpload(string $name, int $kilobytes = 0, ?string $mimeType = null): UploadedFile
    {
        $path = $this->uploadFixturePath($name);
        file_put_contents($path, str_repeat("\0", $kilobytes * 1024));

        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new \RuntimeException("Unable to open upload fixture: {$path}");
        }

        $file = new TestingFile($name, $stream);
        $file->sizeToReport = $kilobytes * 1024;
        $file->mimeTypeToReport = $mimeType;

        return $file;
    }

    protected function fakeImageUpload(string $name, int $width, int $height): UploadedFile
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            throw new \RuntimeException('GD image functions are required for image upload fixtures.');
        }

        $path = $this->uploadFixturePath($name);
        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path);
        imagedestroy($image);

        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new \RuntimeException("Unable to open image upload fixture: {$path}");
        }

        $file = new TestingFile($name, $stream);
        $file->mimeTypeToReport = 'image/jpeg';

        return $file;
    }

    private function uploadFixturePath(string $name): string
    {
        $directory = storage_path('framework/testing/uploads');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid()->toString().'-'.basename($name);
        $this->uploadFixturePaths[] = $path;

        return $path;
    }
}
