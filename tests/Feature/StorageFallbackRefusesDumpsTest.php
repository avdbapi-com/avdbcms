<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Round 1b R1B-018, review BWR-04: the web server rules refuse a database dump, a log, a backup copy or a script in the
 * upload folders, but Laravel's storage fallback route (/storage/{path}: the public disk when the public/storage link
 * is missing) handed every public-disk file out. It is also reached as /index.php/storage/... (every nginx layout: the
 * dumps rule sends such an address to PHP) and as /<language>/storage/... (every layout, Apache included), which no web
 * server rule sees: /index.php/storage/x.sql and /fr/storage/x.log answered 200 with the content on the box.
 */
class StorageFallbackRefusesDumpsTest extends TestCase
{
    public static function refused(): array
    {
        return [
            'a dump' => ['bw-dump.sql'],
            'a compressed dump' => ['backups/bw-db.sql.gz'],
            'a log' => ['bw-debug.log'],
            'a backup copy' => ['bw-site.bak'],
            'an editor copy' => ['bw-index.php~'],
            'a database file' => ['bw-db.sqlite'],
            'an archive' => ['bw-site.tar'],
            'a script' => ['bw-x.php'],
            'a script in capitals' => ['bw-x.PHP'],
            'a phar' => ['bw-x.phar'],
            'a php5' => ['bw-x.php5'],
            'a picture name around a script' => ['bw-x.php.jpg'],
        ];
    }

    #[DataProvider('refused')]
    public function test_the_storage_fallback_never_hands_out_a_dump_a_log_or_a_script(string $path): void
    {
        Storage::fake('public');
        Storage::disk('public')->put($path, 'bw-content');

        $this->get('/storage/'.$path)->assertNotFound();
    }

    public function test_pictures_and_other_uploads_still_come_through_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/bw-picture.jpg', 'bw-picture');
        Storage::disk('public')->put('bw-notes.txt', 'bw-text');

        $picture = $this->get('/storage/avatars/bw-picture.jpg');
        $picture->assertOk();
        $this->assertSame('bw-picture', $picture->streamedContent());
        $this->get('/storage/bw-notes.txt')->assertOk();

        // A name that only contains such a word stays a normal file.
        Storage::disk('public')->put('bw-sqlite-guide.jpg', 'bw-picture-2');
        $this->get('/storage/bw-sqlite-guide.jpg')->assertOk();
    }
}
