<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CronController;
use App\Http\Controllers\Admin\PreflightController;
use App\Models\AvdbImportSchedule;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AdminText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 texts of the admin:
 *  - R1-079 (and the same pattern in every admin controller): the answers of the admin's actions go through __();
 *  - R1-080: the Health check's Server and Checks tabs, Scheduled tasks and Commands in the admin's language;
 *  - R1-081: Content > Import videos (AVDBAPI, XVIDAPI) without hard-coded English, the script's texts included;
 *  - R1-153: an English menu path inside a right-to-left text is isolated (it kept its arrows and its order);
 *  - R1-158: a signed number of Home's metrics is isolated on a right-to-left page ("100%-");
 *  - R1-162: the old admin's colour check reads any colour and its words are translated (it said "NaN:1");
 *  - R1-076: Ctrl+K has its "searching" and "could not load" texts;
 *  - R1-154: a view-only demo account sees its own badge and switches it cannot flip.
 */
class AdminRound1TextsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2', 'app.locale' => 'en']);
    }

    public function test_no_admin_controller_answers_with_an_untranslated_message(): void
    {
        $patterns = [
            "/withSuccess\\(\\s*(['\"])[A-Za-z]/",
            "/withErrors\\(\\[\\s*'[\\w.]+'\\s*=>\\s*(['\"])[A-Za-z]/",
            "/->with\\(\\s*'(?:success|error|info|warning|status)'\\s*,\\s*(['\"])[A-Za-z]/",
            "/'message'\\s*=>\\s*(['\"])[A-Z][^'\"]{3,}/",
            // withErrors('...') / withError("..."), a flash chosen by ?: with an English text that holds a value, an
            // English text kept in a variable for the flash, a log error text
            "/->withErrors?\\(\\s*(['\"])[A-Za-z]/",
            "/[?:]\\s*\"[A-Z][a-z][^\"]*\\{\\$/",
            "/\\$(?:msg|message)\\s*=\\s*(['\"])[A-Z]/",
            "/'log'\\s*=>\\s*\"[A-Z]/",
        ];
        $found = [];

        foreach (glob(app_path('Http/Controllers/Admin/*.php')) as $file) {
            foreach (file($file) as $number => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $found[] = basename($file).':'.($number + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_tools_messages_follow_the_admin_language(): void
    {
        // A second JSON folder with Arabic lines for these texts (lang/ar.json, when it has them, comes after it and
        // wins): the answers must be the Arabic lines, so they went through the translator.
        $folder = storage_path('framework/testing/r1fd-lang-'.uniqid());
        @mkdir($folder, 0777, true);
        file_put_contents($folder.'/ar.json', json_encode([
            'Job #:id was not found.' => 'TR job :id',
            'This table cannot be emptied here: :table' => 'TR table :table',
            'Config cache cleared.' => 'TR config',
        ]));
        app('translator')->addJsonPath($folder);
        app('translator')->setLoaded([]);
        config(['admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        try {
            foreach ([
                ['error', fn () => $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->from('/admin/queue')->post(route('admin.queue.jobs.pause', 987654)),
                    'Job #:id was not found.', ['id' => 987654]],
                ['error', fn () => $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->delete(route('admin.database.truncate', 'users')),
                    'This table cannot be emptied here: :table', ['table' => 'users']],
                ['success', fn () => $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->post(route('admin.system.clear', 'config')),
                    'Config cache cleared.', []],
            ] as [$kind, $request, $key, $replace]) {
                $expected = __($key, $replace, 'ar');
                $this->assertNotSame(__($key, $replace, 'en'), $expected);
                $request()->assertSessionHas($kind, $expected);
            }
        } finally {
            @unlink($folder.'/ar.json');
            @rmdir($folder);
        }
    }

    public function test_a_member_message_follows_the_admin_language(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->withSession(['app_locale' => 'ar'])
            ->post(route('admin.users.toggle-admin', $member))
            ->assertSessionHas('success', __('Now an administrator', [], 'ar'));

        $this->assertNotSame('Now an administrator', __('Now an administrator', [], 'ar'));
    }

    public function test_the_health_check_server_tab_speaks_the_admin_language(): void
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())->withSession(['app_locale' => 'ar'])
            ->get(route('admin.system.index'))->assertOk()->getContent();

        foreach (['Memory Limit', 'Debug Mode', 'Cache Driver'] as $label) {
            $this->assertStringContainsString('>'.e(__($label, [], 'ar')).'</td>', $html, $label);
            $this->assertStringNotContainsString('>'.$label.'</td>', $html, $label);
        }
        // A value that is no word (a version, a number such as -1, a path) reads left to right on the Arabic page.
        $this->assertMatchesRegularExpression('/<code\s+dir="ltr"\s*>'.preg_quote(e(PHP_VERSION), '/').'<\/code>/', $html);
    }

    public function test_the_commands_and_database_pages_speak_the_admin_language(): void
    {
        $folder = storage_path('framework/testing/r1fd-lang-'.uniqid());
        @mkdir($folder, 0777, true);
        file_put_contents($folder.'/ar.json', json_encode(['Waiting for a command...' => 'TR waiting']));
        app('translator')->addJsonPath($folder);
        app('translator')->setLoaded([]);
        config(['admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        try {
            $commands = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.artisan.index'))->assertOk()->getContent();
            $this->assertStringContainsString(e('> '.__('Waiting for a command...', [], 'ar')), $commands);
            $this->assertStringNotContainsString('Waiting for command...', $commands);

            $database = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.database.index'))->assertOk()->getContent();
            $this->assertStringNotContainsString('<td>TOTAL</td>', $database);
            $this->assertStringContainsString('<td>'.e(__('Total', [], 'ar')).'</td>', $database);
        } finally {
            @unlink($folder.'/ar.json');
            @rmdir($folder);
        }
    }

    public function test_the_auto_configuration_lines_are_keys_the_health_check_fills_in(): void
    {
        // Each listed line is still the provider's own text (a change there must change the list and its translations).
        $provider = (string) file_get_contents(app_path('Providers/EnvironmentServiceProvider.php'));
        foreach (PreflightController::AUTO_CONFIGURATION_MESSAGES as $message) {
            $this->assertStringContainsString("'".str_replace("'", "\\'", $message)."'", $provider, $message);
        }

        // The older lines that put a value into their English text: each template's words are still the provider's
        // (around its value), so the Health check recognises the line.
        foreach (PreflightController::AUTO_CONFIGURATION_LINES_WITH_A_VALUE as $line) {
            foreach (preg_split('/:[a-z_]+/', $line) as $words) {
                if ($words !== '') {
                    $this->assertStringContainsString($words, $provider, $line);
                }
            }
        }

        // The Health check (page and JSON) shows a line with its values filled in, in the admin's language.
        config([
            'admin.operational_tools_enabled' => true,
            'site.environment_adjustments' => [
                ['scope' => 'trusted_proxies', 'message' => 'Applied trusted proxy list: :proxies', 'replace' => ['proxies' => '10.0.0.9, 10.0.0.10'], 'severity' => 'info'],
                ['scope' => 'trusted_proxies', 'message' => 'Trusted the current proxy IP from REMOTE_ADDR: 10.0.0.7', 'severity' => 'info'],
                ['scope' => 'sanctum.stateful', 'message' => 'Appended APP_URL host to sanctum.stateful: example.test:8080', 'severity' => 'info'],
                ['scope' => 'streaming', 'message' => "Selected 'x-accel' stream accelerator based on detected web server.", 'severity' => 'info'],
                ['scope' => 'app.url', 'message' => 'Auto-set app.url from the current HTTP request: https://example.test/site', 'severity' => 'info'],
                ['scope' => 'x', 'message' => 'A line nobody wrote a template for: 42', 'severity' => 'info'],
            ],
        ]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson(route('admin.preflight.json'))->assertOk()
            ->assertJsonPath('adjustments.0.message', 'Applied trusted proxy list: 10.0.0.9, 10.0.0.10')
            ->assertJsonMissingPath('adjustments.0.replace')
            ->assertJsonPath('adjustments.1.message', 'Trusted the current proxy IP from REMOTE_ADDR: 10.0.0.7')
            ->assertJsonPath('adjustments.5.message', 'A line nobody wrote a template for: 42');

        // In Arabic: the Arabic sentence with the value, for a key and for each older line (two Arabic lines for texts
        // lang/ar.json gets at the merge).
        $this->arabicLines([
            'Trusted the current proxy IP from REMOTE_ADDR: :address' => 'تم الوثوق بعنوان IP للوكيل الحالي من REMOTE_ADDR: :address',
            "Selected ':accelerator' stream accelerator based on detected web server." => "تم اختيار مسرّع البث ':accelerator' حسب خادم الويب المكتشف.",
        ]);
        $html = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.preflight.index'))->assertOk()->getContent();
        // Each value isolated left to right (R1R-09: the Health check's lines keep their order in Arabic).
        $isolated = fn (string $text, string $value): string => str_replace(e($value), '<bdi dir="ltr">'.e($value).'</bdi>', e($text));
        $this->assertStringContainsString($isolated(__('Applied trusted proxy list: :proxies', ['proxies' => '10.0.0.9, 10.0.0.10'], 'ar'), '10.0.0.9, 10.0.0.10'), $html);
        $this->assertStringContainsString($isolated('تم الوثوق بعنوان IP للوكيل الحالي من REMOTE_ADDR: 10.0.0.7', '10.0.0.7'), $html);
        $this->assertStringContainsString($isolated("تم اختيار مسرّع البث 'x-accel' حسب خادم الويب المكتشف.", 'x-accel'), $html);
        $this->assertStringNotContainsString(':address', $html);
        $this->assertStringNotContainsString(':proxies', $html);
        $this->assertStringContainsString(e(__('Streaming', [], 'ar')), $html);
    }

    public function test_the_admin_wording_of_round_one(): void
    {
        // R1-159: the brand is PayPal; a status the rest of the admin calls Scheduled is not "Planned" here.
        $this->assertSame('Premium page: PayPal text', config('admin_settings.settings.premium_paypal_label.label'));
        $this->assertSame('Scheduled', \App\Enums\VideoStatus::getActive()[\App\Enums\VideoStatus::PLANNED->value]);
        $this->assertNotContains('Planned', \App\Enums\VideoStatus::getActive());
    }

    public function test_every_scheduled_task_is_described_in_plain_words(): void
    {
        $this->assertSame('Tells subscribers when a scheduled video becomes public', CronController::describe(['command' => "'C:\\php\\php.exe' 'artisan' video:published", 'description' => 'Send notification to subscribers when planned video become public for the first time']));
        $this->assertSame('Removes expired API keys', CronController::describe(['command' => 'php artisan sanctum:prune-expired', 'description' => null]));
        $this->assertSame('Some own text', CronController::describe(['command' => 'php artisan something:else', 'description' => 'Some own text']));
        $this->assertNull(CronController::describe(['command' => 'php artisan something:else', 'description' => '']));

        foreach (CronController::listScheduledTasks() as $task) {
            $this->assertNotNull(CronController::describe($task), 'a description for '.$task['command']);
        }
    }

    public function test_the_import_pages_have_no_hard_coded_english(): void
    {
        $admin = User::factory()->admin()->create();
        $html = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.avdb-import.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ID 1: '.e(__('Censored', [], 'ar')), $html);
        $this->assertStringContainsString('const avdbText = ', $html);
        foreach (["'Testing...'", "Test Connection';", "status.textContent = 'Connected'", "'Calculating'", 'Awaiting import command...</div>', '日本語 (Japanese)'] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }

        $schedule = new AvdbImportSchedule(['frequency' => AvdbImportSchedule::FREQUENCY_HOURLY, 'run_time' => '00:30']);
        app()->setLocale('en');
        $this->assertSame('Every hour, at minute 30', $schedule->scheduleLabel());
        // The schedules' summaries go through the translator too (the Arabic text once lang/ar.json has it).
        app()->setLocale('ar');
        $this->assertSame(__('Every hour, at minute :minute', ['minute' => '30'], 'ar'), $schedule->scheduleLabel());
        app()->setLocale('en');

        $xvid = (string) $this->actingAs($admin)->get(route('admin.xvid-import.index'))->assertOk()->getContent();
        $this->assertStringContainsString('const xvidText = ', $xvid);
        $this->assertStringNotContainsString("alert('Error: ' + e.message)", $xvid);
    }

    public function test_the_import_pages_name_categories_languages_and_the_rate_in_the_admin_language(): void
    {
        // The AVDBAPI category picker and its "Fixed locale" list printed the English names, while the badges and the
        // "Fetch content in" list of the same page were translated; the rate read "0/min" and the XVIDAPI catalogue
        // badges were English. Two Arabic lines for texts lang/ar.json gets at the merge are added here (after the
        // Arabic file is loaded).
        $this->arabicLines([':rate/min' => ':rate في الدقيقة', 'VR (Virtual Reality)' => 'الواقع الافتراضي (VR)']);
        $admin = User::factory()->admin()->create();

        $html = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.avdb-import.index'))->assertOk()->getContent();
        foreach (['Censored', 'Uncensored Leaked', 'Chinese AV', 'English Subtitle'] as $category) {
            $this->assertNotSame($category, __($category, [], 'ar'));
            $this->assertStringContainsString('<span class="avdb-cat-name">'.e(__($category, [], 'ar')).'</span>', $html, $category);
            $this->assertStringNotContainsString('<span class="avdb-cat-name">'.e($category).'</span>', $html, $category);
        }
        foreach (['ja' => 'Japanese', 'de' => 'German', 'ar' => 'Arabic'] as $code => $language) {
            $this->assertStringContainsString('<option value="'.$code.'">'.e(__($language, [], 'ar')).'</option>', $html, $language);
            $this->assertStringNotContainsString('<option value="'.$code.'">'.$language.'</option>', $html, $language);
        }
        $this->assertStringContainsString('id="resultRate">0 في الدقيقة</div>', $html);
        $this->assertStringContainsString('"rateZero":'.json_encode('0 في الدقيقة', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), $html);
        $this->assertStringNotContainsString("'0/min'", $html);

        $xvid = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.xvid-import.index'))->assertOk()->getContent();
        $this->assertStringContainsString('الواقع الافتراضي (VR)</span>', $xvid);
        $this->assertStringNotContainsString('>VR (Virtual Reality)</span>', $xvid);
    }

    public function test_an_english_menu_path_is_isolated_on_a_right_to_left_page(): void
    {
        $text = 'Create your own key at upload18.org > API > Keys & security > Restricted keys: tick Upload and Read.';

        $this->assertSame($text, AdminText::isolatePaths($text, false), 'left to right: unchanged');
        // An English sentence on a right-to-left page is isolated from its first word up to the path's end (it is all
        // left to right anyway); in a translated sentence only the path is.
        $this->assertSame("\u{2066}Create your own key at upload18.org > API > Keys & security > Restricted keys\u{2069}: tick Upload and Read.", AdminText::isolatePaths($text, true));
        $this->assertSame("في \u{2066}Upload18 > API > Keys & security\u{2069}، ولا", AdminText::isolatePaths('في Upload18 > API > Keys & security، ولا', true));
        $this->assertSame('No path here.', AdminText::isolatePaths('No path here.', true));
        // A key prefix ends with "_", which a right-to-left line moves to the other end ("_whsec"): isolate() keeps it.
        $this->assertSame("يبدأ بـ \u{2066}whsec_\u{2069}.", AdminText::isolate('يبدأ بـ whsec_.', true));
        $this->assertSame("\u{2066}pk_live_\u{2069} (\u{2066}pk_test_\u{2069}) video_translations", AdminText::isolate('pk_live_ (pk_test_) video_translations', true));
        $this->assertSame('starts with whsec_.', AdminText::isolate('starts with whsec_.', false));
        $this->assertSame("في \u{2066}Upload18 > API\u{2069}", AdminText::isolate('في Upload18 > API', true));

        // A page isolates with markup (html()): a <bdi dir="ltr"> per path and key prefix, the text escaped, nothing
        // invisible to be copied with a word or an address; left to right the text is only escaped.
        $this->assertSame('في <bdi dir="ltr">Upload18 &gt; API &gt; Keys &amp; security</bdi>، ولا', (string) AdminText::html('في Upload18 > API > Keys & security، ولا', [], true));
        $this->assertSame('يبدأ بـ <bdi dir="ltr">whsec_</bdi>.', (string) AdminText::html('يبدأ بـ whsec_.', [], true));
        $this->assertSame('&lt;b&gt; <bdi dir="ltr">pk_live_</bdi> (<bdi dir="ltr">pk_test_</bdi>) video_translations', (string) AdminText::html('<b> pk_live_ (pk_test_) video_translations', [], true));
        $this->assertSame('في <bdi dir="ltr">Developers &gt; Webhooks &gt; whsec_</bdi>، نعم', (string) AdminText::html('في Developers > Webhooks > whsec_، نعم', [], true), 'a prefix inside a path is isolated once');
        $this->assertSame('a &gt; b &amp; whsec_', (string) AdminText::html('a > b & whsec_', [], false));
        $this->assertSame('add <code dir="ltr">https://x.test/a?b=1&amp;c=2</code> to <bdi dir="ltr">whsec_</bdi>', (string) AdminText::html('add :url to whsec_', [':url' => '<code dir="ltr">'.e('https://x.test/a?b=1&c=2').'</code>'], true));

        $html = (string) $this->actingAs(User::factory()->admin()->create())->withSession(['app_locale' => 'ar'])
            ->get('/admin/settings/videos')->assertOk()->getContent();
        $this->assertStringContainsString('<bdi dir="ltr">Upload18 &gt; API &gt; Keys &amp; security</bdi>', $html, 'the Upload18 key help');
        // The card's how-to: the whole menu path in one isolated run (English, or inside the Arabic sentence).
        $this->assertMatchesRegularExpression('#<bdi dir="ltr">[^<]*upload18\.org &gt; API &gt; Keys &amp; security &gt; Restricted keys</bdi>#u', $html, 'the Upload18 card text');
        $this->assertStringNotContainsString("\u{2066}Upload18", $html);
        $this->assertStringNotContainsString("\u{2066}Create your own key", $html);
    }

    public function test_a_signed_number_of_the_home_metrics_is_isolated_right_to_left(): void
    {
        app()->setLocale('ar');
        $items = [['label' => 'Views', 'value' => '10', 'delta' => '-100%'], ['label' => 'New videos', 'value' => '3', 'delta' => '+3 اليوم']];

        $view = $this->blade('<x-admin.metrics :items="$items" />', ['items' => $items]);
        $view->assertSee('<bdi dir="ltr">-100%</bdi>', false)->assertSee('<bdi dir="ltr">+3</bdi> اليوم', false)
            ->assertDontSee("\u{2066}", false);

        app()->setLocale('en');
        $this->blade('<x-admin.metrics :items="$items" />', ['items' => $items])->assertSee('>-100%<', false)->assertDontSee('<bdi', false);
    }

    public function test_the_old_colour_check_reads_any_colour_and_speaks_the_admin_language(): void
    {
        config(['admin.ui' => 'v1']);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get('/admin/settings?tab=appearance')->assertOk()->getContent();

        $this->assertStringContainsString('function stageContrastRatio(', $html, 'its own names: the palette tools declare getLuminance(r, g, b) too');
        $this->assertStringContainsString('const contrastWords = ', $html);
        $this->assertStringNotContainsString('Contrast Alert (', $html);
        $this->assertStringNotContainsString('WCAG AAA (12.8:1)', $html);
    }

    public function test_ctrl_k_has_its_waiting_and_failed_texts(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()
            ->assertSee('data-search-loading="Searching pages and settings..."', false)
            ->assertSee('data-search-failed="Pages and settings could not be loaded. Type again to try once more."', false);
    }

    public function test_a_view_only_account_sees_its_badge_and_switches_it_cannot_flip(): void
    {
        $viewOnly = User::factory()->admin()->create();
        $viewOnly->forceFill(['is_view_only' => true])->save();

        $html = (string) $this->actingAs($viewOnly->fresh())->get('/admin')->assertOk()->getContent();
        $menu = substr($html, strpos($html, 'id="ad-account-menu"'), 6000);
        $this->assertStringContainsString('<span data-account-badge>View-only demo</span>', $menu);
        $this->assertStringContainsString('data-view-only-note', $menu);
        $this->assertMatchesRegularExpression('/data-ad-pref="developer_mode"[^>]*disabled/', $menu);
        $this->assertMatchesRegularExpression('/name="admin_theme" value="dark"[^>]*disabled/', $menu);

        $admin = User::factory()->admin()->create();
        $menu = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $menu = substr($menu, strpos($menu, 'id="ad-account-menu"'), 6000);
        $this->assertStringContainsString('<span data-account-badge>Administrator</span>', $menu);
        $this->assertDoesNotMatchRegularExpression('/data-ad-pref="developer_mode"[^>]*disabled/', $menu);
    }

    /**
     * Arabic lines for texts that lang/ar.json gets at the merge (the lane's i18n.json), set on the loaded Arabic file
     * (Translator::addLines() cannot take a key with a full stop).
     *
     * @param  array<string, string>  $lines
     */
    private function arabicLines(array $lines): void
    {
        $translator = app('translator');
        $translator->get('Streaming', [], 'ar');
        $loaded = new \ReflectionProperty($translator, 'loaded');
        $all = $loaded->getValue($translator);
        foreach ($lines as $english => $arabic) {
            $all['*']['*']['ar'][$english] = $arabic;
        }
        $loaded->setValue($translator, $all);
    }
}
