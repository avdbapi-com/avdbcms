<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 refresh (N48-MANIFEST-01): three demo sites whose nginx / OpenResty document root is the project folder handed
 * out /avdbcms-manifest.json (2.6 MB: the exact version and every file's checksum) because their rules files dated
 * from before the list of closed files grew (round 3 R3-039 added the package's documents and that list). The shipped
 * rules were right; nothing pinned them to the files the package really has at its root. This test does: every entry
 * of the project folder that the package ships and that a web root at public/ cannot reach is closed by the exact
 * locations of the three project-root nginx files and of the rules the site writes into aaPanel's rewrite box, or
 * by their dotfile rule, or is served through its own location (storage/, vendor/, public/).
 */
class ProjectRootClosedFilesTest extends TestCase
{
    private const PROJECT_ROOT_FILES = [
        'docs/server-configs/nginx-project-root.conf',
        'docs/server-configs/nginx-hestia-project-root.conf',
        'docs/server-configs/nginx-cloudpanel-front.conf',
    ];

    /**
     * What the release build leaves out of the package (scripts/build-community-release.ps1, the two exclusion lists;
     * test_the_list_of_what_is_not_shipped_is_the_build_scripts() keeps this copy honest). A root entry that is not here
     * SHIPS: the build copies every other entry of the tree it runs in, so a stray file at the root of a working tree
     * (a note, a command's output saved under an odd name) would be in the package and, with the project folder as
     * nginx's web root, handed out to guests. Such an entry fails the scan below: delete it, or add it to the build's
     * exclusions and here.
     */
    private const NOT_SHIPPED = [
        '.git', '.phpunit.cache', '.tmp-npm-cache', '.tmp-playwright', '.tmp-playwright-browsers', '.playwright-mcp', '.tools',
        '.vscode', '.idea', '.agents', 'build', 'chat-export', 'current_remote', 'deploy', 'docker', 'incident_patch',
        'node_modules', 'ops', 'remote_domain_patch', 'remote_patch', 'scratch', 'scripts', 'tests',
        'CURRENT_STATE.md', 'AGENTS.md', 'docker-compose.yml', 'scratch_themes.json', 'theme_test_report.json', 'Makefile', 'phpunit.xml',
        'phpstan.neon', 'package.json', 'package-lock.json', 'bun.lockb', 'tsconfig.json', 'vite.config.ts', '.editorconfig',
    ];

    /** Served through a location of their own in every project-root file (an alias into the public disk or public/). */
    private const OWN_LOCATION = ['storage', 'vendor', 'public', 'uploads'];

    /**
     * What a web root at public/ serves too: the front controller and the copies of public/ files the project folder's
     * index.php mirrors next to itself (favicon, the PWA manifest, robots.txt, the 404 page, the asset folders).
     */
    private const PUBLIC_TOO = ['index.php', 'favicon.ico', 'manifest.json', 'robots.txt', '404.html', 'css', 'icons', 'images', 'sounds', 'js'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $text = file_get_contents(self::root().'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", (string) preg_replace('/^\s*#[^\n]*\n?/m', '', str_replace("\r\n", "\n", $text)));
    }

    /** @return list<string> the four rule texts: the three files and the rules the site writes for aaPanel */
    private static function ruleTexts(): array
    {
        $texts = [];

        foreach (self::PROJECT_ROOT_FILES as $file) {
            $texts[$file] = self::read($file);
        }

        $texts['AaPanelRewrite::rules()'] = AaPanelRewrite::rules('/www/wwwroot/example.com');

        return $texts;
    }

    public function test_every_shipped_root_entry_a_public_web_root_cannot_reach_is_closed(): void
    {
        $entries = array_values(array_filter(scandir(self::root()) ?: [], fn (string $name): bool => ! in_array($name, ['.', '..'], true)
            && ! in_array($name, self::NOT_SHIPPED, true)
            && ! str_starts_with($name, '.r2-')      // the tool files of local working copies
            && ! str_starts_with($name, 'chat-export-')
            && ! preg_match('/\.(bak|log|tsv)(\.|$)/', $name)));
        $this->assertContains('index.php', $entries);
        $this->assertContains('artisan', $entries);
        $open = [];

        foreach ($entries as $name) {
            if (str_starts_with($name, '.')) {
                continue; // the dotfile rule (AaPanelRewrite::DOTFILES), in every file: tested below
            }

            if (in_array($name, self::OWN_LOCATION, true) || in_array($name, self::PUBLIC_TOO, true)) {
                continue;
            }

            $closed = is_dir(self::root().'/'.$name)
                ? in_array($name, AaPanelRewrite::CLOSED_FOLDERS, true)
                : in_array($name, AaPanelRewrite::CLOSED_FILES, true);

            if (! $closed) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open, 'these entries of the project folder would ship (the release build copies every root entry it does not exclude) and are neither closed (AaPanelRewrite::CLOSED_FOLDERS / CLOSED_FILES) nor served on purpose: a site whose web root is the project folder would hand them out. A stray file of this working tree: delete it; a file the package needs: add its deny line to the three project-root nginx files and AaPanelRewrite::CLOSED_FILES; a private file of the tree: add it to the build\'s exclusions and to NOT_SHIPPED here');
    }

    /**
     * NOT_SHIPPED is a copy of the build's exclusions: an entry here that the build does not exclude would hide a root
     * file that ships without a deny line (second pass, verifier WEB-V1-03: the owner's working tree carried AGENTS.md,
     * which the build did not exclude). Every name must be quoted in scripts/build-community-release.ps1.
     */
    public function test_the_list_of_what_is_not_shipped_is_the_build_scripts(): void
    {
        $build = str_replace("\r\n", "\n", (string) file_get_contents(self::root().'/scripts/build-community-release.ps1'));
        $this->assertNotSame('', $build);

        foreach (self::NOT_SHIPPED as $name) {
            $this->assertMatchesRegularExpression('/\''.preg_quote($name, '/').'\'|\''.preg_quote($name, '/').'\//', $build, $name.' is not in the build\'s exclusion lists');
        }

        // the private notes of a working tree: excluded by the copy filter and refused by the package check
        $this->assertStringContainsString("        'AGENTS.md',\n", $build);
        $this->assertStringContainsString("\$name -eq 'AGENTS.md' -or\n", $build);
    }

    public function test_the_package_documents_and_its_file_list_are_closed_in_every_project_root_rules_text(): void
    {
        $names = ['avdbcms-manifest.json', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'artisan',
            'README.md', 'COMMUNITY_SETUP_GUIDE.md', 'UPGRADE.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'nginx.conf',
            'nginx-aapanel-rewrite.conf', 'php-cli.ini', 'web.config'];

        foreach (self::ruleTexts() as $label => $text) {
            foreach ($names as $name) {
                $this->assertStringContainsString('location = /'.$name.' { return 404; }', $text, $label.': '.$name);
            }

            foreach (AaPanelRewrite::CLOSED_FOLDERS as $folder) {
                $this->assertStringContainsString('location ^~ /'.$folder.'/ { return 404; }', $text, $label.': '.$folder.'/');
            }

            // .env, .env.example, .git, .htaccess, .user.ini ... in any folder, ACME challenges aside
            $this->assertStringContainsString('location ~ '.AaPanelRewrite::DOTFILES.' { return 404; }', $text, $label.': dotfiles');
            $this->assertStringNotContainsString('location = /index.php {', $text, $label.': the front controller stays');
        }

        $this->assertContains('avdbcms-manifest.json', AaPanelRewrite::CLOSED_FILES);
    }

    public function test_the_update_guide_tells_a_project_root_nginx_site_to_refresh_its_copy_of_the_rules(): void
    {
        $upgrade = str_replace("\r\n", "\n", (string) file_get_contents(self::root().'/UPGRADE.md'));

        $this->assertStringContainsString('## Nginx rules you copied from the package', $upgrade);
        $this->assertStringContainsString('avdbcms-manifest.json', $upgrade);
        $this->assertMatchesRegularExpression('/nginx-project-root\.conf.*avdbcms-manifest\.json/s', $upgrade, 'the section names the file and the list');
    }
}
