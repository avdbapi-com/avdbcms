<?php

namespace Tests\Feature;

use App\Http\Middleware\BackgroundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3, the old settings page (resources/views/admin/settings/index.blade.php):
 * its counted texts read right in every language
 * ("Found 1 color delta(s)", "{{ $i }} columns", "12 Studios" became a label and trans_choice lines) and its one
 * background GET, the daily design catalogue, carries the marker of App\Http\Middleware\BackgroundRequest.
 */
class OldSettingsPageTextsTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT; // as @json writes them

    public function test_the_old_settings_page_counts_with_the_forms_of_each_language(): void
    {
        Cache::flush();
        config(['admin.ui' => 'v1']);
        $page = (string) $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.index'))->assertOk()->getContent();

        // Theme Studio's colour check: a label instead of "Found 1 color delta(s)"
        $this->assertStringContainsString((string) json_encode('Colours that differ from ":preset": :count.', self::FLAGS), $page);
        $this->assertStringNotContainsString('delta(s)', $page);

        // the grid columns, through trans_choice ("2 columns" ... "6 columns" in English)
        foreach (range(2, 6) as $columns) {
            $this->assertStringContainsString('>'.$columns.' columns</option>', $page);
        }

        // the daily design catalogue is fetched as a background GET
        $this->assertStringContainsString('['.json_encode(BackgroundRequest::HEADER, self::FLAGS).']: \'1\'', $page);
    }

    public function test_no_counted_text_of_the_old_settings_page_has_a_fixed_plural(): void
    {
        $path = 'resources/views/admin/settings/index.blade.php';
        $source = (string) File::get(base_path($path));

        $this->assertStringContainsString("trans_choice('1 column|:count columns', \$i)", $source);
        $this->assertStringContainsString("trans_choice('1 Studio|:count Studios', count(\$masterExperiences)", $source);

        // the guard of CountedMessagesTest, strict here: under another name, none of the 1.2.2 lines it lets through
        // as replaced elsewhere (CountedMessagesTest::REPLACED_ELSEWHERE) is let through
        $this->assertSame([], CountedMessagesTest::fixedPluralsIn('patched/'.$path, $source));
    }
}
