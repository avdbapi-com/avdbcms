<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Round 1 of 1.2.3 (R1-138): the sign-in, sign-up, password and error pages of 1.2.3 show no picture any more, but the
 * package still shipped their old pictures twice, in public/images/ and in its copy images/ (index.php serves the site
 * from the project folder on panels that need it): about 4.4 MB each. Every picture of these folders is used now.
 */
class UnusedPicturesTest extends TestCase
{
    /** The pictures 1.2.2 shipped there that 1.2.3 no longer uses. */
    private const GONE = ['pages/forgot.png', 'pages/login.png', 'pages/register.png', 'pages/reset.jpg', 'pages/subscriptions.png',
        'errors/403.jpg', 'errors/404.jpg', 'errors/410.jpg', 'errors/500.jpg', 'errors/503.jpg'];

    public function test_the_unused_pictures_are_not_shipped(): void
    {
        foreach (['public/images/', 'images/'] as $folder) {
            foreach (self::GONE as $picture) {
                $this->assertFileDoesNotExist(base_path($folder.$picture));
            }
        }

        $this->assertFileExists(public_path('images/pages/contact.png'), 'the contact page shows it');
    }

    public function test_every_picture_of_the_page_folders_is_used(): void
    {
        $sources = $this->sources();

        foreach (['public/images/pages', 'public/images/errors', 'images/pages', 'images/errors'] as $folder) {
            foreach (File::exists(base_path($folder)) ? File::files(base_path($folder)) : [] as $file) {
                $name = substr($folder, strpos($folder, 'images/')).'/'.$file->getFilename();
                $this->assertStringContainsString($name, $sources, $folder.'/'.$file->getFilename().' is used nowhere');
            }
        }
    }

    public function test_the_copy_in_images_holds_no_picture_public_images_lacks(): void
    {
        foreach (File::allFiles(base_path('images')) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $this->assertFileExists(public_path('images/'.$relative), 'images/'.$relative.' has no original in public/images');
        }
    }

    /** The views, styles and scripts of the site and the admin, and the PHP that builds pages. */
    private function sources(): string
    {
        $text = '';

        foreach (['resources', 'app', 'config', 'routes'] as $folder) {
            foreach (File::allFiles(base_path($folder)) as $file) {
                if (in_array($file->getExtension(), ['php', 'ts', 'js', 'scss', 'css', 'json', 'tsx', 'vue'], true)) {
                    $text .= (string) file_get_contents($file->getPathname())."\n";
                }
            }
        }

        return $text;
    }
}
