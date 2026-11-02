<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PreflightController;
use App\Models\User;
use App\Support\Environment;
use App\Support\ServerChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Round 1 remainder R1R-09: Tools > Health check showed the results of its "Auto-fix" (App\Support\EnvironmentRepairer:
 * "Created missing directory: storage/logs", "Could not create storage target: ...") and the folders it creates on any
 * request in English, with an English badge (SUCCESS / WARN). They stay stored in English (the session, app:doctor) and
 * are shown in the admin's language, recognised by their words like the older auto-configuration lines, with the folder
 * isolated (<bdi dir="ltr">) so an Arabic sentence keeps its order. Round 1b R1B-003 / R1B-007: the Streaming and the new
 * Clean addresses lines tell what the site really does, and the page names a guide the package has.
 */
class HealthCheckRepairLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_listed_line_is_still_the_repairers_text(): void
    {
        // The repairer's source with its string concatenations joined ('a ' . 'b' -> 'a b').
        $source = (string) file_get_contents(app_path('Support/EnvironmentRepairer.php'));
        $source = preg_replace("/'\\s*\\.\\s*\\R?\\s*'/", '', $source);

        foreach (PreflightController::REPAIR_MESSAGES as $message) {
            $this->assertStringContainsString("'".str_replace("'", "\\'", $message)."'", $source, $message);
        }

        foreach (PreflightController::REPAIR_LINES_WITH_A_VALUE as $line) {
            foreach (preg_split('/(?<![A-Za-z0-9_]):[a-z_]+/', $line) as $words) {
                if ($words !== '') {
                    $this->assertStringContainsString($words, $source, $line);
                }
            }
        }
    }

    public function test_auto_fix_results_are_shown_in_the_admins_language_with_the_folder_isolated(): void
    {
        $this->arabic([
            'Created missing directory: :folder' => 'تم إنشاء المجلد الناقص: :folder',
            'Could not create storage target: :folder' => 'تعذر إنشاء هدف التخزين: :folder',
            'Standard file permissions applied (files: :files, directories: :directories, :script: :artisan, :envfile: :env).' => 'طُبقت الأذونات القياسية (الملفات: :files، المجلدات: :directories، :script: :artisan، :envfile: :env).',
            'Generated missing APP_KEY in .env.' => 'تم إنشاء APP_KEY الناقص في .env.',
            'Done' => 'تم',
            'Warning' => 'تحذير',
            'Filesystem' => 'نظام الملفات',
            'Permissions' => 'الأذونات',
        ]);

        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->withSession(['app_locale' => 'ar', 'preflight_repairs' => [
                ['scope' => 'filesystem', 'message' => 'Created missing directory: storage/logs', 'severity' => 'success'],
                ['scope' => 'filesystem', 'message' => 'Could not create storage target: storage/app/public', 'severity' => 'warn'],
                ['scope' => 'permissions', 'message' => 'Standard file permissions applied (files: 644, directories: 755, artisan: 755, .env: 660).', 'severity' => 'success'],
                ['scope' => 'app.key', 'message' => 'Generated missing APP_KEY in .env.', 'severity' => 'success'],
                ['scope' => 'x', 'message' => 'A line nobody wrote a template for: <b>42</b>', 'severity' => 'info'],
            ]])
            ->get(route('admin.preflight.index'))->assertOk()->getContent();

        $this->assertStringContainsString('تم إنشاء المجلد الناقص: <bdi dir="ltr">storage/logs</bdi>', $html);
        $this->assertStringContainsString('تعذر إنشاء هدف التخزين: <bdi dir="ltr">storage/app/public</bdi>', $html);
        $this->assertStringContainsString('(الملفات: <bdi dir="ltr">644</bdi>، المجلدات: <bdi dir="ltr">755</bdi>، <bdi dir="ltr">artisan</bdi>: <bdi dir="ltr">755</bdi>، <bdi dir="ltr">.env</bdi>: <bdi dir="ltr">660</bdi>)', $html);
        $this->assertStringContainsString(e('تم إنشاء APP_KEY الناقص في .env.'), $html);
        $this->assertStringContainsString(e('A line nobody wrote a template for: <b>42</b>'), $html, 'any other line as written, escaped');
        $this->assertStringNotContainsString('Created missing directory', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*(SUCCESS|WARN|INFO)\s*</', $html, 'the badges in the admin\'s language');
        $this->assertMatchesRegularExpression('/>\s*تم\s*</u', $html);

        // The step names: the words in the admin's language, a setting's or a command's name as it is, left to right.
        $this->assertStringContainsString('<div class="fw-semibold">نظام الملفات</div>', $html);
        $this->assertStringContainsString('<div class="fw-semibold">الأذونات</div>', $html);
        $this->assertStringContainsString('<div class="fw-semibold"><bdi dir="ltr">app.key</bdi></div>', $html);
        $this->assertStringNotContainsString('<div class="fw-semibold">permissions</div>', $html);
        $this->assertStringNotContainsString('<div class="fw-semibold">filesystem</div>', $html);
    }

    public function test_every_step_name_with_a_label_is_one_the_code_gives(): void
    {
        $source = file_get_contents(app_path('Support/EnvironmentRepairer.php')).file_get_contents(app_path('Providers/EnvironmentServiceProvider.php'));

        foreach (array_keys(PreflightController::SCOPE_LABELS) as $scope) {
            if ($scope === 'runtime') {
                continue; // the page's own name for a line without one
            }

            $this->assertStringContainsString("'".$scope."'", $source, $scope);
        }
    }

    public function test_a_folder_the_site_created_on_this_request_is_listed_in_the_admins_language(): void
    {
        $this->arabic(['Created missing directory: :folder' => 'تم إنشاء المجلد الناقص: :folder']);
        config(['site.environment_adjustments' => [
            ['scope' => 'filesystem', 'message' => 'Created missing directory: storage/framework/cache', 'severity' => 'success'],
        ]]);
        $admin = User::factory()->admin()->create();

        $html = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get(route('admin.preflight.index'))->assertOk()->getContent();
        $this->assertStringContainsString('تم إنشاء المجلد الناقص: <bdi dir="ltr">storage/framework/cache</bdi>', $html);
        $this->assertStringContainsString('<div class="fw-semibold">'.e(__('Filesystem', [], 'ar')).'</div>', $html);

        // The JSON keeps plain text (no HTML), in the admin's language.
        $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->getJson(route('admin.preflight.json'))->assertOk()
            ->assertJsonPath('adjustments.0.message', 'تم إنشاء المجلد الناقص: storage/framework/cache');
    }

    public function test_the_streaming_and_clean_address_lines_tell_what_the_site_does_on_nginx(): void
    {
        File::ensureDirectoryExists(storage_path('framework/testing'));
        ServerChecks::$stateFile = storage_path('framework/testing/health-'.bin2hex(random_bytes(4)).'.json');
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        Environment::reset();
        ServerChecks::reset();
        config(['site.stream_accelerator' => 'auto']);

        try {
            ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::NO, 'test');
            ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::NO, 'test');

            $report = $this->actingAs(User::factory()->admin()->create())->getJson(route('admin.preflight.json'))->assertOk()->json();
            $lines = collect($report['checks'])->keyBy('label');

            $this->assertSame('none', $report['streaming']['mode']);
            $this->assertSame('warn', $lines['Accelerator']['severity']);
            $this->assertStringContainsString('PHP streams the video files: nginx has no internal location /_internal/', $lines['Accelerator']['detail']);
            $this->assertStringContainsString('docs/server-configs/README.md', $lines['Accelerator']['detail']);
            $this->assertStringContainsString('Addresses with /index.php: when the site checked on', $lines['Clean addresses']['detail']);

            ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::YES, 'test');
            $report = $this->getJson(route('admin.preflight.json'))->assertOk()->json();
            $lines = collect($report['checks'])->keyBy('label');
            $this->assertSame('nginx', $report['streaming']['mode']);
            $this->assertSame('ok', $lines['Accelerator']['severity']);
            $this->assertStringContainsString('nginx sends the video files', $lines['Accelerator']['detail']);

            $page = (string) $this->get(route('admin.preflight.index'))->assertOk()->getContent();
            $this->assertStringNotContainsString('docs/deployment', $page, 'the page names a guide the package has');
            $this->assertStringContainsString('docs/server-configs/README.md', $page);
            $this->assertFileExists(base_path('docs/server-configs/README.md'));
        } finally {
            @unlink((string) ServerChecks::$stateFile);
            ServerChecks::$stateFile = null;
            ServerChecks::reset();
            if ($software === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $software;
            }
            Environment::reset();
        }
    }

    public function test_on_a_server_without_an_accelerator_php_streaming_is_no_warning(): void
    {
        $report = $this->actingAs(User::factory()->admin()->create())->getJson(route('admin.preflight.json'))->assertOk()->json();
        $lines = collect($report['checks'])->keyBy('label');

        $this->assertSame('ok', $lines['Accelerator']['severity']);
        $this->assertSame('ok', $lines['Clean addresses']['severity']);
    }

    /** Arabic texts for keys lang/ar.json gets at the merge (the lane's i18n.json). */
    private function arabic(array $lines): void
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
