<?php

namespace Tests\Unit;

use App\Support\AaPanelRewrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security plan S03 part 0: the root index.php writes AVDBCMS's nginx rules into aaPanel's rewrite file also when that
 * file holds exactly aaPanel's laravel5 preset (its plain try_files serves every file under storage/), in both query
 * forms and with comments; any other rule somebody wrote stays as it is, and an earlier release's rules without the
 * storage/ location are upgraded as before.
 */
class AaPanelRewriteTest extends TestCase
{
    public static function written(): array
    {
        $preset = "location / {\n\ttry_files \$uri \$uri/ /index.php\$is_args\$query_string;\n}\n";

        return [
            'an empty file (aaPanel\'s default)' => [''],
            'the laravel5 preset' => [$preset],
            'the laravel5 preset with the ?$query_string form' => ["location / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}"],
            'the laravel5 preset with comments and odd spacing' => ["# laravel5 rewrite\r\nlocation /{   try_files  \$uri \$uri/ /index.php\$is_args\$query_string ;}   # end\n"],
            'a broken server block' => ["server {\n listen 80;\n}"],
            'only comments' => ["# nothing here yet\n"],
            'an earlier release\'s rules without the storage location' => [AaPanelRewrite::MARK."\nlocation / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}\n"],
            // Round 1b R1B-002 / R1B-003: 1.2.3 RC1's rules let /public/storage/x.php and /public/uploads/x.php run and had
            // no internal location for the videos: they are upgraded once.
            'the rules of 1.2.3 RC1' => [AaPanelRewrite::MARK."\nlocation / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}\n"
                ."location ^~ /storage/ {\n    alias /www/wwwroot/example.com/storage/app/public/;\n    location ~ \\.php\$ { return 404; }\n    try_files \$uri =404;\n}\n"
                ."location ^~ /vendor/ {\n    alias /www/wwwroot/example.com/public/vendor/;\n    location ~ \\.php\$ { return 404; }\n    try_files \$uri =404;\n}\n"
                ."location ^~ /app/ { return 404; }\n"],
        ];
    }

    #[DataProvider('written')]
    public function test_the_rules_are_written(string $current): void
    {
        $this->assertTrue(AaPanelRewrite::shouldWrite($current));
    }

    public static function leftAlone(): array
    {
        return [
            'the current rules' => [AaPanelRewrite::rules('/www/wwwroot/example.com')],
            'somebody\'s own try_files rule' => ["location / {\n    try_files \$uri /index.php?\$query_string;\n}\nlocation /api { proxy_pass http://127.0.0.1:9000; }"],
            'a preset with an extra rule' => ["location / {\n\ttry_files \$uri \$uri/ /index.php\$is_args\$query_string;\n}\nlocation ~ \\.log$ { deny all; }"],
            'another location without try_files' => ["location /files { alias /data/files; }"],
        ];
    }

    #[DataProvider('leftAlone')]
    public function test_other_rules_are_left_alone(string $current): void
    {
        $this->assertFalse(AaPanelRewrite::shouldWrite($current));
    }

    /**
     * The 1.2.2 setup guide's second aaPanel option: the laravel5 preset in URL rewrite plus the include (without its
     * "location /") in the extension folder, which aaPanel puts into the same server block. Writing the rules over the
     * preset would declare /storage/, /vendor/ ... twice: nginx refuses that, and the next panel reload or restart fails
     * for every site on the server. So nothing is written; storage/ is closed by the include already.
     */
    public function test_nothing_is_written_when_the_server_block_already_declares_the_locations(): void
    {
        $preset = "location / {\n\ttry_files \$uri \$uri/ /index.php\$is_args\$query_string;\n}\n";
        $include = (string) file_get_contents(dirname(__DIR__, 2).'/docs/server-configs/nginx-project-root.conf');
        $withoutRoot = (string) preg_replace('/^location \/ \{\n[^}]*\}\n/m', '', $include);
        $this->assertStringNotContainsString("location / {\n", $withoutRoot);

        foreach (['the whole include' => $include, 'the include without its location /' => $withoutRoot,
            'one storage rule' => "location ^~/storage/ { alias /www/wwwroot/example.com/storage/app/public/; }",
            'a plain prefix location of the same path' => "location /vendor/ { deny all; }"] as $case => $extension) {
            $this->assertNotSame([], AaPanelRewrite::sharedLocations($extension), $case);
            $this->assertFalse(AaPanelRewrite::shouldWrite($preset, [self::VHOST, $extension]), $case);
            $this->assertFalse(AaPanelRewrite::shouldWrite('', [$extension]), $case.' (empty rewrite file)');
            $this->assertFalse(AaPanelRewrite::shouldWrite(AaPanelRewrite::MARK."\nlocation / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}\n", [$extension]), $case.' (an earlier release\'s rules)');
        }

        // A file that PHP cannot read might declare them: nothing is written.
        $this->assertFalse(AaPanelRewrite::shouldWrite($preset, [self::VHOST, null]));

        // aaPanel's own vhost file, an SSL or a redirect snippet in the extension folder: no shared location, written.
        $this->assertSame([], AaPanelRewrite::sharedLocations(self::VHOST));
        $this->assertTrue(AaPanelRewrite::shouldWrite($preset, [self::VHOST, "location ~ /\\.well-known { allow all; }\nlocation = /old { return 301 /new; }\n# location ^~ /storage/ { }"]));
        $this->assertTrue(AaPanelRewrite::shouldWrite($preset, []));

        // 1.2.3 round 1b review: the site's own addresses that end like a file (/video/file/, /admin/) and /docker/, as in
        // docs/server-configs/nginx-project-root.conf; round 3 R3-039: /docs/.
        $this->assertSame(['/', '/storage/', '/public/storage/', '/public/uploads/', '/uploads/', '/_internal/', '/video/file/', '/admin/', '/vendor/', '/app/', '/bootstrap/', '/config/', '/database/', '/docker/', '/helpers/', '/lang/', '/resources/', '/routes/', '/tests/', '/bin/', '/node_modules/', '/docs/'],
            AaPanelRewrite::prefixLocations(AaPanelRewrite::rules('/www/wwwroot/example.com')));
    }

    /** After the rules were written, a failed "nginx -t" that blames them puts the panel's file back; another failure does not. */
    public function test_a_failed_nginx_test_that_blames_the_rules_puts_the_file_back(): void
    {
        $file = '/www/server/panel/vhost/rewrite/example.com.conf';

        $this->assertTrue(AaPanelRewrite::testBlamesRules(
            "nginx: [emerg] duplicate location \"/storage/\" in /www/server/panel/vhost/nginx/extension/example.com/avdbcms.conf:12\nnginx: configuration file /www/server/nginx/conf/nginx.conf test failed",
            $file
        ));
        $this->assertTrue(AaPanelRewrite::testBlamesRules(
            "nginx: [emerg] unknown directive \"alias\" in {$file}:9\nnginx: configuration file /www/server/nginx/conf/nginx.conf test failed",
            $file
        ));
        // PHP's user may not open nginx's log or pid file: the rules are fine, they stay (nginx is not reloaded).
        $this->assertFalse(AaPanelRewrite::testBlamesRules(
            "nginx: [alert] could not open error log file: open() \"/www/server/nginx/logs/error.log\" failed (13: Permission denied)\nnginx: the configuration file /www/server/nginx/conf/nginx.conf syntax is ok\nnginx: [emerg] open() \"/www/server/nginx/logs/nginx.pid\" failed (13: Permission denied)\nnginx: configuration file /www/server/nginx/conf/nginx.conf test failed",
            $file
        ));
        $this->assertFalse(AaPanelRewrite::testBlamesRules('sh: 1: nginx: not found', $file));
    }

    /** The server block aaPanel writes for a site (trimmed): no prefix location of its own. */
    private const VHOST = <<<'NGINX'
server
{
    listen 80;
    server_name example.com;
    index index.php index.html index.htm default.php default.htm default.html;
    root /www/wwwroot/example.com;
    #REWRITE-START URL rewrite rule reference, any modification will invalidate the rewrite rules set by the panel
    include /www/server/panel/vhost/rewrite/example.com.conf;
    #REWRITE-END
    include /www/server/panel/vhost/nginx/extension/example.com/*.conf;
    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README.md)
    {
        return 404;
    }
    location ~ \.well-known{
        allow all;
    }
    location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$
    {
        expires      30d;
    }
    location ~ .*\.(js|css)?$
    {
        expires      12h;
    }
    access_log  /www/wwwlogs/example.com.log;
}
NGINX;

    public function test_the_rules_serve_only_the_public_disk_under_storage(): void
    {
        $rules = AaPanelRewrite::rules('/www/wwwroot/example.com');

        $this->assertStringContainsString("location ^~ /storage/ {\n    alias /www/wwwroot/example.com/storage/app/public/;", $rules);
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $rules);
        $this->assertStringStartsWith(AaPanelRewrite::MARK, $rules);
        $this->assertFalse(AaPanelRewrite::shouldWrite($rules), 'written once, then left alone');
    }
}
