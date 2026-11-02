<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Opcodes\LogViewer\Facades\LogViewer;
use Tests\TestCase;

/**
 * Round 1 of 1.2.3 (R1-082): Tools > Logs (the log viewer package) told every owner "Front-end assets are outdated. To
 * update, please run php artisan log-viewer:publish": the shipped public/vendor/log-viewer was older than the package
 * (v3.21.1), and shared hosting cannot run artisan. The published files are the package's own, byte for byte, and the
 * release build copies them from the package it ships (scripts/build-community-release.ps1, Sync-LogViewerAssets).
 */
class LogViewerAssetsTest extends TestCase
{
    public function test_the_published_assets_are_those_of_the_installed_log_viewer(): void
    {
        $package = base_path('vendor/opcodesio/log-viewer/public');
        $published = public_path('vendor/log-viewer');

        $this->assertDirectoryExists($package);
        $this->assertTrue(LogViewer::assetsAreCurrent(), 'the log viewer says its assets are current');

        $files = collect(File::allFiles($package))->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))->sort()->values()->all();
        $this->assertContains('mix-manifest.json', $files);

        foreach ($files as $file) {
            $this->assertFileExists($published.'/'.$file);
            $this->assertSame(hash_file('sha256', $package.'/'.$file), hash_file('sha256', $published.'/'.$file), $file);
        }
    }

    public function test_the_release_build_copies_them_from_the_package_it_ships(): void
    {
        $script = (string) file_get_contents(base_path('scripts/build-community-release.ps1'));

        $this->assertStringContainsString('function Sync-LogViewerAssets', $script);
        $this->assertMatchesRegularExpression('/\n\s*Sync-LogViewerAssets -Stage \$stage\r?\n/', $script, 'called after Composer installed the release\'s vendor/');
    }
}
