<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Http\Controllers\Admin\StorageController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * R1-084: the old admin's Cache & storage page counted every file under storage/ and public/ before it answered; with
 * about 78,000 files it passed the time limit and answered 500. It now shows the answer of the "folders" status probe
 * (each walk stops after about 3 seconds and says "at least"), like the new admin; Clear deletes for a few seconds at
 * most and says when files are left. The Health check's Server tab counts its folders the same bounded way.
 */
class AdminStorageBigFoldersTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/big-folder-'.uniqid());
        File::ensureDirectoryExists($this->folder.'/a/b');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_the_old_admin_page_answers_from_the_bounded_probe(): void
    {
        config(['admin.ui' => 'v1']);
        // A probe answer kept from a moment ago: the page shows it, it does not walk the folders again.
        $folders = [];
        foreach (array_keys(StorageController::folders()) as $label) {
            $folders[$label] = ['exists' => true, 'size' => 5_000_000, 'files' => 123_456, 'formatted_size' => '4.77 MB', 'partial' => $label === 'storage/framework/cache'];
        }
        Cache::put('admin.status.folders.'.app()->getLocale(), ['ok' => true, 'probe' => 'folders', 'status' => 'ok', 'label' => 'Folder sizes',
            'detail' => null, 'data' => ['folders' => $folders], 'action' => null, 'checked_at' => now()->toIso8601String(), 'ms' => 1], 600);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/storage')
            ->assertOk()
            ->assertSee('123,456+', false)
            ->assertSee('Some folders hold too many files to count in a few seconds');
    }

    public function test_a_walk_stops_at_its_deadline_and_says_so(): void
    {
        foreach (range(1, 700) as $n) {
            file_put_contents($this->folder.'/a/b/file'.$n.'.cache', 'x');
        }

        $full = StatusProbeController::folderSize($this->folder, microtime(true) + 60);
        $this->assertSame(['files' => 700, 'partial' => false], ['files' => $full['files'], 'partial' => $full['partial']]);

        $cut = StatusProbeController::folderSize($this->folder, microtime(true) - 1);
        $this->assertTrue($cut['partial']);
        $this->assertLessThan(700, $cut['files']);
    }

    public function test_clearing_stops_at_its_deadline_and_keeps_dot_files(): void
    {
        foreach (range(1, 700) as $n) {
            file_put_contents($this->folder.'/a/b/file'.$n.'.cache', 'x');
        }
        file_put_contents($this->folder.'/.gitignore', "*\n");

        $remove = new ReflectionMethod(StorageController::class, 'removeFiles');

        $first = $remove->invoke(null, $this->folder, microtime(true) - 1);
        $this->assertTrue($first['left'], 'a deadline that has passed stops the clearing');
        $this->assertLessThan(700, $first['removed']);

        $rest = $remove->invoke(null, $this->folder, microtime(true) + 60);
        $this->assertFalse($rest['left']);
        $this->assertSame(700, $first['removed'] + $rest['removed']);
        $this->assertFileExists($this->folder.'/.gitignore', 'the dot file that keeps the folder stays');
    }

    public function test_the_server_tab_still_answers(): void
    {
        config(['admin.ui' => 'v2']);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.system.index'))->assertOk()->assertSee('storage/logs');
    }
}
