<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var list<string> */
    private array $uploadFixturePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
     * The PHP CLI used by this checkout points sys_temp_dir at a protected
     * OSPanel directory, so tmpfile() (used by UploadedFile::fake()) returns
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
