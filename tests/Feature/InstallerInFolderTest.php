<?php

namespace Tests\Feature;

use App\Support\Environment;
use App\Support\SiteAddress;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 fix pass R2R-05: the browser installer of a site in a folder of its domain (https://example.test/site,
 * opened at /site/install): its own links and forms stay in the folder (they went to /install/... at the domain's root,
 * another site's page or a 404), the Admin step suggests the address with the folder as APP_URL, and the requirements
 * page says what belongs to the domain and what does not reach a folder yet (COMMUNITY_SETUP_GUIDE.md, "A site in a
 * folder of its domain"). At a domain's root the wizard is as before. The real installs in folders (Windows, nginx,
 * Apache Alias, the project folder in a folder) are in lane SB's runs.
 */
class InstallerInFolderTest extends TestCase
{
    /** @var array<string, array{0: mixed, 1: mixed, 2: mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_INSTALLED' => 'auto', 'INSTALLER_ALLOW_REINSTALL' => 'true'] as $key => $value) {
            $this->saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX', 'AVDBCLIP_ROOT_FALLBACK', 'SERVER_SOFTWARE'] as $key) {
            $this->saved[$key] = [null, $_SERVER[$key] ?? null, false];
            unset($_SERVER[$key]);
        }

        Schema::dropIfExists('users');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        foreach ($this->saved as $key => [$env, $server, $process]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            if (in_array($key, ['APP_INSTALLED', 'INSTALLER_ALLOW_REINSTALL'], true)) {
                putenv($process === false ? $key : $key.'='.$process);
            }
        }

        Environment::reset();

        parent::tearDown();
    }

    public function test_the_wizard_keeps_its_folder_and_says_what_belongs_to_the_domain(): void
    {
        $html = (string) $this->visit('/site/install?step=requirements', '/site/index.php')->assertOk()->getContent();

        $this->assertStringContainsString('action="/site/install/requirements"', $html);
        $this->assertStringContainsString('href="/site/install?step=introduction"', $html);
        $this->assertSame([], $this->wizardAddressesOutside($html, '/site/'));

        $this->assertStringContainsString('data-site-folder="/site"', $html);
        $this->assertStringContainsString('<code>https://example.test/site</code>', $html);
        $this->assertStringContainsString('Sitemap: https://example.test/site/sitemap.xml', $html);
        $this->assertStringNotContainsString('/site/index.php/sitemap.xml', $html);
        $this->assertStringContainsString('docs/server-configs/nginx-subfolder.conf', $html);
    }

    public function test_the_admin_step_suggests_the_address_with_the_folder(): void
    {
        $html = (string) $this->visit('/site/install?step=admin', '/site/index.php')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input id="app_url" name="app_url" value="https://example\.test/site"#', $html);
        $this->assertSame([], $this->wizardAddressesOutside($html, '/site/'));
    }

    public function test_through_index_php_in_a_folder_the_wizard_keeps_both(): void
    {
        $html = (string) $this->visit('/site/index.php/install?step=requirements', '/site/index.php')->assertOk()->getContent();

        $this->assertStringContainsString('action="/site/index.php/install/requirements"', $html);
        $this->assertSame([], $this->wizardAddressesOutside($html, '/site/index.php/'));

        // round 3 check SB-V1-02: the sitemap's address in the form the site's addresses have there (the clean one is the
        // web server's 404 page on a server without rewrite rules)
        $this->assertStringContainsString('Sitemap: https://example.test/site/index.php/sitemap.xml', $html);
        $this->assertStringNotContainsString('Sitemap: https://example.test/site/sitemap.xml', $html);
    }

    public function test_at_a_domains_root_the_wizard_is_as_before(): void
    {
        $html = (string) $this->visit('/install?step=requirements', '/index.php')->assertOk()->getContent();

        $this->assertStringContainsString('action="/install/requirements"', $html);
        $this->assertStringContainsString('href="/install?step=introduction"', $html);
        $this->assertStringNotContainsString('data-site-folder', $html);
        $this->assertStringNotContainsString('A site in a folder of its domain', $html);

        $admin = (string) $this->visit('/install?step=admin', '/index.php')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<input id="app_url" name="app_url" value="https://example\.test"#', $admin);
    }

    public function test_the_web_server_panel_of_a_folder_install_names_the_folders_own_setup(): void
    {
        // round 3 review SB-RV-07: it advised the domain's root ("set the Running Directory to /public", a whole server
        // block with root <project>/public), which contradicted the folder notice above it
        $this->serve('nginx/1.24.0');
        $html = (string) $this->visit('/site/install?step=requirements', '/site/index.php')->assertOk()->getContent();
        $this->assertStringContainsString('The folder /site of its domain', $html);
        $this->assertStringNotContainsString('Running Directory to /public', $html);
        $snippet = $this->snippet($html);
        $this->assertStringContainsString('docs/server-configs/nginx-subfolder.conf', $snippet);
        $this->assertStringContainsString('/site             -&gt;  /site', $snippet);
        $this->assertStringNotContainsString('server_name', $snippet);

        $this->serve('Apache/2.4.58 (Ubuntu)');
        $snippet = $this->snippet((string) $this->visit('/site/install?step=requirements', '/site/index.php')->assertOk()->getContent());
        $this->assertStringContainsString('Alias /site &quot;', $snippet);
        $this->assertStringNotContainsString('DocumentRoot &quot;', $snippet, 'no document root of its own');
    }

    public function test_at_a_domains_root_the_web_server_panel_is_as_before(): void
    {
        $this->serve('nginx/1.24.0');
        $html = (string) $this->visit('/install?step=requirements', '/index.php')->assertOk()->getContent();
        $this->assertStringNotContainsString('of its domain', $html);
        $snippet = $this->snippet($html);
        $this->assertStringContainsString('server_name example.com www.example.com;', $snippet);
        $this->assertStringNotContainsString('nginx-subfolder.conf', $snippet);
    }

    /** The web server the installer reads (Environment::webServer(): SERVER_SOFTWARE). */
    private function serve(string $software): void
    {
        $_SERVER['SERVER_SOFTWARE'] = $software;
        Environment::reset();
    }

    /** The installer's suggested server snippet (escaped as the page prints it). */
    private function snippet(string $html): string
    {
        $this->assertSame(1, preg_match('#<pre id="server-config" class="config-snippet">(.*?)</pre>#s', $html, $match), 'the snippet');

        return $match[1];
    }

    /**
     * A request as the web server and public/index.php hand it to Laravel (App\Support\SiteAddress), sent through the
     * kernel.
     */
    private function visit(string $address, string $scriptName): TestResponse
    {
        // A fresh copy keeps the example address (http://localhost) until it is installed, and
        // EnvironmentServiceProvider::normalizeAppUrl() takes the address the browser asked for instead, with the folder
        // (currentRequestRoot()); the test's application booted without a request, so it is set here the same way.
        config(['app.url' => 'https://example.test'.SiteAddress::folder(['SCRIPT_NAME' => $scriptName, 'REQUEST_URI' => $address])]);

        $server = ['SCRIPT_NAME' => $scriptName, 'PHP_SELF' => $scriptName, 'REQUEST_URI' => $address];
        $this->assertNull(SiteAddress::prepare($server, array_keys((array) config('languages')), public_path(), public_path('index.php')));

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX'] as $key) {
            if (isset($server[$key])) {
                $_SERVER[$key] = $server[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        return $this->withServerVariables(['SCRIPT_NAME' => $server['SCRIPT_NAME'], 'PHP_SELF' => $server['PHP_SELF'],
            'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on'])
            ->get('https://example.test'.$server['REQUEST_URI']);
    }

    /** @return list<string> the wizard's own addresses (links and forms to /install) that do not start with $start */
    private function wizardAddressesOutside(string $html, string $start): array
    {
        preg_match_all('#(?:href|action)="(/[^"]*install[^"]*)"#', $html, $matches);

        return array_values(array_filter($matches[1], static fn (string $path): bool => ! str_starts_with($path, $start)));
    }
}
