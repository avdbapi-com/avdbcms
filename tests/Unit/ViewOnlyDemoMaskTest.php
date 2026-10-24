<?php

namespace Tests\Unit;

use App\Http\Middleware\ViewOnlyDemo;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.2, lane V: what the view-only demo account is shown (App\Http\Middleware\ViewOnlyDemo::mask / maskJson /
 * withNote). Secrets, e-mail addresses, IP addresses, server paths, session ids and credential fields are masked; a
 * JSON answer stays valid JSON; the site's own address, asset names, times and the CSRF field stay.
 */
class ViewOnlyDemoMaskTest extends TestCase
{
    public function test_secret_values_in_all_their_text_forms_are_masked(): void
    {
        $secret = 'p@ss/"word"&1';
        $secrets = [];
        foreach ([$secret, htmlspecialchars($secret, ENT_QUOTES), rawurlencode($secret), substr((string) json_encode($secret), 1, -1)] as $form) {
            $secrets[$form] = '••••••';
        }

        $text = 'a '.$secret.' b '.htmlspecialchars($secret, ENT_QUOTES).' c '.rawurlencode($secret).' d '.json_encode(['v' => $secret]);
        $masked = ViewOnlyDemo::mask($text, $secrets, false);

        $this->assertStringNotContainsString('word', $masked);
        $this->assertSame(4, substr_count($masked, '••••••'));
    }

    public function test_keys_credentials_and_webhooks_are_masked(): void
    {
        $text = implode("\n", [
            '-----BEGIN RSA PRI'.'VATE KEY-----MIIEow'.str_repeat('A', 40).'-----END RSA PRI'.'VATE KEY-----',
            'redis://owner:S3cr3tPass@cache.internal:6379/0',
            'https://hooks.slack.com/services/T000/B000/XXXXXXXX',
            'sk_live_'.str_repeat('a1', 12).' rk_test_'.str_repeat('b2', 6).' whsec_'.str_repeat('c3', 8),
            'APP_KEY=base64:'.str_repeat('Q', 43).'=',
        ]);

        $masked = ViewOnlyDemo::mask($text, [], false);

        $this->assertStringContainsString('[private key]', $masked);
        $this->assertStringNotContainsString('MIIEow', $masked);
        $this->assertStringContainsString('redis://••••••@cache.internal', $masked);
        $this->assertStringNotContainsString('S3cr3tPass', $masked);
        $this->assertStringContainsString('[webhook]', $masked);
        $this->assertStringContainsString('sk_live_••••••', $masked);
        $this->assertStringContainsString('rk_test_••••••', $masked);
        $this->assertStringContainsString('whsec_••••••', $masked);
        $this->assertStringContainsString('base64:••••••', $masked);
    }

    public function test_emails_and_addresses_are_masked_but_the_site_host_assets_and_times_stay(): void
    {
        $text = 'Mail owner.private@relay-mail.example.com, logo icon@2x.png, from 203.0.113.9 and 2001:db8:85a3::8a2e:370:7334, '
            .'site http://10.20.30.40/admin at 10:44:12, version 1.2.2';

        $masked = ViewOnlyDemo::mask($text, [], false, ['10.20.30.40']);

        $this->assertStringContainsString('o•••@r•••.com', $masked);
        $this->assertStringNotContainsString('owner.private', $masked);
        $this->assertStringContainsString('icon@2x.png', $masked);
        $this->assertStringContainsString('203.0.x.x', $masked);
        $this->assertStringContainsString('2001:db8:x:x', $masked);
        $this->assertStringContainsString('http://10.20.30.40/admin', $masked);
        $this->assertStringContainsString('10:44:12', $masked);
        $this->assertStringContainsString('version 1.2.2', $masked);
    }

    public function test_server_paths_are_masked_plain_escaped_and_the_sites_own_folder(): void
    {
        $text = 'log /home/owner/public_html/storage/logs/laravel.log json "\/var\/www\/site\/.env" own /data/sites/avd-site/config/app.php url https://example.com/srv/x';

        $masked = ViewOnlyDemo::mask($text, [], false, [], ['/data/sites/avd-site']);

        $this->assertStringNotContainsString('/home/owner', $masked);
        $this->assertStringNotContainsString('var\/www', $masked);
        $this->assertStringNotContainsString('/data/sites/avd-site', $masked);
        $this->assertStringContainsString('[server path]/config/app.php', $masked);
        // a path in a link of another site is not a server path
        $this->assertStringContainsString('https://example.com/srv/x', $masked);
    }

    public function test_a_path_inside_an_html_escaped_json_attribute_leaves_the_attribute_whole(): void
    {
        $html = '<div data-props="{&quot;file&quot;:&quot;/home/owner/x.mp4&quot;,&quot;n&quot;:1}"></div>';

        $masked = ViewOnlyDemo::mask($html);

        $this->assertStringNotContainsString('/home/owner', $masked);
        $this->assertStringContainsString('&quot;,&quot;n&quot;:1}"></div>', $masked);
        $decoded = json_decode(html_entity_decode((string) preg_replace('/^.*data-props="([^"]*)".*$/s', '$1', $masked)), true);
        $this->assertSame(1, $decoded['n'] ?? null);
    }

    public function test_credential_fields_are_emptied_and_session_ids_hidden(): void
    {
        $html = '<input type="hidden" name="_token" value="csrf-token-value-123">'
            .'<input name="smtp_password" type="password" value="hunter22-secret">'
            .'<input name="site_name" value="My Site">'
            .'<textarea name="captcha_secret_key">secret-in-textarea</textarea>'
            .'<a href="/admin/sessions/'.str_repeat('a', 40).'">x</a>';

        $masked = ViewOnlyDemo::mask($html);

        $this->assertStringContainsString('value="csrf-token-value-123"', $masked);
        $this->assertStringContainsString('name="smtp_password" type="password" value=""', $masked);
        $this->assertStringContainsString('value="My Site"', $masked);
        $this->assertStringNotContainsString('secret-in-textarea', $masked);
        $this->assertStringContainsString('/admin/sessions/'.str_repeat('x', 40).'"', $masked);
    }

    public function test_a_json_answer_stays_valid_json(): void
    {
        $data = [
            'quote' => 'He said "admin@owner-site.test" \\ and left',
            'path' => '/home/owner/public_html/x.log',
            'ip' => '198.51.100.23',
            'session' => '/admin/sessions/'.str_repeat('b', 40).'/x',
            'empty' => new \stdClass(),
            'list' => [],
            'n' => 1.0,
        ];
        $json = (string) json_encode($data);

        $masked = ViewOnlyDemo::maskJson($json);
        $decoded = json_decode($masked, true);

        $this->assertIsArray($decoded);
        $this->assertStringNotContainsString('admin@owner-site.test', $decoded['quote']);
        $this->assertStringContainsString('He said "', $decoded['quote']);
        $this->assertSame('[server path]', $decoded['path']);
        $this->assertSame('198.51.x.x', $decoded['ip']);
        $this->assertStringNotContainsString(str_repeat('b', 40), $decoded['session']);
        $this->assertStringContainsString('"empty":{}', $masked);
        $this->assertStringContainsString('"list":[]', $masked);
    }

    public function test_when_the_text_mask_would_break_the_json_each_string_is_masked_instead(): void
    {
        // a secret that is also a piece of JSON syntax: replacing it in the text would break the answer
        $json = '{"a":"x","b":{"":"the value is \"x\"","0":"y"},"c":[]}';

        $masked = ViewOnlyDemo::maskJson($json, ['":"' => '••••••']);
        $decoded = json_decode($masked);

        $this->assertNotNull($decoded, 'still valid JSON');
        $this->assertSame('x', $decoded->a);
        $this->assertSame('y', $decoded->b->{'0'});
        $this->assertSame([], $decoded->c);
    }

    public function test_the_note_goes_after_the_body_tag_of_the_page_only(): void
    {
        $page = '<html><head><script>var t = "<body>";</script></head><body class="x"><p>Hi</p></body></html>';
        $noted = ViewOnlyDemo::withNote($page);

        $this->assertSame(1, substr_count($noted, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'));
        $this->assertStringContainsString('var t = "<body>";', $noted);
        $this->assertMatchesRegularExpression('/<body class="x"><style>\.'.ViewOnlyDemo::NOTE_CLASS.'\{/', $noted);

        $fragment = '<tr><td>row</td></tr>';
        $this->assertSame($fragment, ViewOnlyDemo::withNote($fragment));
    }
}
