<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Member\DangerMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * 1.2.3 round 1 (R1-070): the member CSS entry (resources/sass/member.scss) was 138 KB / 23 KB gz, over its 130 / 22 KB
 * budget, render-blocking on every page of a signed-in visitor. Two parts a page rarely needs left it:
 * - the member danger tokens per look: the layout prints the page's own look's rules beside the entry
 *   (<style id="avd-member-danger">, App\Support\Member\DangerMap), wherever the entry is printed and nowhere else;
 * - the sign-in pages' rules (member/_auth.scss): their own entry (resources/sass/auth-pages.scss), printed after the
 *   member entry on the sign-in, contact, account and error pages only.
 * 1.2.3 round 3 (R1-070 = R2R-10: the entry had grown back to 131.5 KB / 23.0 KB gz): the channel page's own rules
 * (member/_channel-page.scss) are their own entry too (resources/sass/channel-page.scss), linked by the channel page
 * only, after the member entry.
 */
class MemberCssEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER = 'resources/sass/member.scss';

    private const AUTH = 'resources/sass/auth-pages.scss';

    private const CHANNEL = 'resources/sass/channel-page.scss';

    protected function setUp(): void
    {
        parent::setUp();

        // @vite() prints which entries a page asks for (the tests have no build of their own); the rest as withoutVite().
        Facade::clearResolvedInstance(Vite::class);
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new HtmlString(implode('', array_map(static fn (string $entry): string => '<link rel="stylesheet" data-test-entry="'.$entry.'">', (array) $entrypoints)));
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function isRunningHot()
            {
                return false;
            }

            public function cspNonce()
            {
                return null;
            }

            public function preloadedAssets()
            {
                return [];
            }

            public function reactRefresh()
            {
                return '';
            }

            public function content($asset, $buildDirectory = null)
            {
                return '';
            }

            public function asset($asset, $buildDirectory = null)
            {
                return '/build/'.str_replace('/', '~', $asset);
            }
        });
    }

    public function test_a_signed_in_visitors_public_page_has_the_member_entry_and_its_looks_danger_rules_only(): void
    {
        $member = User::factory()->create();
        $html = $this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertSame([self::MEMBER], $this->memberEntries($html));
        $this->assertDangerRulesOfTheLook($html);
    }

    public function test_a_guests_public_page_has_neither(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertSame([], $this->memberEntries($html));
        $this->assertStringNotContainsString('id="avd-member-danger"', $html);
    }

    public function test_the_sign_in_contact_and_account_pages_add_the_auth_part_after_the_member_entry(): void
    {
        foreach ([route('login'), route('registration'), route('password.forgot'), route('contact.show')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([self::MEMBER, self::AUTH], $this->memberEntries($html), 'guest '.$url);
            $this->assertDangerRulesOfTheLook($html);
        }

        $member = User::factory()->create();
        foreach ([route('user.edit'), route('logout'), route('contact.show')] as $url) {
            $html = $this->actingAs($member)->get($url)->assertOk()->getContent();
            $this->assertSame([self::MEMBER, self::AUTH], $this->memberEntries($html), 'member '.$url);
        }

        // a member page without sign-in rules
        $html = $this->actingAs($member)->get(route('pages.history'))->assertOk()->getContent();
        $this->assertSame([self::MEMBER], $this->memberEntries($html), 'history');
    }

    public function test_the_channel_page_adds_its_own_rules_after_the_member_entry_and_no_other_page_does(): void
    {
        $channel = User::factory()->create(['show_subscribers' => true]);
        $this->assertSame([self::MEMBER, self::CHANNEL], $this->memberEntries($this->get($channel->route)->assertOk()->getContent()), 'guest');
        $member = User::factory()->create();
        $this->assertSame([self::MEMBER, self::CHANNEL], $this->memberEntries($this->actingAs($member)->get($channel->route)->assertOk()->getContent()), 'member');
        $this->assertSame([self::MEMBER, self::CHANNEL], $this->memberEntries($this->actingAs($channel)->get($channel->route)->assertOk()->getContent()), 'its owner');

        foreach ([route('pages.history'), route('pages.library'), route('subscription.manage'), route('user.videos.index'), route('pages.trend')] as $url) {
            $this->assertSame([self::MEMBER], $this->memberEntries($this->actingAs($member)->get($url)->assertOk()->getContent()), $url);
        }
    }

    public function test_error_pages_print_both_once_for_guests_and_members(): void
    {
        $html = $this->get('/fc-no-such-page-r1070')->assertNotFound()->getContent();
        $this->assertStringContainsString('mk-status-page', $html);
        // the error page links the member entry itself for a guest (errors/minimal.blade.php), the layout the auth part
        $this->assertSame([self::MEMBER, self::AUTH], $this->memberEntries($html), 'guest 404');
        $this->assertDangerRulesOfTheLook($html);

        $member = User::factory()->create();
        $html = $this->actingAs($member)->get('/fc-no-such-page-r1070')->assertNotFound()->getContent();
        $this->assertSame([self::MEMBER, self::AUTH], $this->memberEntries($html), 'member 404');
        $this->assertDangerRulesOfTheLook($html);
    }

    public function test_the_entries_hold_what_they_should(): void
    {
        $member = (string) file_get_contents(base_path('resources/sass/member.scss'));
        foreach (['member/kit', 'member/studio', 'member/account', 'member/channel', 'member/notify', 'member/chrome'] as $part) {
            $this->assertStringContainsString('@import "'.$part.'";', $member, $part);
        }
        $this->assertStringNotContainsString('@import "member/auth";', $member);
        $this->assertStringNotContainsString('@import "member/danger";', $member);
        $this->assertStringContainsString('@import "member/auth";', (string) file_get_contents(base_path('resources/sass/auth-pages.scss')));
        $this->assertStringContainsString("'auth-pages': 'resources/sass/auth-pages.scss'", (string) file_get_contents(base_path('vite.config.ts')));
        $this->assertStringNotContainsString('@import "member/channel-page";', $member);
        $this->assertStringContainsString('@import "member/channel-page";', (string) file_get_contents(base_path('resources/sass/channel-page.scss')));
        $this->assertStringContainsString("'channel-page': 'resources/sass/channel-page.scss'", (string) file_get_contents(base_path('vite.config.ts')));
    }

    /** The stylesheets of the member kit a page asks for, in order. @return list<string> */
    private function memberEntries(string $html): array
    {
        preg_match_all('#data-test-entry="(resources/sass/(?:member|auth-pages|channel-page)\.scss)"#', $html, $m);

        return $m[1];
    }

    private function assertDangerRulesOfTheLook(string $html): void
    {
        $this->assertSame(1, preg_match('/<html\b[^>]*\bdata-avd-theme="([a-z0-9_]+)"/', $html, $theme), 'the look');
        preg_match('/<html\b[^>]*\bdata-avd-flavor="([a-z0-9_]*)"/', $html, $flavor);
        $expected = DangerMap::css($theme[1], $flavor[1] ?? '');

        if ($expected === '') {
            $this->assertStringNotContainsString('id="avd-member-danger"', $html);

            return;
        }

        $this->assertSame(1, substr_count($html, '<style id="avd-member-danger">'.$expected.'</style>'), 'the look\'s danger rules, once');
    }
}
