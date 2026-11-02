<?php

namespace Tests\Feature;

use App\Enums\ExportStatus;
use App\Enums\VideoStatus;
use App\Models\Export;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every size the admin shows with its unit reads "3.64 MB" on a right-to-left page too (R2A-052; judge R2CD-RV-05):
 * the number left of its unit, kept on one line. On an Arabic page a size outside an element that reads left to right
 * was drawn unit first ("MB 22.38"): the new admin's Cache & storage folders, the Logs size badges, the Backups
 * sentence and rows, and in the old admin the Dashboard health bar, the Settings studio cards, Storage, Backups, a
 * video's page; Exports in both. The pages are rendered in Arabic, in the new and the old admin, with data that makes
 * them show sizes, and every text that is a size must sit in an element with dir="ltr" (an element with dir is
 * isolated by the browser) and be kept whole (nowrap). This replaces the first pass's scan of the Blade sources, which
 * missed the sizes that carry their unit inside a variable.
 */
class AdminSizesReadLeftToRightTest extends TestCase
{
    use RefreshDatabase;

    /** "3.64 MB", "852 B", "1,204.5 KB" (a number, a space, a unit) */
    private const SIZE = '/(?<![\w.,])\d[\d.,]*\s(?:B|KB|MB|GB|TB)(?![A-Za-z])/u';

    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-rv05-'.Str::lower(Str::random(10));

        foreach (['logs', 'app/backups', 'app/public', 'framework/cache', 'framework/views'] as $folder) {
            File::ensureDirectoryExists($this->storage.DIRECTORY_SEPARATOR.$folder);
        }

        File::put($this->storage.'/logs/laravel-2026-10-28.log', str_repeat("[2026-10-28 10:00:00] local.INFO: a line of the log\n", 40));
        File::put($this->storage.'/app/backups/backup_2026-10-28.sql', str_repeat("-- a backup\n", 9000));
        File::put($this->storage.'/app/public/poster.jpg', str_repeat('x', 30000));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_every_size_an_admin_page_shows_reads_left_to_right_in_arabic(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);
        $video = Video::factory()->for($admin)->create(['status' => VideoStatus::PUBLIC->value, 'size' => 52428800]);
        Export::query()->create(['file' => 'members-2026-10-28.csv', 'size' => 352000, 'status' => ExportStatus::COMPLETED]);
        $this->app->useStoragePath($this->storage);

        $pages = ['/admin', '/admin/backups', '/admin/logs-viewer', '/admin/exports', '/admin/videos/'.$video->id.'/edit',
            '/admin/storage', '/admin/system', '/admin/media', '/admin/cron', '/admin/database', '/admin/settings'];
        $seen = [];
        $loose = [];

        foreach (['v2', 'v1'] as $ui) {
            AdminPreferences::set($admin, 'admin_ui', $ui);
            config(['admin.ui' => $ui]);

            foreach ($pages as $page) {
                $response = $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get($page);

                if ($response->getStatusCode() !== 200) {
                    continue; // a page one of the admins does not have (it redirects to its own)
                }

                foreach ($this->sizes((string) $response->getContent()) as [$size, $ltr, $whole, $where]) {
                    $seen[$ui.' '.$page][] = $size;

                    if (! $ltr || ! $whole) {
                        $loose[] = sprintf('%s %s: "%s"%s%s in %s', $ui, $page, $size, $ltr ? '' : ' not left to right', $whole ? '' : ' may break', $where);
                    }
                }
            }
        }

        $this->assertSame([], $loose, 'sizes drawn unit first (or broken over two lines) on an Arabic page');

        // the scan saw the sizes it is about (not an empty pass): both admins' backups, logs, exports and a video
        foreach (['v2 /admin/backups', 'v1 /admin/backups', 'v2 /admin/logs-viewer', 'v1 /admin/logs-viewer', 'v2 /admin/exports',
                     'v1 /admin/exports', 'v2 /admin/videos/'.$video->id.'/edit', 'v1 /admin/videos/'.$video->id.'/edit', 'v1 /admin'] as $where) {
            $this->assertNotEmpty($seen[$where] ?? [], "{$where} shows a size");
        }
        $this->assertGreaterThanOrEqual(15, count($seen, COUNT_RECURSIVE) - count($seen), 'sizes seen in all');
    }

    /**
     * The sizes of a page's text (not in scripts, styles, code or form fields): each with whether its nearest element
     * with a direction reads left to right and whether it is kept on one line (nowrap, or a badge / truncated card).
     *
     * @return list<array{0: string, 1: bool, 2: bool, 3: string}>
     */
    private function sizes(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $out = [];

        foreach ((new DOMXPath($dom))->query('//body//text()') ?: [] as $node) {
            if (preg_match_all(self::SIZE, (string) $node->nodeValue, $matches) < 1 || $this->skipped($node)) {
                continue;
            }

            foreach ($matches[0] as $size) {
                $parent = $node->parentNode;
                $where = $parent instanceof DOMElement ? $parent->tagName.'.'.str_replace(' ', '.', trim($parent->getAttribute('class'))).' "'.mb_substr(trim((string) $parent->textContent), 0, 50).'"' : '?';
                $out[] = [$size, $this->direction($node) === 'ltr', $this->whole($node), $where];
            }
        }

        return $out;
    }

    private function skipped(DOMNode $node): bool
    {
        for ($e = $node->parentNode; $e instanceof DOMElement; $e = $e->parentNode) {
            if (in_array(strtolower($e->tagName), ['script', 'style', 'code', 'pre', 'textarea', 'template', 'noscript', 'option', 'title'], true)) {
                return true;
            }

            // the creators' upload window that the old admin's page carries (hands-off, Upload18: an owner proposal
            // for resources/views/users/videos/modals/upload.blade.php)
            if ($e->getAttribute('id') === 'video_create') {
                return true;
            }
        }

        return false;
    }

    /** The direction of the nearest element that sets one (dir="ltr" / "rtl"; <bdi> without dir reads by its text). */
    private function direction(DOMNode $node): string
    {
        for ($e = $node->parentNode; $e instanceof DOMElement; $e = $e->parentNode) {
            $dir = strtolower($e->getAttribute('dir'));

            if ($dir !== '') {
                return $dir;
            }

            if (strtolower($e->tagName) === 'bdi') {
                return 'auto';
            }
        }

        return 'rtl';
    }

    /** Whether the size is kept on one line: an element of it that does not wrap (text-nowrap, a badge, a truncated value). */
    private function whole(DOMNode $node): bool
    {
        for ($e = $node->parentNode; $e instanceof DOMElement; $e = $e->parentNode) {
            $class = ' '.$e->getAttribute('class').' ';

            foreach ([' text-nowrap ', ' badge ', ' text-truncate ', ' ad-chip '] as $nowrap) {
                if (str_contains($class, $nowrap)) {
                    return true;
                }
            }

            if (str_contains(strtolower($e->getAttribute('style')), 'nowrap')) {
                return true;
            }

            if (in_array(strtolower($e->tagName), ['td', 'th', 'li', 'div', 'p', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                // a block or cell around the size without nowrap: the size may break inside
                return false;
            }
        }

        return false;
    }
}
