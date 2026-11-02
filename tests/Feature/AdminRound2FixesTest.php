<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConfigFiles;
use App\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Round 2a's admin findings, one test each:
 *  - R2A-006: Appearance > Channels' "Accent colour" names its choices as channels do ("Look colour", "Custom color"),
 *    not "Look" / "Custom" made from the rule's values (which had no translation, or a wrong one).
 *  - R2A-055: Settings > Site basics shows the date format examples in the admin's language, and a setting's
 *    "Default: ... Reset" is the configuration files' value, not the saved one (the Site name showed the saved name).
 *  - R1-080: Tools > Health check names the generic web server and panel in the admin's language.
 *  - R2A-052: Tools > Scheduled tasks reads its cron expressions, commands, next-run times and zones left to right on a
 *    right-to-left page, on one line (the sizes with their unit everywhere in the admin: AdminSizesReadLeftToRightTest,
 *    which renders the pages; a scan of the Blade sources missed the sizes that carry their unit in a variable).
 *  - R2A-021: the Ctrl+K search has a live region for its states and its result count.
 *  - R2A-019: a progress bar coloured by its state takes the text colour made for that fill (4.5:1 in both modes).
 *  - R2A-058: the "What moved where" page links and the XVIDAPI import options are at least 24 px tall targets.
 *  - R2A-056: the old admin's Dashboard on a phone in dark mode: no full-colour cards under grey labels, header buttons
 *    that wrap whole, a Recent Users table that scrolls instead of cutting its last column.
 *  - R2A-057: a page left while it still reads in the background stops its reads itself (Safari's console stays quiet).
 */
class AdminRound2FixesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $linesDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        ConfigFiles::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->linesDir !== null) {
            @unlink($this->linesDir.DIRECTORY_SEPARATOR.'ar.json');
            @rmdir($this->linesDir);
        }

        parent::tearDown();
    }

    /** @param  array<string, string>  $lines  Arabic lines for this test, on top of lang/ar.json */
    private function arabicLines(array $lines): void
    {
        $this->linesDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r2cd-'.bin2hex(random_bytes(5));
        mkdir($this->linesDir);
        file_put_contents($this->linesDir.DIRECTORY_SEPARATOR.'ar.json', json_encode($lines, JSON_UNESCAPED_UNICODE));
        app('translator')->addJsonPath($this->linesDir);
    }

    private function field(string $pageId, string $key): array
    {
        $form = app(SettingsPageController::class)->form(Request::create('/admin'), $pageId);

        foreach (array_merge($form['cards'], $form['advancedCards']) as $card) {
            foreach (array_merge($card['fields'], $card['more']) as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }

        $this->fail("no field {$key} on {$pageId}");
    }

    public function test_the_channel_accent_names_its_choices_as_channels_do(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        config(['admin.ui' => 'v2']);

        $options = $this->field('appearance.channels', 'channel_default_accent')['options'];

        $this->assertSame('Look colour', $options['look'] ?? null);
        // R3-078: British spelling, like the rest of the admin ("Look colour ... Custom colour").
        $this->assertSame('Custom colour', $options['custom'] ?? null);
        $this->assertSame('Coral', $options['coral'] ?? null);

        $html = (string) $this->get('/admin/appearance/channels')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="look"[^>]*>'.preg_quote(e(__('Look colour')), '#').'</option>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="look"[^>]*>Look</option>#', $html);
    }

    public function test_a_default_is_the_configuration_files_value_not_the_saved_one(): void
    {
        $files = (string) ConfigFiles::value('app.name');
        $this->assertNotSame('', $files);

        // what AppServiceProvider::applySettings() does on every request of a site whose owner saved a name
        Setting::set('site_name', 'Saved Name '.$files, 'general');
        config(['app.name' => 'Saved Name '.$files]);
        config(['admin.ui' => 'v2']);
        $this->actingAs(User::factory()->admin()->create());

        $field = $this->field('settings.site', 'site_name');
        $this->assertSame('Saved Name '.$files, $field['value']);
        $this->assertSame($files, $field['default'], 'Reset gives the name of the configuration, not the saved one');

        $html = (string) $this->get('/admin/settings/site')->assertOk()->getContent();
        $this->assertStringContainsString('data-default="'.e($files).'"', $html);
    }

    public function test_the_date_format_examples_are_in_the_admins_language(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $this->actingAs(User::factory()->admin()->create());
        app()->setLocale('ar');

        $options = $this->field('settings.site', 'date_format')['options'];

        $this->assertSame(now()->locale('ar')->translatedFormat('M d, Y'), $options['M d, Y']);
        $this->assertNotSame('Oct 28, 2026', $options['M d, Y']);
        $this->assertSame('2026-10-28', $options['Y-m-d']);
    }

    public function test_the_health_check_names_the_generic_server_and_panel_in_the_admins_language(): void
    {
        $this->arabicLines(['CLI / console' => 'سطر الأوامر / وحدة التحكم', 'None / generic host' => 'لا يوجد / استضافة عامة']);
        app()->setLocale('ar');

        $this->assertSame('سطر الأوامر / وحدة التحكم', Environment::shownLabel('CLI / console'));
        $this->assertSame('Nginx', Environment::shownLabel('Nginx'), 'a product name as it is');
        $this->assertSame('cPanel / WHM', Environment::shownLabel('cPanel / WHM'));

        config(['admin.ui' => 'v2']);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->withSession(['app_locale' => 'ar'])
            ->get(route('admin.preflight.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e(Environment::shownLabel(Environment::webServerLabel())), $html);
        $this->assertStringContainsString(e(Environment::shownLabel(Environment::panelLabel())), $html);
        $this->assertStringNotContainsString('>'.e(Environment::webServerLabel()).'</div>', $html);
    }

    public function test_the_scheduled_tasks_read_their_codes_left_to_right(): void
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('admin.cron.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<td><code dir="ltr" class="text-nowrap">[0-9*/ ,-]+</code></td>#', $html, 'the expression');
        $this->assertStringContainsString('<small class="text-muted text-nowrap" dir="ltr">', $html, 'the next run');
        $this->assertStringContainsString('<code dir="ltr">views:prune-history</code>', $html, 'the command');
        $this->assertMatchesRegularExpression('#<div class="h4 mb-0 text-nowrap" dir="ltr">[0-9.,]+ KB</div>#', $html, 'the cron log size (on one line)');
        $this->assertMatchesRegularExpression('#<span dir="ltr">\d+x / \d+s</span>#', $html, 'the remote lookup limits');
    }

    public function test_the_search_window_has_a_live_region(): void
    {
        config(['admin.ui' => 'v2']);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<div id="ad-palette-status" class="ad-vh" role="status" aria-live="polite" aria-atomic="true"\s+data-results="[^"]+"></div>#', $html);

        $script = (string) file_get_contents(resource_path('admin/palette.ts'));
        $this->assertStringContainsString("status = dialog ? $('#ad-palette-status', dialog) : null;", $script);
        $this->assertStringContainsString('announce(waiting', $script, 'loading, failure, nothing found and the count are announced');
    }

    public function test_a_progress_bar_with_a_state_colour_reads_at_4_5_to_1_in_both_modes(): void
    {
        $bridge = (string) file_get_contents(resource_path('admin/components/_bridge.scss'));
        $this->assertMatchesRegularExpression('/\.progress-bar\s*\{\s*&\.bg-success,\s*&\.bg-info,\s*&\.bg-danger\s*\{\s*color:\s*#fff;/', $bridge);
        $this->assertMatchesRegularExpression('/&\.bg-warning\s*\{\s*color:\s*var\(--on-fill-dark\);/', $bridge);

        $tokens = (string) file_get_contents(resource_path('admin/components/_tokens.scss'));
        preg_match_all('/(ok|warn|danger|info)-fill: (#[0-9a-f]{6})/', $tokens, $fills, PREG_SET_ORDER);
        $this->assertCount(8, $fills, 'light and dark fills');
        preg_match('/--on-fill-dark: (#[0-9a-f]{6})/', $tokens, $dark);

        foreach ($fills as [, $state, $fill]) {
            $text = $state === 'warn' ? $dark[1] : '#ffffff';
            $this->assertGreaterThanOrEqual(4.5, self::contrast($fill, $text), "{$state} fill {$fill} with {$text}");
        }
    }

    public function test_small_links_and_options_are_24_px_targets(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $moved = (string) $this->actingAs($admin)->get('/admin/shell/moved')->assertOk()->getContent();
        $this->assertStringContainsString('.ad-moved-page > a{display:inline-flex;align-items:center;min-block-size:24px}', $moved);
        $this->assertDoesNotMatchRegularExpression('#</span><br>\s*<span class="ad-moved-page">#', $moved, 'one page per line by the layout, not by <br>');

        $view = (string) file_get_contents(resource_path('views/admin/xvid-import/index.blade.php'));
        $this->assertMatchesRegularExpression('/#translateTagsCheck \+ \.form-check-label,\s*#skipTagsCheck \+ \.form-check-label,\s*#dryRunCheck \+ \.form-check-label \{\s*display: inline-flex;\s*align-items: center;\s*min-block-size: 24px;/', $view);

        $shell = (string) file_get_contents(resource_path('admin/components/_shell.scss'));
        $this->assertMatchesRegularExpression('/\.ad-crumbs \{[^}]*?a \{\s*display: inline-flex;\s*align-items: center;\s*min-block-size: 24px;/s', $shell);
    }

    public function test_the_old_dashboard_keeps_its_cards_readable_and_its_buttons_whole_on_a_phone(): void
    {
        config(['admin.ui' => 'v1']);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->getContent();

        $this->assertStringNotContainsString('bg-opacity-10', $html, 'no state colour painted under grey labels');
        $this->assertStringContainsString('<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">', $html);
        $this->assertSame(4, substr_count($html, ' text-nowrap"><i class="fa-solid fa-'), 'the four header buttons never break inside a word');
        $this->assertMatchesRegularExpression('#<h6 class="mb-0"><i class="fa-solid fa-user-plus me-1"></i>[^<]+</h6>.*?<div class="table-responsive" tabindex="0">#s', $html, 'Recent Users scrolls');

        $studio = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        $this->assertStringContainsString('<span class="form-check-label small fw-bold">{{__(\'Sync Styles on Save\')}}</span>', $studio);
        $this->assertStringContainsString('class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" id="btnStudioExportTheme"', $studio);
    }

    /**
     * R2A-057: leaving an admin page while it still reads in the background left Safari's console with "Fetch API /
     * XMLHttpRequest cannot load ... due to access control checks." and unhandled AxiosError rejections (Home's deferred
     * parts, the Horizon dashboard). The browser proof is runs/checks_r2a057_webkit_*.json (lane CD); here: the pieces.
     */
    public function test_pages_left_while_they_read_stop_their_reads_themselves(): void
    {
        // the admin's background reads (resources/admin/lib/dom.ts): stopped when leaving begins, run again when the page stays
        $dom = (string) file_get_contents(resource_path('admin/lib/dom.ts'));
        $this->assertStringContainsString('if (!event.defaultPrevented) leave();', $dom, 'a leave the page asks to confirm stops nothing');
        $this->assertStringContainsString("window.addEventListener('pagehide', leave);", $dom);
        $this->assertMatchesRegularExpression('/export const backgroundGet = async \([^)]*\)[^{]*\{\s*watchLeave\(\);/', $dom);
        $this->assertStringContainsString('underWay.forEach((controller) => controller.abort());', $dom);
        $this->assertStringContainsString('if (leaving) await new Promise<void>((resolve) => waiting.push(resolve));', $dom);
        $this->assertStringContainsString('if (controller.signal.aborted && !signal?.aborted) continue;', $dom);
        $this->assertStringContainsString("const html = page ? await page.text().catch(() => '') : '';", (string) file_get_contents(resource_path('admin/modules/list-probes.ts')));

        // the Horizon dashboard (the package's own page) gets its guard: once, in its head, never in its API answers
        $connection = \Mockery::mock();
        $connection->shouldReceive('command')->with('PING')->andReturn(true);
        \Illuminate\Support\Facades\Redis::shouldReceive('connection')->andReturn($connection);
        $middleware = new \App\Http\Middleware\EnsureHorizonAvailable();

        $page = $middleware->handle(Request::create('/admin/horizon'), fn () => response('<!DOCTYPE html><html><head><title>Horizon</title></head><body></body></html>'));
        $html = (string) $page->getContent();
        $this->assertSame(1, substr_count($html, '<script data-avd-left-guard>'));
        $this->assertStringContainsString('<head><script data-avd-left-guard>', $html);

        foreach (['this.__avdRead=String(m).toUpperCase()==="GET"', 'live.splice(0).forEach(function(x){try{x.abort();}catch(e){}})',
                     'held[x.__avdUrl]=[x,a]', 'addEventListener("beforeunload",leave)', 'addEventListener("pageshow"', 'timer=setTimeout(stay,30000)',
                     'if(left&&(r.isAxiosError'] as $part) {
            $this->assertStringContainsString($part, $html);
        }

        $again = $middleware->handle(Request::create('/admin/horizon/dashboard'), fn () => response($html));
        $this->assertSame(1, substr_count((string) $again->getContent(), 'data-avd-left-guard'), 'a page that has it keeps one');

        $api = $middleware->handle(Request::create('/admin/horizon/api/stats'), fn () => response()->json(['jobsPerMinute' => 0]));
        $this->assertStringNotContainsString('data-avd-left-guard', (string) $api->getContent());
    }

    private static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static fn (string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
            $linear = array_map(static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        [$light, $dark] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }
}
