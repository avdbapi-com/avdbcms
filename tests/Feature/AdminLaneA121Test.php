<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.1, lane A (admin pages): the XVIDAPI "Add New Scheduled Cron" card and its placeholder, the AVDB filter
 * placeholders and category names, Tools > Backups wording and sizes, the Arabic name on Settings > Languages and the
 * help text of "Bottom bar on phones". Review follow-ups: the old admin's import pages in light mode (dark fields and
 * the "Live Ecosystem" cards), the backup's age on phones (in the size cell, not after the file name).
 *
 * Proposed for tests/Feature/ (lane A does not own tests/). The translation test at the end needs the lane's
 * i18n.json applied to lang/*.json.
 */
class AdminLaneA121Test extends TestCase
{
    use RefreshDatabase;

    private const BOTTOM_BAR_HELP = 'Home, Categories and Random at the bottom of the phone screen, then Login and Register for visitors or Library and Profile for members. Explore replaces Random when the Random video button is off.';

    private const BACKUPS_LINE = '1 backup takes :total on the server.|:count backups take :total on the server.';

    private ?string $storage = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The import pages ask their provider for its numbers: never over the network in a test.
        Http::fake();
    }

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
            $this->storage = null;
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ Tools > Backups

    public function test_one_backup_is_singular_and_a_small_size_is_shown_in_kb(): void
    {
        $this->backupFiles(['backup_2026-10-24_042027.sql.gz' => 36149]);

        $html = $this->newAdminPage(route('admin.backups.index'));

        $this->assertStringContainsString('1 backup takes 35.3 KB on the server.', $html);
        $this->assertStringNotContainsString('1 backups', $html);
        $this->assertStringNotContainsString('0.0 MB', $html);
        // The Size column never wraps ("0.0 / MB" at 1440 px); on phones the backup's age follows the size there.
        $this->assertMatchesRegularExpression('#<td class="c-meta is-num text-nowrap">35\.3 KB<small class="ad-phone-only">[^<]+</small></td>#', $html);
    }

    public function test_on_phones_the_backup_age_sits_in_the_size_cell_not_after_the_file_name(): void
    {
        $this->backupFiles(['backup_2026-10-23_090000.sql' => 2048]);
        touch($this->storage.'/app/backups/backup_2026-10-23_090000.sql', time() - 18);

        $xpath = $this->xpath($this->newAdminPage(route('admin.backups.index')));

        // The name cell holds the name only ("backup_....sql18 seconds ago" ran together on phones in 1.2.0).
        $main = $xpath->query('//tbody/tr/td[contains(@class,"c-main")]')->item(0);
        $this->assertNotNull($main);
        $this->assertSame('backup_2026-10-23_090000.sql', trim($main->textContent));
        $this->assertSame(0, $xpath->query('.//small', $main)->length);

        // The size cell (a row with a gap on phones) carries the size, then the phone-only age.
        $size = $xpath->query('//tbody/tr/td[contains(@class,"c-meta")]')->item(0);
        $this->assertSame('2 KB', trim($size->firstChild->textContent));
        $age = $xpath->query('./small[contains(@class,"ad-phone-only")]', $size);
        $this->assertSame(1, $age->length);
        $this->assertMatchesRegularExpression('/^\d+ (seconds?|minutes?) ago$/', trim($age->item(0)->textContent));
        // Wider screens keep the Made column.
        $this->assertSame(1, $xpath->query('//tbody/tr/td[contains(@class,"is-date")]')->length);
    }

    public function test_several_backups_are_plural_with_sizes_in_b_kb_and_mb(): void
    {
        $this->backupFiles(['backup_a.sql' => 2 * 1048576, 'backup_b.sql.gz' => 512 * 1024, 'backup_c.sql' => 300]);

        $html = $this->newAdminPage(route('admin.backups.index'));

        $this->assertStringContainsString('3 backups take 2.5 MB on the server.', $html);
        foreach (['2 MB', '512 KB', '300 B'] as $size) {
            $this->assertStringContainsString('<td class="c-meta is-num text-nowrap">'.$size.'<small class="ad-phone-only">', $html);
        }
    }

    public function test_hidden_files_in_the_backups_folder_are_not_backups(): void
    {
        $this->backupFiles(['.gitkeep' => 0, '.htaccess' => 20, 'backup_x.sql' => 2048]);

        $html = $this->newAdminPage(route('admin.backups.index'));

        $this->assertStringContainsString('1 backup takes 2 KB on the server.', $html);
        $this->assertStringContainsString('data-id="backup_x.sql"', $html);
        $this->assertStringNotContainsString('data-id=".htaccess"', $html);
        $this->assertStringNotContainsString('data-id=".gitkeep"', $html);
    }

    public function test_without_backups_the_line_says_no_backup_count(): void
    {
        $this->backupFiles([]);

        $html = $this->newAdminPage(route('admin.backups.index'));

        $this->assertStringNotContainsString('backups take', $html);
        $this->assertStringNotContainsString('backup takes', $html);
        // SQLite has no information_schema: the size is left out and the page keeps its own description.
        $this->assertStringContainsString('Make, download and delete backups of your database.', $html);
        $this->assertStringContainsString('No backup yet', $html);
    }

    public function test_the_old_admin_backups_page_uses_the_same_sizes(): void
    {
        $this->backupFiles(['backup_old.sql.gz' => 36149]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);
        config(['admin.ui' => 'v1', 'admin.operational_tools_enabled' => true]);

        $html = $this->actingAs($admin)->get(route('admin.backups.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td class="align-middle text-nowrap">35.3 KB</td>', $html);
        $this->assertStringNotContainsString('0.03 MB', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function test_backup_sizes_read_like_the_other_admin_lists_and_keep_whole_numbers(int $bytes, string $text): void
    {
        $this->assertSame($text, \App\Http\Controllers\Admin\BackupController::sizeText($bytes));
    }

    public static function sizes(): array
    {
        return [
            'nothing' => [0, '0 B'],
            'negative' => [-5, '0 B'],
            'bytes' => [300, '300 B'],
            'a thousand bytes' => [1000, '1,000 B'],
            'just under 1 KB' => [1023, '1,023 B'],
            '1 KB' => [1024, '1 KB'],
            'small backup' => [36149, '35.3 KB'],
            '100 KB' => [102400, '100 KB'],
            '512 KB' => [524288, '512 KB'],
            'rounds up to 1 MB' => [1048575, '1 MB'],
            '1 MB' => [1048576, '1 MB'],
            'small database' => [3544186, '3.38 MB'],
            '100 MB' => [104857600, '100 MB'],
            '500 MB' => [524288000, '500 MB'],
            '1.5 GB' => [1610612736, '1.5 GB'],
            '10 GB' => [10737418240, '10 GB'],
        ];
    }

    public function test_the_backups_line_is_plural_in_every_locale_rule(): void
    {
        $line = function (string $locale, int $count): string {
            app()->setLocale($locale);

            return trans_choice(self::BACKUPS_LINE, $count, ['count' => number_format($count), 'total' => '1 MB']);
        };

        $this->assertSame('1 backup takes 1 MB on the server.', $line('en', 1));
        $this->assertSame('5 backups take 1 MB on the server.', $line('en', 5));
        $this->assertSame('21 резервная копия занимает 1 MB на сервере.', $line('ru', 21));
        $this->assertSame('3 резервные копии занимают 1 MB на сервере.', $line('ru', 3));
        $this->assertSame('5 резервных копий занимают 1 MB на сервере.', $line('ru', 5));
        $this->assertSame('نسختان احتياطيتان تشغلان 1 MB على الخادم.', $line('ar', 2));
        $this->assertSame('1 Sicherung belegt 1 MB auf dem Server.', $line('de', 1));
        $this->assertSame('7 bản sao lưu chiếm 1 MB trên máy chủ.', $line('vi', 7));
    }

    // ------------------------------------------------------------------ Settings > Languages

    public function test_a_right_to_left_language_name_is_isolated_and_keeps_the_column_alignment(): void
    {
        Language::query()->updateOrCreate(['code' => 'ar'], ['label' => 'العربية', 'emoji' => 'SA', 'direction' => 'rtl', 'is_active' => true, 'is_default' => false, 'sort_order' => 13]);

        $xpath = $this->xpath($this->newAdminPage(route('admin.languages.index')));

        // The link keeps the page direction (its text lines up with the other names) ...
        $this->assertSame(0, $xpath->query('//td[contains(@class,"c-main")]//a[@dir]')->length);
        // ... and the name inside it is isolated right to left.
        $names = $xpath->query('//td[contains(@class,"c-main")]//a/bdi[@dir="rtl"]');
        $this->assertSame(1, $names->length);
        $this->assertSame('العربية', trim($names->item(0)->textContent));
        $this->assertGreaterThan(1, $xpath->query('//td[contains(@class,"c-main")]//a/bdi[not(@dir)]')->length);
    }

    // ------------------------------------------------------------------ Import pages

    public function test_the_xvidapi_schedule_card_follows_the_admin_colours_and_has_a_neutral_example(): void
    {
        $html = $this->newAdminPage(route('admin.xvid-import.index'));
        $xpath = $this->xpath($html);

        $card = $xpath->query('//form[@id="newScheduleForm"]/parent::div')->item(0);
        $this->assertNotNull($card);
        $classes = preg_split('/\s+/', trim($card->getAttribute('class')));
        $this->assertContains('xvid-schedule-new', $classes);
        $this->assertNotContains('bg-dark', $classes);
        // The card takes the admin's soft surface and text colour (light or dark with the admin).
        $this->assertMatchesRegularExpression('/\.xvid-schedule-new\s*\{[^}]*background:\s*var\(--avd-surface-soft[^}]*color:\s*var\(--avd-text/', $html);
        $this->assertSame('e.g. Daily new videos', $xpath->query('//input[@id="ad-xvid-import-name"]')->item(0)->getAttribute('placeholder'));
        $this->assertStringNotContainsString('Daily Top Teen', $html);

        // The import progress window takes the admin's colours too (no dark box with light-admin text); its close
        // button is named and is not the white-only variant.
        $content = $xpath->query('//div[@id="progressModal"]//div[contains(concat(" ", normalize-space(@class), " "), " modal-content ")]')->item(0);
        $this->assertNotNull($content);
        $this->assertStringNotContainsString('bg-dark', $content->getAttribute('class'));
        $close = $xpath->query('//div[@id="progressModal"]//button[contains(@class,"btn-close")]')->item(0);
        $this->assertSame('Close', $close->getAttribute('aria-label'));
        $this->assertStringNotContainsString('btn-close-white', $close->getAttribute('class'));
        $this->assertSame(0, $xpath->query('//div[@id="progressModal"]//h5[contains(@class,"text-white")]')->length);
    }

    public function test_the_old_admin_xvidapi_page_keeps_its_dark_fields_and_live_card_readable_in_light_mode(): void
    {
        $html = $this->oldAdminPage(route('admin.xvid-import.index'));
        $xpath = $this->xpath($html);

        // The old admin's layout CSS darkens every .text-white in #main-container on a light page: the dark fields
        // (incl. the enhanced selects and the search icon box) keep white text there ...
        $this->assertMatchesRegularExpression('/html\[data-bs-theme="light"\] body #main-container \.xvid-page :is\(\.bg-dark\.text-white, \.input-group-text\.bg-dark\),\s*html\[data-bs-theme="light"\] body #main-container \.xvid-page \.ts-wrapper\.bg-dark :is\(\.ts-control, \.ts-control input\) \{\s*color: #fff !important;/', $html);
        foreach (['ad-xvid-import-name', 'ad-xvid-import-run-time', 'ad-xvid-import-pages-2', 'ad-xvid-import-pagesize-2', 'ad-xvid-import-frequency', 'ad-xvid-import-start-page', 'catSearchInput'] as $id) {
            $classes = preg_split('/\s+/', (string) $xpath->query('//*[@id="'.$id.'"]')->item(0)?->getAttribute('class'));
            $this->assertContains('bg-dark', $classes, $id);
            $this->assertContains('text-white', $classes, $id);
        }
        // ... and the dark "Live Ecosystem" card takes the light panel colours (its inline background needs !important).
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " xvid-live ")]')->length);
        $this->assertMatchesRegularExpression('/html\[data-bs-theme="light"\] body #main-container \.xvid-live \{\s*background: var\(--avd-surface-soft[^;]*\) !important;/', $html);
        $this->assertMatchesRegularExpression('/#main-container \.xvid-live \.bg-black \{\s*background-color: var\(--avd-surface/', $html);
        // Every one of these rules (in the page's own style block) is for the old admin only (the new admin has no
        // #main-container).
        $this->assertSame(1, preg_match('#<style>(?:(?!</style>).)*?\.xvid-schedule-new(?:(?!</style>).)*</style>#s', $html, $style));
        preg_match_all('/^\s*html\[data-bs-theme="light"\][^{]*\{/m', $style[0], $rules);
        $this->assertNotEmpty($rules[0]);
        foreach ($rules[0] as $rule) {
            $this->assertStringContainsString('#main-container', $rule);
        }
    }

    public function test_the_old_admin_avdbapi_page_keeps_its_live_card_and_ticked_categories_readable(): void
    {
        $html = $this->oldAdminPage(route('admin.avdb-import.index'));
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " avdb-live ")]')->length);
        $this->assertMatchesRegularExpression('/html\[data-bs-theme="light"\] body #main-container \.avdb-live \{\s*background: var\(--avd-surface-soft[^;]*\) !important;/', $html);
        $this->assertMatchesRegularExpression('/body #main-container \.avdb-cat-card input:checked \+ \.avdb-cat-label \{\s*color: var\(--avd-brand-ink/', $html);
        $this->assertMatchesRegularExpression('/body #main-container \.avdb-cat-card input:checked ~ \.avdb-cat-count \{\s*color: var\(--avd-text\);/', $html);
    }

    public function test_the_avdb_filters_use_neutral_placeholders(): void
    {
        $xpath = $this->xpath($this->newAdminPage(route('admin.avdb-import.index')));
        $placeholder = fn (string $id) => $xpath->query('//*[@id="'.$id.'"]')->item(0)?->getAttribute('placeholder');

        $this->assertSame('Keyword or ABC-123', $placeholder('searchWd'));
        $this->assertSame('ABC-123', $placeholder('searchCode'));
        $this->assertSame('Actor name', $placeholder('searchActor'));
        $this->assertSame('Director name', $placeholder('searchDirector'));
        $this->assertSame('Studio name', $placeholder('searchWriter'));
        $this->assertSame('Tag name', $placeholder('searchCategory'));
        $this->assertSame('2024 or 2020-2024', $placeholder('filterYear'));
    }

    public function test_the_avdb_category_names_keep_their_words_whole(): void
    {
        $html = $this->newAdminPage(route('admin.avdb-import.index'));

        $this->assertMatchesRegularExpression('/\.avdb-cat-name\s*\{[^}]*overflow-wrap:\s*break-word;/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.avdb-cat-name\s*\{[^}]*overflow-wrap:\s*anywhere/', $html);
        // Narrow cards put the count under the name instead of squeezing the name.
        $this->assertMatchesRegularExpression('/\.avdb-cat-card\s*\{[^}]*container-type:\s*inline-size;/', $html);
        $this->assertStringContainsString('@container (max-width: 14rem)', $html);
    }

    public function test_no_shipped_view_or_translation_names_the_old_examples(): void
    {
        foreach (['resources/views/admin/avdb-import/index.blade.php', 'resources/views/admin/xvid-import/index.blade.php'] as $view) {
            $source = (string) file_get_contents(base_path($view));
            foreach (['Yua Mikami', 'miaa-818', 'FNS-053', 'placeholder="Nagao"', 'placeholder="S1"', 'placeholder="Creampie"', 'Daily Top Teen'] as $old) {
                $this->assertStringNotContainsString($old, $source, "{$view} still has {$old}");
            }
        }
    }

    // ------------------------------------------------------------------ Bottom bar on phones (help text)

    public function test_the_bottom_bar_help_names_the_real_buttons_in_the_config_and_the_search_index(): void
    {
        $this->assertSame(self::BOTTOM_BAR_HELP, config('admin_settings.settings.mobile_bottom_nav_enabled.help'));
        $this->assertSame('(c) 2026 AVDBCMS. All rights reserved.', config('admin_settings.settings.footer_text.default'));

        $rows = json_decode((string) file_get_contents(resource_path('admin/lib/settings-index.json')), true);
        $row = collect($rows)->firstWhere('key', 'mobile_bottom_nav_enabled');
        $this->assertSame(self::BOTTOM_BAR_HELP, $row['help'] ?? null);
        $this->assertStringNotContainsString('Trending, Upload and Account', (string) file_get_contents(resource_path('admin/lib/settings-index.json')));
    }

    public function test_the_appearance_page_shows_the_new_bottom_bar_help(): void
    {
        $html = $this->newAdminPage('/admin/appearance/menus');

        $this->assertStringContainsString(e(self::BOTTOM_BAR_HELP), $html);
        $this->assertStringNotContainsString('Home, Trending, Upload and Account buttons', $html);
    }

    // ------------------------------------------------------------------ Translations (after i18n.json is applied)

    public function test_every_language_file_has_the_new_texts_and_none_of_the_removed_ones(): void
    {
        $added = ['e.g. Daily new videos', 'Keyword or ABC-123', 'Actor name', 'Director name', 'Studio name', '2024 or 2020-2024', 'Your database is :size.', self::BACKUPS_LINE, self::BOTTOM_BAR_HELP];
        $removed = ['Your database is :size. :count backups take :total on the server.', 'Home, Trending, Upload and Account buttons at the bottom of the phone screen.', 'Yua Mikami'];

        $files = glob(lang_path('*.json'));
        $this->assertCount(18, $files);
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($added as $key) {
                $this->assertArrayHasKey($key, $data, "{$locale} lacks \"{$key}\"");
                if ($locale !== 'en') {
                    $this->assertNotSame($key, $data[$key], "{$locale} still shows \"{$key}\" in English");
                }
            }
            foreach ($removed as $key) {
                $this->assertArrayNotHasKey($key, $data, "{$locale} still has \"{$key}\"");
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Point storage at a scratch folder whose app/backups holds exactly these files (name => size in bytes). */
    private function backupFiles(array $files): void
    {
        $this->storage = storage_path('framework/testing/lane-a-'.Str::uuid());
        File::ensureDirectoryExists($this->storage.'/app/backups');
        foreach ($files as $name => $bytes) {
            file_put_contents($this->storage.'/app/backups/'.$name, str_repeat('x', $bytes));
        }
        $this->app->useStoragePath($this->storage);
    }

    private function newAdminPage(string $url): string
    {
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        return (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
    }

    /** The same page in the old admin (admin.ui v1: the public layout with the back-office menu, #main-container). */
    private function oldAdminPage(string $url): string
    {
        config(['admin.ui' => 'v1', 'admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $html = (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('id="main-container"', $html);

        return $html;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
