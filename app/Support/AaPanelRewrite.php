<?php

namespace App\Support;

/**
 * The nginx rules the root index.php writes into aaPanel's per-site rewrite file
 * (/www/server/panel/vhost/rewrite/<domain>.conf) when the web root is the project folder, and when it writes them.
 *
 * Dependency-free on purpose: index.php loads this file before Laravel (and Composer) start.
 *
 * Without the rules' "location ^~ /storage/" (served from storage/app/public only), nginx hands out every file under
 * storage/ before PHP runs: logs, backups, exports, invoices. So the rules are written when the file is empty, holds
 * a broken server block, holds rules of an earlier release without one of the current locations (the storage one, the
 * upload folders' that never run a script, the videos' internal /_internal/), or holds exactly aaPanel's "laravel5"
 * preset (a single try_files location: what "select laravel5 (or leave default)" in the setup guides produces). Any
 * other rule an owner wrote stays as it is, and nothing is written when the site's other nginx files already declare
 * one of the rules' locations (nginx would refuse the pair) or cannot be read. The rules are the ones of
 * docs/server-configs/nginx-project-root.conf (tests/Unit/ServerConfigsTest keeps them the same).
 */
final class AaPanelRewrite
{
    public const MARK = '# AVDBClip auto-configured rules (web root = project root).';

    /** aaPanel's own folder (tests point it at a copy of the panel's files). */
    public static string $panelRoot = '/www/server/panel';

    /** How long the answer of a full scan of the panel's vhost files is kept (siteFor()). */
    private const SCAN_SECONDS = 600;

    /**
     * The aaPanel site whose nginx server block serves $projectRoot: the name of its vhost file
     * (<panel>/vhost/nginx/<name>.conf), which is also the name of the site's rewrite file.
     *
     * Only the panel's own files say it, never the request: a Host header is whatever the visitor typed, and a request
     * routed to this site with another site's Host made the root index.php replace THAT site's rewrite rules (R1-091).
     * A vhost file counts when one of its "root" directives (comments aside) is this folder or its public/. First the
     * file named after the folder (aaPanel's default: /www/wwwroot/<domain>), else every vhost file; the answer of that
     * scan is kept for 10 minutes in the system's temporary folder, so a request reads one file, not the whole panel.
     * Null when no vhost file serves this folder, or the panel's folder cannot be read.
     */
    public static function siteFor(string $projectRoot, ?string $panelRoot = null): ?string
    {
        $vhostDir = rtrim($panelRoot ?? self::$panelRoot, '/').'/vhost/nginx';
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($projectRoot === '' || ! @is_dir($vhostDir)) {
            return null;
        }

        $serves = static function (string $name) use ($vhostDir, $projectRoot): bool {
            if (! self::isSiteName($name)) {
                return false;
            }

            $text = @file_get_contents($vhostDir.'/'.$name.'.conf');

            return is_string($text) && self::rootsServe($text, $projectRoot);
        };

        $folder = basename($projectRoot);
        if ($serves($folder)) {
            return $folder;
        }

        $marker = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/avdbcms_aapanel_'.md5($vhostDir.'|'.$projectRoot);
        $remembered = @is_file($marker) && (time() - (int) @filemtime($marker)) < self::SCAN_SECONDS ? trim((string) @file_get_contents($marker)) : null;
        if ($remembered !== null) {
            return $remembered !== '-' && $serves($remembered) ? $remembered : ($remembered === '-' ? null : self::scan($vhostDir, $serves, $marker));
        }

        return self::scan($vhostDir, $serves, $marker);
    }

    /**
     * Write the rules into the rewrite file of this project's aaPanel site (siteFor()) when shouldWrite() says so, test
     * nginx and reload it; when the test blames the rules, the file goes back exactly as it was. The root index.php
     * runs it on aaPanel servers. Returns what happened (for tests): no-panel, no-site, unreadable, kept, write-failed,
     * written, reloaded or restored.
     *
     * @param  (callable(string): array{0: int, 1: list<string>})|null  $run  runs a shell command: [exit status, output lines]
     */
    public static function apply(string $projectRoot, ?string $panelRoot = null, ?callable $run = null): string
    {
        $panelRoot = rtrim($panelRoot ?? self::$panelRoot, '/');
        $rewriteDir = $panelRoot.'/vhost/rewrite';

        if (! @is_dir($rewriteDir)) {
            return 'no-panel';
        }

        $site = self::siteFor($projectRoot, $panelRoot);
        if ($site === null) {
            return 'no-site';
        }

        $rewriteFile = $rewriteDir.'/'.$site.'.conf';
        // The file as it is (null: there is none); one PHP cannot read is never replaced.
        $previous = null;
        if (@is_file($rewriteFile)) {
            $raw = @file_get_contents($rewriteFile);
            if ($raw === false) {
                return 'unreadable';
            }
            $previous = $raw;
        }

        if (! self::shouldWrite(trim((string) $previous), self::otherNginxFiles($site, $panelRoot))) {
            return 'kept';
        }

        if (! @file_put_contents($rewriteFile, self::rules(rtrim(str_replace('\\', '/', $projectRoot), '/'))."\n")) {
            return 'write-failed';
        }

        $run ??= self::shellRunner();
        if ($run === null) {
            return 'written';
        }

        [$status, $output] = $run('nginx -t 2>&1');
        if ($status === 0) {
            $run('nginx -s reload 2>&1');

            return 'reloaded';
        }

        if (self::testBlamesRules(implode("\n", $output), $rewriteFile)) {
            // nginx refuses the new rules: the panel's file goes back exactly as it was, so no later reload or restart
            // of the panel fails because of them.
            if ($previous === null) {
                @unlink($rewriteFile);
            } else {
                @file_put_contents($rewriteFile, $previous);
            }

            return 'restored';
        }

        return 'written';
    }

    /**
     * The site's other nginx files in the same server block: aaPanel's vhost file and every *.conf of its extension
     * folder, null for one PHP cannot read (then nothing is written: see shouldWrite()).
     *
     * @return list<string|null>
     */
    private static function otherNginxFiles(string $site, string $panelRoot): array
    {
        $vhostRoot = $panelRoot.'/vhost/nginx';
        if (! @is_dir($vhostRoot)) {
            return [null];
        }

        $others = [];
        $vhostFile = $vhostRoot.'/'.$site.'.conf';
        if (@is_file($vhostFile)) {
            $text = @file_get_contents($vhostFile);
            $others[] = $text === false ? null : $text;
        }

        $extensionDir = $vhostRoot.'/extension/'.$site;
        if (@is_dir($extensionDir)) {
            $entries = @scandir($extensionDir);
            if ($entries === false) {
                return [...$others, null];
            }
            foreach ($entries as $entry) {
                if (str_ends_with($entry, '.conf') && @is_file($extensionDir.'/'.$entry)) {
                    $text = @file_get_contents($extensionDir.'/'.$entry);
                    $others[] = $text === false ? null : $text;
                }
            }
        }

        return $others;
    }

    /** Every vhost file, in name order: the first that serves the folder (the answer, also "none", is kept). */
    private static function scan(string $vhostDir, \Closure $serves, string $marker): ?string
    {
        $files = @glob($vhostDir.'/*.conf') ?: [];
        sort($files);
        $found = null;

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($serves($name)) {
                $found = $name;
                break;
            }
        }

        @file_put_contents($marker, $found ?? '-');

        return $found;
    }

    /** Whether a "root" directive of this nginx text (comments aside) is the project folder or its public/. */
    private static function rootsServe(string $config, string $projectRoot): bool
    {
        $text = preg_replace('/#[^\n]*/', '', str_replace("\r", '', $config)) ?? '';
        if (preg_match_all('/(?:^|[\s;{}])root\s+("[^"]*"|\'[^\']*\'|[^\s;]+)\s*;/', $text, $matches) < 1) {
            return false;
        }

        $wanted = [$projectRoot, $projectRoot.'/public'];
        $real = @realpath($projectRoot);
        if (is_string($real) && $real !== '') {
            $real = rtrim(str_replace('\\', '/', $real), '/');
            $wanted[] = $real;
            $wanted[] = $real.'/public';
        }

        foreach ($matches[1] as $root) {
            $root = rtrim(str_replace('\\', '/', trim($root, "\"'")), '/');
            $realRoot = @realpath($root);
            $candidates = [$root];
            if (is_string($realRoot) && $realRoot !== '') {
                $candidates[] = rtrim(str_replace('\\', '/', $realRoot), '/');
            }
            if (array_intersect($candidates, $wanted) !== []) {
                return true;
            }
        }

        return false;
    }

    /** A vhost file's name aaPanel could have written: a domain-like name, never a path. */
    private static function isSiteName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1;
    }

    /** exec() when the host allows it, else null (the rules are written; nginx picks them up on its next reload). */
    private static function shellRunner(): ?callable
    {
        if (! function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }

        return static function (string $command): array {
            $output = [];
            $status = 1;
            @exec($command, $output, $status);

            return [(int) $status, $output];
        };
    }

    /**
     * Whether index.php should (re)write the rewrite file that currently holds $current.
     *
     * $others are the site's other nginx files that end up in the same server block: aaPanel's vhost file
     * (/www/server/panel/vhost/nginx/<domain>.conf) and every *.conf of its extension folder
     * (/www/server/panel/vhost/nginx/extension/<domain>/), null for one PHP could not read. When one of them already
     * declares a location the rules declare, nothing is written: nginx refuses duplicate locations ("nginx -t" fails),
     * and the next reload or restart of the panel would fail for every site on the server. That is the state of a site
     * set up with the 1.2.2 guide's second option (the laravel5 preset in URL rewrite plus docs/server-configs/
     * nginx-project-root.conf, without its "location /", in the extension folder), whose storage/ is already closed. A
     * file that cannot be read counts as such a conflict.
     *
     * @param  list<string|null>  $others
     */
    public static function shouldWrite(string $current, array $others = []): bool
    {
        if (! self::wouldReplace(trim($current))) {
            return false;
        }

        foreach ($others as $other) {
            if ($other === null || self::sharedLocations($other) !== []) {
                return false;
            }
        }

        return true;
    }

    /** Whether the rules replace this content of the rewrite file (the file alone; see shouldWrite()). */
    private static function wouldReplace(string $current): bool
    {
        // Rules of an earlier release: upgraded when they lack one of the current rules' locations (the storage/ one
        // up to 1.2.0; the upload folders' and the videos' /_internal/ up to 1.2.3) or still let /storage/ run .php5.
        if (str_contains($current, '# AVDBClip auto-configured')) {
            return array_diff(self::prefixLocations(self::rules('/project')), self::prefixLocations($current)) !== []
                || ! str_contains($current, self::SCRIPTS);
        }

        if (self::isLaravelPreset($current)) {
            return true;
        }

        // Somebody's own try_files rules are left alone.
        if (str_contains($current, 'try_files')) {
            return false;
        }

        return $current === ''
            || ! str_contains($current, 'location')
            || str_contains($current, 'server {')
            || str_contains($current, 'server{');
    }

    /**
     * The prefix locations ("location /x/ {" and "location ^~ /x/ {", not "=" or regular expressions) that both the rules
     * and $config declare: nginx refuses such a pair in one server block ("duplicate location").
     *
     * @return list<string>
     */
    public static function sharedLocations(string $config): array
    {
        return array_values(array_intersect(self::prefixLocations(self::rules('/project')), self::prefixLocations($config)));
    }

    /**
     * The paths of the prefix locations a piece of nginx configuration declares, comments aside.
     *
     * @return list<string>
     */
    public static function prefixLocations(string $config): array
    {
        $text = preg_replace('/#[^\n]*/', '', str_replace("\r", '', $config)) ?? '';
        preg_match_all('/(?:^|[\s;{}])location\s+(?:\^~\s*)?(\/[^\s{;]*)\s*\{/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether a failed "nginx -t" after the rules were written blames them: a duplicate location, or an error in the
     * rewrite file itself. Then index.php puts the file back as it was. A test that fails for another reason (PHP's user
     * may not open nginx's logs or pid file) leaves the rules in place; nginx is then not reloaded either.
     */
    public static function testBlamesRules(string $output, string $rewriteFile): bool
    {
        if (stripos($output, 'duplicate location') !== false) {
            return true;
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if ($rewriteFile !== '' && str_contains($line, $rewriteFile) && preg_match('/\[(emerg|alert|crit|error)\]/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text is aaPanel's laravel5 preset and nothing else: one "location / { try_files $uri $uri/
     * /index.php$is_args$query_string; }" block (or the "?$query_string" form), comments and white space aside.
     */
    public static function isLaravelPreset(string $content): bool
    {
        $text = preg_replace('/#[^\n]*/', '', str_replace("\r", '', $content)) ?? '';
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        $text = str_replace(['{ ', ' }', ' ;'], ['{', '}', ';'], $text);
        $text = str_replace(['location /{', ';}'], ['location / {', '; }'], $text);

        return in_array($text, [
            'location / {try_files $uri $uri/ /index.php$is_args$query_string; }',
            'location / {try_files $uri $uri/ /index.php?$query_string; }',
        ], true);
    }

    /**
     * Script files an upload folder never runs (.php, .php3 ... .php8, .phar, .phtml, .pht, .phps, also as x.php.jpg or
     * x.php/extra): the same expression as docs/server-configs/ and the .htaccess files.
     */
    public const SCRIPTS = '\.ph(p[0-9]*|ar|tml|t|ps)(\.|/|$)';

    /** Database dumps, logs and backup copies, never handed out as files (the same list as docs/server-configs/). */
    public const DUMPS = '(\.(sql|sqlite3?|db|dump|log|bak|backup|old|orig|save|swp|gz|tgz|tar)|~)$';

    /** Dotfiles in any folder (.env, .git, public/.user.ini ...); ACME challenges under /.well-known/ still work. */
    public const DOTFILES = '/\.(?!well-known/)';

    /** The folders of the project that are never served (prefix locations: they win over a panel's PHP regex). */
    public const CLOSED_FOLDERS = ['app', 'bootstrap', 'config', 'database', 'docker', 'helpers', 'lang', 'resources', 'routes', 'tests', 'bin', 'node_modules', 'docs'];

    /** The files of the project folder that are never served (exact locations). */
    public const CLOSED_FILES = [
        'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'vite.config.js',
        'vite.config.ts', 'docker-compose.yml', 'nginx.conf', 'nginx-aapanel-rewrite.conf', 'php-cli.ini', 'web.config',
        'README.md', 'COMMUNITY_SETUP_GUIDE.md',
        // round 3 R3-039: the package's documents and its file list (the exact version and every file's SHA-256)
        'UPGRADE.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'avdbcms-manifest.json',
    ];

    /**
     * The site's own addresses that end like a file (a video stored on this server, /video/file/<name>.mp4; an
     * administrator's download of a backup): always the application, also where a panel's static-file rule would answer
     * them with a 404 (CloudPanel's location ~* ^.+\.(css|js|...|gz|mp4|...|zip)$).
     */
    public const APPLICATION_PREFIXES = ['/video/file/', '/admin/'];

    /**
     * The rules, for a project in $projectRoot (no trailing slash): exactly the locations of
     * docs/server-configs/nginx-project-root.conf (tests/Unit/ServerConfigsTest compares them), without its gzip lines
     * (aaPanel's own nginx.conf compresses already).
     */
    public static function rules(string $projectRoot): string
    {
        $neverRun = '    location ~* '.self::SCRIPTS.' { return 404; }';
        $neverHandOut = '    location ~* '.self::DUMPS.' { return 404; }';

        $lines = [
            self::MARK,
            'location / {',
            '    try_files $uri $uri/ /index.php?$query_string;',
            '}',
            '',
            '# Public-disk files (the php artisan storage:link target) are served straight',
            '# from storage/app/public, so they work even when PHP symlink() is disabled.',
            '# Nothing else under storage/ is ever served; a script there never runs.',
            'location ^~ /storage/ {',
            '    alias '.$projectRoot.'/storage/app/public/;',
            $neverRun,
            $neverHandOut,
            '    try_files $uri =404;',
            '    expires 1d;',
            '}',
            '',
            '# The same folders under their public/ path, and public/uploads (the upload folder',
            '# of older releases): files only, never a script.',
            'location ^~ /public/storage/ {',
            $neverRun,
            $neverHandOut,
            '    try_files $uri =404;',
            '}',
            'location ^~ /public/uploads/ {',
            $neverRun,
            $neverHandOut,
            '    try_files $uri =404;',
            '}',
            'location ^~ /uploads/ {',
            '    alias '.$projectRoot.'/public/uploads/;',
            $neverRun,
            $neverHandOut,
            '    try_files $uri =404;',
            '}',
            '',
            '# Video files stored on this server, handed to nginx by PHP (X-Accel-Redirect);',
            '# never reachable directly.',
            'location ^~ /_internal/ {',
            '    internal;',
            '    alias '.$projectRoot.'/storage/app/private/;',
            '}',
            '',
            '# The site\'s own addresses that end like a file (a video, a backup download)',
            '# always reach the application, whatever static-file rule the panel has.',
        ];

        foreach (self::APPLICATION_PREFIXES as $prefix) {
            $lines[] = 'location ^~ '.$prefix.' {';
            $lines[] = '    rewrite ^ /index.php last;';
            $lines[] = '}';
        }

        array_push($lines,
            '',
            '# Published package assets (Horizon, Log Viewer, Umami) live in public/vendor;',
            '# the composer vendor/ source tree is never served (missing file = plain 404).',
            'location ^~ /vendor/ {',
            '    alias '.$projectRoot.'/public/vendor/;',
            $neverRun,
            '    try_files $uri =404;',
            '}',
            '',
            '# Database dumps, logs and backup copies are never handed out; the site\'s own',
            '# addresses that end like that (an admin\'s download of a backup) reach the application.',
            'location ~* '.self::DUMPS.' {',
            '    rewrite ^ /index.php last;',
            '}',
            '',
            '# Never serve application source, config, tests, dotfiles or install data.',
            '# Prefix locations with ^~ win over the panel regex PHP handler whatever the order.',
            'location ~ '.self::DOTFILES.' { return 404; }',
        );

        foreach (self::CLOSED_FOLDERS as $folder) {
            $lines[] = 'location ^~ /'.$folder.'/ { return 404; }';
        }

        foreach (self::CLOSED_FILES as $file) {
            $lines[] = 'location = /'.$file.' { return 404; }';
        }

        return implode("\n", $lines);
    }
}
