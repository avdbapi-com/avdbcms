<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 pre-fix pass, R2R-13: the key "Dismiss" read reject, fire, disband, discharge or set free in most
 * languages (es "Despedir", ko "해고하다", vi "Miễn nhiệm", id "Membubarkan", tr "Azletmek", th "อนุญาตให้ออกไป"). It has one
 * use: the title of the close button (x) of the watch page's "Continue where you left off?" prompt, which closes the
 * prompt without resuming (resources/views/videos/show.blade.php, the guarded player block: only the translations
 * changed). Each language now says Close there, exactly as its own "Close" key does.
 */
class DismissMeansCloseTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG = ['Despedir', '해고하다', 'Miễn nhiệm', 'Membubarkan', 'Azletmek', 'อนุญาตให้ออกไป', 'Liberar', 'नकार देना', '却下', '驳回', 'رفض', 'Zurückweisen', 'Rejeter', 'Отклонить', 'Tolak', 'I-dismiss'];

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_values(array_diff(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])), ['en']));
    }

    public function test_dismiss_has_one_use_the_close_button_of_the_resume_prompt(): void
    {
        $uses = [];
        foreach (['app', 'resources/views', 'resources/js', 'config', 'routes'] as $folder) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! preg_match('/\.(php|ts|tsx|js)$/', $path) || str_contains($path, '/resources/js/lang/')) {
                    continue;
                }
                if (preg_match_all('/(?:__|t|trans|@lang|__r)\(\s*([\'"`])Dismiss\1/', (string) file_get_contents($path), $m)) {
                    $uses[substr($path, strlen(str_replace('\\', '/', base_path())) + 1)] = count($m[0]);
                }
            }
        }

        // the same button names itself with the same word (its title and, since the owner's OQ-5 and OQ-1 of 30 Oct 2026,
        // its aria-label, the icon hidden from screen readers): still one use, the x of that prompt
        $this->assertSame(['resources/views/videos/show.blade.php' => 2], $uses, 'a new use of "Dismiss" needs its own check of the translations');
        $this->assertMatchesRegularExpression(
            '/onclick="window\.avdDismissResume\(\)" title="\{\{ __\(\'Dismiss\'\) \}\}" aria-label="\{\{ __\(\'Dismiss\'\) \}\}">\s*<i class="fa-solid fa-xmark" aria-hidden="true"><\/i>/',
            (string) file_get_contents(resource_path('views/videos/show.blade.php')),
            'the x of the "Continue where you left off?" prompt'
        );
    }

    public function test_every_language_says_close_there(): void
    {
        foreach ($this->locales() as $locale) {
            $dismiss = __('Dismiss', [], $locale);
            $close = __('Close', [], $locale);

            $this->assertNotSame('Close', $close, $locale.' translates Close');
            $this->assertSame($close, $dismiss, $locale);
            $this->assertNotContains($dismiss, self::WRONG, $locale);
        }

        $this->assertSame('Dismiss', __('Dismiss', [], 'en'));
    }

    public function test_the_watch_page_titles_the_close_button_in_the_readers_language(): void
    {
        $video = Video::factory()->for(User::factory(), 'user')->withStatus(VideoStatus::PUBLIC)->create();

        foreach (['es' => 'Cerrar', 'de' => 'Schließen', 'ar' => 'إغلاق', 'ja' => '閉じる'] as $locale => $close) {
            $_SERVER['DETECTED_LOCALE'] = $locale;
            $html = (string) $this->get($video->route)->assertOk()->getContent();
            unset($_SERVER['DETECTED_LOCALE']);

            $this->assertStringContainsString('onclick="window.avdDismissResume()" title="'.$close.'"', $html, $locale);
        }
    }
}
