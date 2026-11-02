<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round 3 R3-039: with the project folder as nginx's web root (docs/server-configs/nginx-project-root.conf, the aaPanel
 * rules index.php writes, nginx-aapanel-rewrite.conf pasted with the Running directory "/" by mistake) visitors could
 * download the package's documents and its file list: avdbcms-manifest.json gives the exact version and every file's
 * SHA-256 (a fingerprint for scanners), UPGRADE.md, CHANGELOG.md, LICENSE, NOTICE and docs/ the rest. README.md and
 * COMMUNITY_SETUP_GUIDE.md were already closed; Apache's root .htaccess refuses them all. A site's aaPanel rules of an
 * earlier release are written again (they lack the new /docs/ location), so the update closes them there too.
 * The requests through nginx (404 for each, the site still 200) are in lane SB's box runs.
 */
class PackageDocumentsClosedTest extends TestCase
{
    private const DOCUMENTS = ['UPGRADE.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'avdbcms-manifest.json', 'README.md', 'COMMUNITY_SETUP_GUIDE.md'];

    private static function read(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", $text);
    }

    /** @return array<string, array{0: string}> */
    public static function projectRootRules(): array
    {
        return [
            'docs/server-configs/nginx-project-root.conf' => ['docs/server-configs/nginx-project-root.conf'],
            'nginx-aapanel-rewrite.conf (the Running directory "/" by mistake)' => ['nginx-aapanel-rewrite.conf'],
            'the aaPanel rules index.php writes (AaPanelRewrite::rules)' => ['aapanel-writer'],
        ];
    }

    private static function rules(string $name): string
    {
        return $name === 'aapanel-writer' ? AaPanelRewrite::rules('/www/wwwroot/example.com') : self::read($name);
    }

    #[DataProvider('projectRootRules')]
    public function test_the_packages_documents_and_file_list_are_never_handed_out(string $name): void
    {
        $rules = preg_replace('/#[^\n]*/', '', self::rules($name));

        foreach (self::DOCUMENTS as $file) {
            $this->assertStringContainsString('location = /'.$file.' { return 404; }', $rules, $name.' '.$file);
        }

        $this->assertStringContainsString('location ^~ /docs/ { return 404; }', $rules, $name);
    }

    public function test_the_writer_lists_them(): void
    {
        foreach (self::DOCUMENTS as $file) {
            $this->assertContains($file, AaPanelRewrite::CLOSED_FILES, $file);
        }

        $this->assertContains('docs', AaPanelRewrite::CLOSED_FOLDERS);
        $this->assertContains('/docs/', AaPanelRewrite::prefixLocations(AaPanelRewrite::rules('/project')));
    }

    public function test_a_sites_rules_of_an_earlier_release_are_written_again_and_the_current_ones_are_kept(): void
    {
        // The rules a 1.2.2 / 1.2.3 RC site has in its URL rewrite file: the current ones without the R3-039 locations.
        $current = AaPanelRewrite::rules('/www/wwwroot/example.com');
        $earlier = $current;

        foreach (['UPGRADE.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'avdbcms-manifest.json'] as $file) {
            $earlier = str_replace("location = /{$file} { return 404; }\n", '', $earlier);
        }

        $earlier = str_replace("location ^~ /docs/ { return 404; }\n", '', $earlier);

        $this->assertStringNotContainsString('/UPGRADE.md', $earlier);
        $this->assertStringNotContainsString('/docs/', $earlier);
        $this->assertTrue(AaPanelRewrite::shouldWrite($earlier), 'an earlier release\'s rules are upgraded');
        $this->assertFalse(AaPanelRewrite::shouldWrite($current), 'the current rules stay as they are (no write on every request)');
    }

    public function test_apache_and_iis_keep_them_closed_too(): void
    {
        // The project folder's .htaccess (Apache) and web.config (IIS) hand every address that is not public/ to public/,
        // where none of these is.
        $this->assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L,QSA]', self::read('.htaccess'));
        $this->assertStringContainsString('<action type="Rewrite" url="public/{R:1}" appendQueryString="true" />', self::read('web.config'));

        foreach (self::DOCUMENTS as $file) {
            $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/public/'.$file, 'public/'.$file.' would be handed out by every layout');
        }

        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2).'/public/docs');
    }
}
