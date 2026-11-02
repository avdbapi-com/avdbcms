<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Controllers\Auth\ReturnPath;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 3 R3-079: the phone bottom bar's Sign in / Create account and the guest prompt's links (layout.blade.php) carried
 * the request's address as received as their return address: for //evil.example/x they linked to
 * /login?redirect=%2F%2Fevil.example%2Fx while the header's Sign in (ReturnPath::loginUrl()) gave %2Fevil.example%2Fx.
 * The sign-in page checks the value again (ReturnPath::rememberFrom()), so it led nowhere else, but the links' safety
 * rested on that check alone. Now every sign-in link carries its return address through ReturnPath::loginUrl()
 * (safePath(): a path of this site), like the header; Create account carries none (the sign-up page reads none), as in
 * the header and the guest prompt. The guest Subscribe and the Subscriptions page's Sign in too (the watch page, the
 * playlist page's Save and the channel menu's report link: proposals to the lanes that own those files).
 */
class SignInLinksCarryASafeReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.mobile_bottom_nav_enabled' => true,
            'site.registration_enabled' => true,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    public function test_a_crafted_address_gives_the_layouts_sign_in_links_a_path_of_this_site(): void
    {
        foreach ([
            '//evil.example/x' => '/evil.example/x',
            '//evil.example/x?a=1' => '/evil.example/x?a=1',
            '///evil.example' => '/evil.example',
        ] as $address => $kept) {
            $html = (string) $this->get($this->raw($address))->getContent();
            $links = $this->authLinks($html);

            $this->assertNotEmpty($links['bottom bar'], $address.': the bottom bar is printed');
            foreach ($links as $where => $hrefs) {
                foreach ($hrefs as $href) {
                    $this->assertDoesNotMatchRegularExpression('#redirect=(%2F){2}|redirect=//#i', $href, $address.' '.$where.': '.$href);
                }
            }
            $expected = ReturnPath::loginUrl($kept);
            $this->assertContains($expected, $links['bottom bar'], $address.': the bottom bar\'s Sign in is the header\'s form');
            $this->assertSame($expected, $links['config loginUrl'][0] ?? null, $address.': the guest prompt\'s address');
            $this->assertContains(locale_url(route('registration')), $links['bottom bar'], $address.': Create account without a return address');
            $this->assertSame(locale_url(route('registration')), $links['config registerUrl'][0] ?? null, $address);
        }
    }

    public function test_an_ordinary_page_keeps_its_return_address_and_its_language(): void
    {
        $html = (string) $this->get('/discover?sort=new')->getContent();
        $links = $this->authLinks($html);
        $this->assertContains('/login?redirect='.rawurlencode('/discover?sort=new'), $links['bottom bar']);
        $this->assertSame('/login?redirect='.rawurlencode('/discover?sort=new'), $links['config loginUrl'][0] ?? null);

        // the home page and the sign-in pages: no return address (as the header)
        $this->assertContains('/login', $this->authLinks((string) $this->get('/')->getContent())['bottom bar']);
        $this->assertContains('/login', $this->authLinks((string) $this->get('/login')->getContent())['bottom bar']);

        // in another language (public/index.php took /fr off the address): the language's sign-in page, back to the
        // page in that language
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $html = (string) $this->get('/discover')->getContent();
        unset($_SERVER['DETECTED_LOCALE']);
        $links = $this->authLinks($html);
        $this->assertContains('/fr/login?redirect='.rawurlencode('/fr/discover'), $links['bottom bar']);
        $this->assertContains('/fr/register', $links['bottom bar']);
    }

    public function test_the_guest_subscribe_and_the_subscriptions_page_use_the_same_return_address(): void
    {
        $channel = User::factory()->create(['email_verified_at' => now()]);
        Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        $path = parse_url(route('user.show', $channel), PHP_URL_PATH);
        $html = html_entity_decode((string) $this->get($path)->assertOk()->getContent(), ENT_QUOTES);
        $this->assertMatchesRegularExpression('#class="[^"]*mk-subscribe[^"]*" href="'.preg_quote(ReturnPath::loginUrl($path), '#').'" data-auth-prompt="mkAuthSubscribe"#', $html);

        $html = html_entity_decode((string) $this->get('/subscriptions')->assertOk()->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('<a class="mk-btn mk-btn--primary" href="'.ReturnPath::loginUrl('/subscriptions').'">', $html);
    }

    public function test_the_layout_builds_its_sign_in_links_only_through_return_path(): void
    {
        foreach (['resources/views/components/layout.blade.php', 'resources/views/library/subscribe.blade.php', 'resources/views/subscription/index.blade.php'] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression("#'redirect'\s*=>\s*request\(\)->getRequestUri\(\)#", $source, $file.': no raw request address as a return address');
        }
    }

    /** An absolute address whose path is kept exactly as written (a leading "//" too). */
    private function raw(string $address): string
    {
        return rtrim((string) config('app.url'), '/').$address;
    }

    /** @return array{'bottom bar': list<string>, 'config loginUrl': list<string>, 'config registerUrl': list<string>} */
    private function authLinks(string $html): array
    {
        $links = ['bottom bar' => [], 'config loginUrl' => [], 'config registerUrl' => []];

        if (preg_match('#<nav class="avd-mobile-bottom-nav[^"]*"[^>]*>(.*?)</nav>#s', $html, $nav) === 1) {
            preg_match_all('#href="([^"]*/(?:login|register)[^"]*)"#', $nav[1], $m);
            $links['bottom bar'] = array_map(fn (string $href) => $this->local(html_entity_decode($href, ENT_QUOTES)), $m[1]);
        }

        foreach (['loginUrl', 'registerUrl'] as $key) {
            if (preg_match('#"'.$key.'":"([^"]*)"#', $html, $m) === 1) {
                $links['config '.$key] = [$this->local(json_decode('"'.$m[1].'"'))];
            }
        }

        return $links;
    }

    private function local(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
