<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PhpInfoController;
use App\Http\Middleware\EnsureAdminOperationalToolsEnabled;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AdminOperationalToolsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dangerous_admin_routes_use_operational_tools_gate(): void
    {
        $routeNames = [
            'admin.database.index',
            'admin.backups.index',
            'admin.system.index',
            'admin.preflight.index',
            'admin.cron.index',
            'admin.failed-jobs.index',
            'admin.env-editor.index',
            'admin.logs',
            'admin.logs.index',
            'admin.queue.index',
            'admin.artisan.index',
            'admin.phpinfo.index',
            'admin.storage.index',
            'admin.horizon',
            'admin.impersonate',
        ];

        foreach ($routeNames as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains('admin.ops', $route->gatherMiddleware(), "Route {$name} must use admin.ops.");
        }
    }

    public function test_operational_tools_gate_fails_closed_when_disabled(): void
    {
        config(['admin.operational_tools_enabled' => false]);

        $this->expectException(NotFoundHttpException::class);

        app(EnsureAdminOperationalToolsEnabled::class)->handle(
            Request::create('/admin/phpinfo', 'GET'),
            fn () => response('ok')
        );
    }

    public function test_operational_tools_gate_passes_when_enabled(): void
    {
        config(['admin.operational_tools_enabled' => true]);

        $response = app(EnsureAdminOperationalToolsEnabled::class)->handle(
            Request::create('/admin/phpinfo', 'GET'),
            fn () => response('ok')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_the_php_and_server_tabs_never_print_a_secret_of_the_environment(): void
    {
        config(['admin.operational_tools_enabled' => true]);
        $secrets = ['STRIPE_SECRET' => 'sk_test_W5probeSecret4242', 'APP_KEY' => 'base64:W5probeAppKeyValue=', 'DB_PASSWORD' => 'W5-db-pass-word', 'PAYPAL_CLIENT_ID' => 'W5PaypalClientId1234'];
        $saved = [];

        // How .env reaches PHP: putenv() (the web server environment on Linux), $_ENV and $_SERVER.
        foreach ($secrets as $name => $value) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            $admin = User::factory()->admin()->create();

            foreach (['/admin/phpinfo', '/admin/system'] as $url) {
                $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

                foreach ($secrets as $name => $value) {
                    $this->assertStringNotContainsString($value, $html, "{$name} printed on {$url}");
                }
            }

            // The page still shows the PHP settings and modules.
            $this->actingAs($admin)->get('/admin/phpinfo')->assertSee('memory_limit')->assertSee('PHP Version');
        } finally {
            foreach ($saved as $name => [$env, $superEnv, $server]) {
                $env === false ? putenv($name) : putenv("{$name}={$env}");
                if ($superEnv === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $superEnv; }
                if ($server === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $server; }
            }
        }
    }

    public function test_secret_rows_are_masked_by_name_whatever_the_length_of_the_value(): void
    {
        // A value-only filter lets short or all-digit secrets through (a 5-character PayPal webhook ID, a numeric
        // database password); the environment rows are masked by the variable's name, in the web and the CLI format.
        $html = '<tr><td class="e">PAYPAL_WEBHOOK_ID </td><td class="v">Qz_w7 </td></tr>'."\n"
            .'<tr><td class="e">DB_PASSWORD </td><td class="v">80419 </td></tr>'."\n"
            .'<tr><td class="e">$_SERVER[\'MAIL_PASSWORD\']</td><td class="v">k9#x</td></tr>'."\n"
            .'<tr><td class="e">APP_ENV </td><td class="v">production </td></tr>'."\n"
            .'<tr><td class="e">memory_limit</td><td class="v">512M</td><td class="v">128M</td></tr>'."\n";
        $text = "PAYPAL_WEBHOOK_ID => Qz_w7\nDB_PASSWORD => 80419\nAPP_ENV => production\nmax_execution_time => 0 => 0\n";

        $masked = PhpInfoController::withoutSecrets($html.$text);

        foreach (['Qz_w7', '80419', 'k9#x'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
        $this->assertStringContainsString('<td class="e">PAYPAL_WEBHOOK_ID </td><td class="v">********</td></tr>', $masked);
        $this->assertStringContainsString('<td class="e">$_SERVER[\'MAIL_PASSWORD\']</td><td class="v">********</td></tr>', $masked);
        $this->assertStringContainsString('DB_PASSWORD => ********', $masked);
        $this->assertStringContainsString('<td class="e">APP_ENV </td><td class="v">production </td></tr>', $masked);
        $this->assertStringContainsString('APP_ENV => production', $masked);
        $this->assertStringContainsString('<td class="e">memory_limit</td><td class="v">512M</td><td class="v">128M</td></tr>', $masked);
        $this->assertStringContainsString('max_execution_time => 0 => 0', $masked);
    }

    public function test_masking_keeps_ordinary_values_and_hides_every_secret_spelling(): void
    {
        $_ENV['W5_PROBE_TOKEN'] = 'tok<en>&"value"';

        try {
            $html = '<td>'.htmlspecialchars('tok<en>&"value"', ENT_QUOTES).'</td><td>tok<en>&"value"</td><td>memory_limit</td><td>128M</td>';
            $masked = PhpInfoController::withoutSecrets($html);

            $this->assertStringNotContainsString('tok&lt;en&gt;', $masked);
            $this->assertStringNotContainsString('tok<en>', $masked);
            $this->assertStringContainsString('memory_limit', $masked);
            $this->assertStringContainsString('128M', $masked);
        } finally {
            unset($_ENV['W5_PROBE_TOKEN']);
        }
    }
}
