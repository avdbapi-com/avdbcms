<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round 1b R1B-002, R1B-003, R1B-017, R1B-018: every web server configuration the package ships says the same.
 *  - The upload folders (storage/ = the public disk, uploads/ = the legacy folder updates keep) never run a script
 *    (.php .php3 ... .php8 .phar .phtml .pht .phps, also x.php.jpg and x.php/extra): Apache's shared-host layout ran
 *    /storage/x.php, /storage/x.phar and /public/storage/x.php, nginx ran /uploads/x.php.
 *  - A database dump, a log or a backup copy is never handed out as a file (they were downloadable from public/), while
 *    the site's own addresses that end like that (an admin's download of a backup or a log) still reach the application.
 *  - nginx has the internal /_internal/ location the video files are handed to (X-Accel-Redirect), aliased to the
 *    folder of the local disk (storage/app/private), in every shipped nginx config and in the aaPanel writer's rules.
 *  - Apache's shared-host layout refuses a real folder of the project (storage/logs ...) at once (403) before anything can
 *    add or take off its slash (it looped with 301s).
 * The box proofs (nginx -t, the requests through nginx and Apache) are in the lane's runs; this keeps the files from
 * drifting apart.
 */
class ServerConfigsTest extends TestCase
{
    private const NGINX = [
        'docs/server-configs/nginx-public-root.conf',
        'docs/server-configs/nginx-project-root.conf',
        'nginx.conf',
        'nginx-aapanel-rewrite.conf',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $text = file_get_contents(self::root().'/'.$relative);
        self::assertIsString($text, $relative);

        return str_replace("\r\n", "\n", $text);
    }

    /** @return array<string, array{0: string, 1: string}> name => [the config's text, what its /storage/ location looks like] */
    public static function nginxConfigs(): array
    {
        $configs = [];

        foreach (self::NGINX as $file) {
            $configs[$file] = [$file];
        }

        $configs['the aaPanel writer\'s rules (AaPanelRewrite::rules)'] = ['aapanel-writer'];

        return $configs;
    }

    private static function nginx(string $name): string
    {
        return $name === 'aapanel-writer' ? AaPanelRewrite::rules('/www/wwwroot/example.com') : self::read($name);
    }

    #[DataProvider('nginxConfigs')]
    public function test_every_nginx_config_has_the_internal_video_location(string $name): void
    {
        $config = self::nginx($name);

        $this->assertMatchesRegularExpression('#location \^~ /_internal/ \{\s*internal;\s*alias [^;\s]+/storage/app/private/;\s*\}#', $config, $name);
    }

    #[DataProvider('nginxConfigs')]
    public function test_no_upload_folder_runs_a_script_in_any_nginx_config(string $name): void
    {
        $config = self::nginx($name);
        $neverRun = 'location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }';

        foreach (['/storage/', '/uploads/'] as $folder) {
            $block = $this->block($config, 'location ^~ '.$folder.' {');
            $this->assertNotNull($block, $name.' has a ^~ '.$folder.' location');
            $this->assertStringContainsString($neverRun, $block, $name.' '.$folder);
            $this->assertStringNotContainsString('fastcgi_pass', $block, $name.' '.$folder);
        }

        if (in_array($name, ['docs/server-configs/nginx-project-root.conf', 'aapanel-writer'], true)) {
            // The web root is the project folder: the same folders under public/ too.
            foreach (['/public/storage/', '/public/uploads/'] as $folder) {
                $this->assertStringContainsString($neverRun, (string) $this->block($config, 'location ^~ '.$folder.' {'), $name.' '.$folder);
            }
        }
    }

    #[DataProvider('nginxConfigs')]
    public function test_no_nginx_config_hands_out_dumps_or_logs_but_the_sites_own_addresses_still_work(string $name): void
    {
        $config = self::nginx($name);

        $this->assertMatchesRegularExpression('#location ~\* '.preg_quote(AaPanelRewrite::DUMPS, '#').' \{\s*rewrite \^ /index\.php last;\s*\}#', $config, $name);
        $this->assertStringContainsString('location ~* '.AaPanelRewrite::DUMPS.' { return 404; }', (string) $this->block($config, 'location ^~ /storage/ {'), $name);
    }

    /**
     * 1.2.3 refresh (N1-ROBOTS-01 on the nginx demo sites, verifier WEB-V1-02): nginx's own answer for the written
     * robots.txt carried no Cache-Control, so Cloudflare kept it for its default two hours after every change the site
     * made from Settings > Google & sharing. Every nginx config of the package (and the rules the site writes for aaPanel)
     * now gives the file the five minutes its route and public/.htaccess give it, and hands a missing file to the site,
     * whose route answers the same text. An exact location: it wins over a panel's own static-file rules.
     */
    #[DataProvider('nginxConfigs')]
    public function test_every_nginx_config_gives_robots_txt_five_minutes_and_hands_a_missing_file_to_the_site(string $name): void
    {
        $config = preg_replace('/#[^\n]*/', '', self::nginx($name));

        $this->assertMatchesRegularExpression('#location = /robots\.txt \{\s*try_files \$uri /index\.php\?\$query_string;\s*expires 5m;#', $config, $name);
        $this->assertSame(1, substr_count($config, 'location = /robots.txt'), $name.': one robots.txt location');

        if (str_contains($config, 'location ~ \.php$')) {
            // A whole server block: the file's five minutes never reach the PHP answer, which carries its own Cache-Control.
            $this->assertLessThan(strpos($config, 'location ~ \.php$'), strpos($config, 'location = /robots.txt'), $name);
        }
    }

    #[DataProvider('nginxConfigs')]
    public function test_every_nginx_config_is_balanced(string $name): void
    {
        $config = preg_replace('/#[^\n]*/', '', self::nginx($name));

        $this->assertSame(substr_count($config, '{'), substr_count($config, '}'), $name);
        $this->assertStringNotContainsString('server {', $name === 'nginx-aapanel-rewrite.conf' ? $config : '', 'the URL rewrite box holds locations only');
    }

    public function test_the_expressions_are_the_ones_the_shared_host_files_and_the_root_index_php_use(): void
    {
        $public = self::read('public/.htaccess');
        $root = self::read('.htaccess');
        $index = self::read('index.php');

        // Apache: the same script and dump endings, case-insensitive.
        $this->assertStringContainsString('RewriteRule ^(storage|uploads)/(.*/)?[^/]*'.AaPanelRewrite::SCRIPTS.' - [F,NC]', $public);
        $this->assertStringContainsString('RewriteRule ^(storage|uploads)/(.*/)?[^/]*'.AaPanelRewrite::SCRIPTS.' - [F,NC]', $root);
        $this->assertStringContainsString("RewriteCond %{REQUEST_FILENAME} -f\n    RewriteRule ".AaPanelRewrite::DUMPS.' - [F,NC]', $public);
        $this->assertStringContainsString('RewriteRule (^|/)\.(?!well-known(/|$)) - [F]', $public);

        // The root index.php (nginx with the web root at the project folder) never serves them as static files.
        $this->assertStringContainsString("'#".AaPanelRewrite::SCRIPTS."#i'", $index);
        $this->assertStringContainsString("'#".AaPanelRewrite::DUMPS."#i'", $index);
    }

    public function test_the_shared_host_htaccess_refuses_scripts_and_real_folders_before_it_forwards_storage(): void
    {
        $root = self::read('.htaccess');

        $scripts = strpos($root, 'RewriteRule ^(storage|uploads)/');
        $folders = strpos($root, "RewriteCond %{REQUEST_FILENAME} -d\n    RewriteRule ^(?!public$). - [F,L]");
        $storage = strpos($root, 'RewriteRule ^storage/(.+)$ public/storage/$1 [L,QSA]');

        $this->assertNotFalse($scripts);
        $this->assertNotFalse($folders, 'a real folder is refused at once, never a 301 that public/index.php undoes');
        $this->assertNotFalse($storage);
        $this->assertLessThan($storage, $scripts);
        $this->assertLessThan($storage, $folders);
        $this->assertGreaterThan(strpos($root, 'RewriteRule ^public/ - [L]'), $scripts);
    }

    public function test_the_guide_the_health_check_names_exists_and_explains_both_switches(): void
    {
        $guide = self::read('docs/server-configs/README.md');

        $this->assertStringContainsString('## Video files', $guide);
        $this->assertStringContainsString('/_internal/', $guide);
        $this->assertStringContainsString('STREAM_ACCELERATOR', $guide);
        $this->assertStringContainsString('## Clean addresses', $guide);
        $this->assertStringContainsString('APP_CLEAN_URLS', $guide);
        $this->assertFileDoesNotExist(self::root().'/docs/deployment');
    }

    public function test_the_aapanel_writer_writes_exactly_the_locations_of_the_project_root_include(): void
    {
        // Round 1b review BWR-09: the writer said its rules were "the ones of nginx-project-root.conf" but had no exact-file
        // denies (nginx.conf, php-cli.ini, web.config ...), no /docker/, and refused dotfiles only at the web root.
        $include = $this->locations(str_replace('__PROJECT_ROOT__', '/www/wwwroot/example.com', self::read('docs/server-configs/nginx-project-root.conf')));
        $writer = $this->locations(AaPanelRewrite::rules('/www/wwwroot/example.com'));

        $this->assertNotEmpty($include);
        $this->assertSame($include, $writer);
        $this->assertContains('location = /nginx.conf { return 404; }', $writer);
        $this->assertContains('location ^~ /docker/ { return 404; }', $writer);
        $this->assertContains('location ~ '.AaPanelRewrite::DOTFILES.' { return 404; }', $writer);
    }

    #[DataProvider('nginxConfigs')]
    public function test_the_sites_own_addresses_that_end_like_a_file_reach_the_application_before_any_panel_rule(string $name): void
    {
        // Round 1b review BWR-02: CloudPanel's own static-file location (~* ^.+\.(css|js|...|gz|mp4|...|zip)$) answered the
        // local videos (/video/file/<name>.mp4) and the backup downloads with a 404; a "^~" prefix wins over any regex.
        $config = self::nginx($name);

        foreach (AaPanelRewrite::APPLICATION_PREFIXES as $prefix) {
            $this->assertMatchesRegularExpression('#location \^~ '.preg_quote($prefix, '#').' \{\s*rewrite \^ /index\.php last;\s*\}#', $config, $name.' '.$prefix);
        }
    }

    #[DataProvider('nginxConfigs')]
    public function test_every_nginx_config_refuses_dotfiles_in_any_folder(string $name): void
    {
        // Round 1b review BWR-09: /public/.x was handed out by the project-root files, any dotfile of public/ by the
        // aaPanel file (only aaPanel's own .env/.git/.user.ini rule).
        $config = preg_replace('/#[^\n]*/', '', self::nginx($name));
        $rule = 'location ~ '.AaPanelRewrite::DOTFILES.' { return 404; }';

        $this->assertStringContainsString($rule, $config, $name);
        $this->assertSame(1, preg_match('#'.AaPanelRewrite::DOTFILES.'#', '/public/.bw-hidden'));
        $this->assertSame(0, preg_match('#'.AaPanelRewrite::DOTFILES.'#', '/.well-known/acme-challenge/x'));

        if (str_contains($config, 'location ~ \.php$')) {
            // A whole server block: before PHP, so a dotfile script never runs.
            $this->assertLessThan(strpos($config, 'location ~ \.php$'), strpos($config, $rule), $name);
        }
    }

    public function test_the_aapanel_rewrite_file_never_opens_the_rest_of_storage(): void
    {
        // Round 1b review BWR-03: pasted with the Running directory "/" its plain /storage/ location handed out
        // storage/app/backups, the session files and installed.json; aliased to the public disk it serves nothing else.
        $config = self::read('nginx-aapanel-rewrite.conf');

        $this->assertMatchesRegularExpression('#location \^~ /storage/ \{\s*alias __PROJECT_ROOT__/storage/app/public/;#', $config);
        $this->assertStringContainsString('ONLY for the Running directory /public.', $config);
        $this->assertStringContainsString('docs/server-configs/nginx-project-root.conf ALONE', $config);

        // Used with the Running directory "/" by mistake anyway: the upload folders under public/ never run a script and
        // the project's source and files stay closed (with /public none of these exists, so nothing changes there).
        foreach (['/public/storage/', '/public/uploads/'] as $folder) {
            $this->assertStringContainsString('location ~* '.AaPanelRewrite::SCRIPTS.' { return 404; }', (string) $this->block($config, 'location ^~ '.$folder.' {'), $folder);
        }
        foreach (AaPanelRewrite::CLOSED_FOLDERS as $folder) {
            $this->assertStringContainsString('location ^~ /'.$folder.'/ { return 404; }', $config, $folder);
        }
        foreach (AaPanelRewrite::CLOSED_FILES as $file) {
            $this->assertStringContainsString('location = /'.$file.' { return 404; }', $config, $file);
        }

        $guide = self::read('COMMUNITY_SETUP_GUIDE.md');
        $this->assertStringContainsString('**Running directory `/`** (the project folder itself): do **not** use `nginx-aapanel-rewrite.conf`', $guide);
        $this->assertStringNotContainsString('- Best: paste the content of `nginx-aapanel-rewrite.conf`', $guide);
    }

    public function test_the_iis_files_refuse_scripts_in_the_upload_folders_dumps_and_dotfiles_before_the_front_controller(): void
    {
        // Round 1b review BWR-08: public/web.config had only the trailing-slash and front-controller rules, so IIS's PHP
        // handler ran /storage/x.php and /uploads/x.php and handed out a .sql.gz (IIS Express: lane runs/iis_layout.txt).
        $public = self::read('public/web.config');
        $root = self::read('web.config');

        foreach (['public/web.config' => $public, 'web.config' => $root] as $file => $text) {
            $this->assertNotFalse(simplexml_load_string($text), $file.' is valid XML');
            $this->assertStringContainsString('(storage|uploads)/(.*/)?[^/]*'.AaPanelRewrite::SCRIPTS.'" ignoreCase="true" />', $text, $file);
            $this->assertStringContainsString(rtrim(AaPanelRewrite::DUMPS, '$'), $text, $file);
            $this->assertStringContainsString('<match url="(^|/)\.(?!well-known(/|$))" ignoreCase="false" />', $text, $file);
            $this->assertSame(3, substr_count($text, '<action type="CustomResponse" statusCode="403"'), $file);
        }

        $this->assertStringContainsString('<match url="'.AaPanelRewrite::DUMPS.'" ignoreCase="true" />', $public);
        $this->assertLessThan(strpos($public, '<rule name="Remove trailing slash"'), strpos($public, '<rule name="Dumps, logs and backup copies are never handed out"'));
        $this->assertLessThan(strpos($root, '<rule name="AVDBCMS project-root fallback"'), strpos($root, '<rule name="Project folder: dumps, logs and backup copies are never handed out"'));

        // IIS hands the project folder's rules down to public/web.config: two rules with one name are a 500.52 there.
        preg_match_all('/<rule name="([^"]+)"/', $public, $publicNames);
        preg_match_all('/<rule name="([^"]+)"/', $root, $rootNames);
        $this->assertSame([], array_intersect($publicNames[1], $rootNames[1]));
    }

    public function test_the_upstream_docker_stack_is_not_shipped(): void
    {
        // R1B-009: the development stack of the upstream project could not start; containers: 1Panel.
        $this->assertFileDoesNotExist(self::root().'/docker-compose.yml');
        $this->assertDirectoryDoesNotExist(self::root().'/docker');

        $build = self::read('scripts/build-community-release.ps1');
        $this->assertStringContainsString("'docker-compose.yml',", $build);
        $this->assertStringContainsString("\$name.StartsWith('docker/') -or", $build);
        $this->assertStringContainsString("\$name -eq 'docker-compose.yml' -or", $build);
        $this->assertStringContainsString("'deploy', 'docker',", $build);
        $this->assertStringContainsString("'docker'}\n        or name == 'docker-compose.yml'", self::read('scripts/repack-clean-stage.py'));

        foreach (['README.md', 'COMMUNITY_SETUP_GUIDE.md', 'UPGRADE.md', 'docs/server-configs/README.md'] as $document) {
            $text = self::read($document);

            if ($document === 'UPGRADE.md') {
                // Judge R3-025: UPGRADE.md names the `docker-compose.yml` 1.2.0 to 1.2.2 shipped among the files the update to
                // 1.2.3 removes (and Undo brings back); it never tells anyone to use it.
                $this->assertStringContainsString('the development Docker files `docker-compose.yml` and `docker/`', $text);
                $text = str_replace('`docker-compose.yml`', '', $text);
            }

            $this->assertStringNotContainsString('docker-compose', $text, $document.' does not need it');
            $this->assertStringNotContainsString('docker compose', $text, $document.' does not need it');
        }
    }

    /**
     * The location blocks of a piece of nginx configuration, comments, blank lines and gzip lines aside, each block one
     * normalised string ("location ^~ /x/ { alias ...; try_files ...; }"), in their order.
     *
     * @return list<string>
     */
    private function locations(string $config): array
    {
        $lines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $config)) as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));

            if ($line !== '' && ! str_starts_with($line, 'gzip')) {
                $lines[] = $line;
            }
        }

        $text = implode(' ', $lines);
        $blocks = [];
        $offset = 0;

        while (($start = strpos($text, 'location ', $offset)) !== false) {
            $depth = 0;

            for ($i = $start, $length = strlen($text); $i < $length; $i++) {
                if ($text[$i] === '{') {
                    $depth++;
                } elseif ($text[$i] === '}' && --$depth === 0) {
                    break;
                }
            }

            $blocks[] = (string) preg_replace('/\s+/', ' ', substr($text, $start, $i - $start + 1));
            $offset = $i + 1;
        }

        return $blocks;
    }

    /** The text of the block that starts with $opening (nested blocks included), null when there is none. */
    private function block(string $config, string $opening): ?string
    {
        $start = strpos($config, $opening);

        if ($start === false) {
            return null;
        }

        $depth = 0;

        for ($i = $start, $length = strlen($config); $i < $length; $i++) {
            if ($config[$i] === '{') {
                $depth++;
            } elseif ($config[$i] === '}' && --$depth === 0) {
                return substr($config, $start, $i - $start + 1);
            }
        }

        return null;
    }
}
