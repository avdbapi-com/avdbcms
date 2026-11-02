<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: one "This is a demo" window (App\Support\ViewOnlyDemoWindow) for every refusal of a view-only demo
 * account, on every page it opens: a page form that was refused comes back and opens it, a script's 403 refusal carries
 * what it shows (also a script that asks for the page's HTML, like the admin's list row actions), a closed page opened
 * by a link opens it after the redirect, and a page a middleware made carries it too. Only such an account's pages carry
 * it: nothing changes for other accounts and visitors.
 *
 * The names of the answer's headers, the session value and the attributes are written out here (not taken from the
 * code's constants): they are what the pages' scripts read, so a change of them is a change of behaviour.
 */
class ViewOnlyDemoWindowTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'This is a demo';

    private const CHANGE = 'You can look around everything, but nothing you do here is saved.';

    private const CLOSED = 'This part is not open in the demo.';

    private const REFUSAL_HEADER = 'X-View-Only-Demo-Refusal';

    private const FLASH = 'view_only_demo.window';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
    }

    public function test_a_refused_admin_form_comes_back_and_opens_the_window(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());

        $this->actingAs($demo)->from('/admin')->post(route('admin.home.dismiss'), ['notice' => 'home-checklist'])
            ->assertStatus(303)->assertRedirect('/admin')
            ->assertHeader(self::REFUSAL_HEADER, 'change')
            ->assertSessionHas(self::FLASH, 'change');

        $window = $this->window((string) $this->actingAs($demo)->get('/admin')->assertOk()->getContent());

        $this->assertSame('change', $window->getAttribute('data-open'));
        $this->assertStringContainsString('ad-dialog', $window->getAttribute('class'), 'the admin dialog look in the new admin');
        $this->assertSame(self::CHANGE, $this->text($window, '//*[@id="avd-demo-window-body"]'));

        // The next page of the account: the window is there, closed (a refusal opens it once).
        $window = $this->window((string) $this->actingAs($demo)->get('/admin')->assertOk()->getContent());
        $this->assertFalse($window->hasAttribute('data-open'));
    }

    public function test_a_refused_account_settings_form_comes_back_and_opens_the_window_in_the_member_kit_look(): void
    {
        $demo = $this->viewOnly(User::factory()->create());

        $this->actingAs($demo)->from('/profile/edit')->put(route('user.update'), ['username' => 'changed-name', 'section' => 'profile'])
            ->assertStatus(303)
            ->assertSessionHas(self::FLASH, 'change');
        $this->assertNotSame('changed-name', $demo->fresh()->username);

        $html = (string) $this->actingAs($demo)->get('/profile/edit')->assertOk()->getContent();
        $window = $this->window($html);

        $this->assertSame('change', $window->getAttribute('data-open'));
        $this->assertStringContainsString('is-kit', $window->getAttribute('class'));
        $this->assertNotNull($this->xpath($html)->query('//dialog[@id="avd-demo-window"]//*[contains(@class,"mk-dialog")]//*[contains(@class,"modal-content")]')->item(0),
            'the member kit\'s dialog (it follows the look\'s pop-up window style)');
        // The page's own "Not saved" note is marked: it gives way to the window once it has opened.
        $this->assertNotNull($this->xpath($html)->query('//*[@data-view-only-refusal]')->item(0));
    }

    public function test_a_closed_page_opened_by_a_link_opens_the_window_after_the_redirect(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());

        $this->actingAs($demo)->from('/admin/videos')->withHeaders(['Sec-Fetch-User' => '?1', 'Sec-Fetch-Mode' => 'navigate'])->get('/admin/avdb-import')
            ->assertStatus(303)->assertRedirect('/admin/videos')
            ->assertHeader(self::REFUSAL_HEADER, 'closed')
            ->assertSessionHas(self::FLASH, 'closed');

        $window = $this->window((string) $this->actingAs($demo)->get('/admin/videos')->assertOk()->getContent());
        $this->assertSame('closed', $window->getAttribute('data-open'));
        $this->assertSame(self::CLOSED, $this->text($window, '//*[@id="avd-demo-window-body"]'));
        $this->assertSame(self::CLOSED, $window->getAttribute('data-closed'));
        $this->assertSame(self::CHANGE, $window->getAttribute('data-change'));
    }

    public function test_a_refused_script_gets_what_the_window_needs_in_the_admin_and_on_the_site(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());
        $video = $this->publicVideo();

        $answers = [
            $this->actingAs($demo)->putJson('/admin/settings/save', ['values' => ['site_name' => 'x']]),
            $this->actingAs($demo)->postJson(route('admin.bulk.videos'), ['action' => 'delete', 'ids' => [$video->id]]),
            $this->actingAs($demo)->postJson('/api/like', ['id' => $video->id, 'model' => 'video']),
            $this->actingAs($demo)->postJson('/api/subscribe/'.$video->user_id),
            $this->actingAs($demo)->postJson(route('custom-playlist.watch-later.toggle', $video->id)),
        ];

        foreach ($answers as $answer) {
            $answer->assertForbidden()
                ->assertHeader('X-View-Only-Demo', '1')
                ->assertHeader(self::REFUSAL_HEADER, 'change')
                ->assertJsonPath('demo_window', ['kind' => 'change', 'title' => self::TITLE, 'body' => self::CHANGE, 'button' => 'Got it']);
        }

        $this->assertNotNull($video->fresh());
    }

    /**
     * The admin's list row actions (Make public, Ban, Delete ...) post with "Accept: text/html" and X-Requested-With and
     * read the page they are sent to; a fetch() says it is no page view (Sec-Fetch-Mode). Such a script gets the 403
     * JSON refusal (which opens the window), not the redirect a page gets: a redirect would be followed out of sight and
     * use up the window's one-time value, and the script would show its own red message. A page view (a form, a link)
     * and a prefetch still go back with the refusal.
     */
    public function test_a_script_that_asks_for_the_page_gets_the_refusal_a_script_gets(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());
        $owner = User::factory()->admin()->create();
        $video = $this->publicVideo();
        $form = ['action' => 'make_private', 'ids' => [$video->id]];

        $scripts = [
            'row action (X-Requested-With)' => ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest'],
            'fetch() of the page (Sec-Fetch-Mode: cors)' => ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'],
            'fetch() same-origin' => ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'same-origin'],
        ];

        foreach ($scripts as $label => $headers) {
            $answer = $this->asAccount($demo, $headers + ['Referer' => url('/admin/videos')])->post(route('admin.bulk.videos'), $form);
            $answer->assertForbidden()
                ->assertHeader('X-View-Only-Demo', '1')
                ->assertHeader(self::REFUSAL_HEADER, 'change')
                ->assertJsonPath('demo_window.kind', 'change')
                ->assertJsonPath('demo_window.body', self::CHANGE)
                ->assertJsonPath('ok', false)
                ->assertSessionMissing(self::FLASH);
            $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status, $label.': nothing changed');
        }

        // The row action's delete (DELETE through _method), and a closed page a script opens: the same.
        $this->asAccount($demo, $scripts['row action (X-Requested-With)'])->post(route('admin.videos.delete', $video), ['_method' => 'DELETE'])
            ->assertForbidden()->assertHeader(self::REFUSAL_HEADER, 'change');
        $this->assertNotNull($video->fresh());
        $this->asAccount($demo, $scripts['fetch() of the page (Sec-Fetch-Mode: cors)'])->get('/admin/avdb-import')
            ->assertForbidden()->assertHeader(self::REFUSAL_HEADER, 'closed')->assertJsonPath('demo_window.kind', 'closed');

        // A page view (a form): back with the refusal and the window's one-time value, as before.
        $this->asAccount($demo, ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-User' => '?1', 'Referer' => url('/admin/videos')])
            ->post(route('admin.bulk.videos'), $form)
            ->assertStatus(303)->assertRedirect('/admin/videos')->assertSessionHas(self::FLASH, 'change');

        // A browser that sends no Sec-Fetch headers, without X-Requested-With: a page view too.
        $this->asAccount($demo, ['Accept' => 'text/html', 'Referer' => url('/admin/videos')])->post(route('admin.bulk.videos'), $form)
            ->assertStatus(303)->assertSessionHas(self::FLASH, 'change');

        // A prefetch of a closed page (the admin's menu prefetches on hover) is answered like the page view it prepares.
        $this->asAccount($demo, ['Sec-Purpose' => 'prefetch', 'Sec-Fetch-Mode' => 'navigate', 'Referer' => url('/admin/videos')])->get('/admin/avdb-import')
            ->assertStatus(303)->assertHeader(self::REFUSAL_HEADER, 'closed');

        // A normal administrator's row action runs.
        $this->asAccount($owner, $scripts['row action (X-Requested-With)'] + ['Referer' => url('/admin/videos')])->post(route('admin.bulk.videos'), $form)
            ->assertHeaderMissing('X-View-Only-Demo')->assertHeaderMissing(self::REFUSAL_HEADER);
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
    }

    /**
     * A page a middleware answers with (response()->view() there: "Developer mode is off" of the developer pages, the
     * admin's address lock) has no content type until it is sent: it is a page all the same, with the note, the window
     * and the masking.
     */
    public function test_a_page_a_middleware_makes_carries_the_note_the_window_and_the_masking(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());
        AdminPreferences::set($demo, 'developer_mode', false);
        $demo->refresh();

        $html = (string) $this->asAccount($demo)->get('/admin/api-tokens')->assertForbidden()->getContent();
        $this->assertStringContainsString('Turn on Developer mode', $html, 'the "Developer mode is off" page');
        $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'), 'the note');
        $window = $this->window($html);
        $this->assertFalse($window->hasAttribute('data-open'));
        $this->assertStringContainsString('ad-dialog', $window->getAttribute('class'));

        // Its one action, "Turn on Developer mode", is refused: the page comes back and opens the window.
        $this->asAccount($demo, ['Sec-Fetch-User' => '?1', 'Sec-Fetch-Mode' => 'navigate', 'Referer' => url('/admin/api-tokens')])
            ->post(route('admin.shell.preferences'), ['key' => 'developer_mode', 'value' => '1'])
            ->assertStatus(303)->assertRedirect('/admin/api-tokens')->assertSessionHas(self::FLASH, 'change');
        $window = $this->window((string) $this->asAccount($demo)->get('/admin/api-tokens')->assertForbidden()->getContent());
        $this->assertSame('change', $window->getAttribute('data-open'));
        $this->assertFalse(AdminPreferences::developerMode($demo->fresh()), 'nothing changed');

        // The admin's address lock (IsAdmin) shows the visitor's address on its page: masked for a view-only account.
        config(['site.admin_ip_whitelist' => '203.0.113.10']);
        $answer = $this->asAccount($demo)->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/admin/users')->assertForbidden();
        $html = (string) $answer->getContent();
        $this->assertStringContainsString('This address cannot open the admin', $html);
        $this->assertStringContainsString('text/html', (string) $answer->headers->get('Content-Type'));
        $this->assertStringNotContainsString('198.51.100.23', $html, 'masked');
        $this->assertStringContainsString('198.51.x.x', $html);
        $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'));
        $this->window($html);

        // Another account gets that page untouched.
        $owner = User::factory()->admin()->create();
        $html = (string) $this->asAccount($owner)->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/admin/users')->assertForbidden()->getContent();
        $this->assertStringContainsString('198.51.100.23', $html);
        $this->assertStringNotContainsString('avd-demo-window', $html);
    }

    public function test_the_window_is_one_accessible_dialog_on_every_page_of_a_view_only_account(): void
    {
        $admin = $this->viewOnly(User::factory()->admin()->create());
        $member = $this->viewOnly(User::factory()->create());
        $video = $this->publicVideo();

        $pages = [
            [$admin, '/admin', 'ad-dialog'],
            [$admin, '/admin/settings/site', 'ad-dialog'],
            [$admin, '/', 'is-kit'],
            [$member, '/', 'is-kit'],
            [$member, $video->route, 'is-kit'],
            [$member, '/profile/edit', 'is-kit'],
            [$member, route('user.videos.index', [], false), 'is-kit'],
            [$member, '/premium', 'is-kit'],
        ];

        foreach ($pages as [$user, $url, $look]) {
            $html = (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="avd-demo-window"'), "{$url}: one window");
            $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'), "{$url}: the note stays");

            $window = $this->window($html);
            $xpath = new DOMXPath($window->ownerDocument);
            $this->assertStringContainsString($look, $window->getAttribute('class'), "{$url}: {$look}");
            $this->assertSame('dialog', $window->getAttribute('role'));
            $this->assertSame('true', $window->getAttribute('aria-modal'));
            $this->assertFalse($window->hasAttribute('open'), "{$url}: closed until a refusal");
            $this->assertSame(self::TITLE, $this->text($window, '//*[@id="'.$window->getAttribute('aria-labelledby').'"]'));
            $this->assertSame(self::CHANGE, $this->text($window, '//*[@id="'.$window->getAttribute('aria-describedby').'"]'));
            $buttons = $xpath->query('.//button', $window);
            $this->assertSame(1, $buttons->length, "{$url}: one button");
            $this->assertSame('Got it', trim($buttons->item(0)->textContent));
            $this->assertSame('button', $buttons->item(0)->getAttribute('type'));
            // the window's own script comes with it, and marks the page as a view-only account's
            $this->assertStringContainsString("getElementById('avd-demo-window')", $html);
            $this->assertStringContainsString("setAttribute('data-view-only-demo', '')", $html);
        }
    }

    public function test_the_window_speaks_the_account_language(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());

        // The site's Arabic texts plus the window's three (this copy's lang/ar.json is not given the new texts: they
        // arrive with the release's translations).
        $translator = app('translator');
        $translator->load('*', '*', 'ar');
        $lines = \Closure::bind(fn (): array => $this->loaded['*']['*']['ar'] ?? [], $translator, $translator::class)();
        $translator->setLoaded(['*' => ['*' => ['ar' => array_merge($lines, [
            self::TITLE => 'هذا عرض تجريبي',
            self::CHANGE => 'يمكنك تصفّح كل شيء، لكن لا يُحفظ أي شيء تفعله هنا.',
            self::CLOSED => 'هذا الجزء غير متاح في العرض التجريبي.',
        ])]]]);

        // The page (its language from the session, where the member's choice is kept) ...
        $html = (string) $this->asAccount($demo)->withSession(['app_locale' => 'ar'])->get('/admin/users')->assertOk()->getContent();
        $window = $this->window($html);
        $this->assertSame('هذا عرض تجريبي', $this->text($window, '//*[@id="avd-demo-window-title"]'));
        $this->assertSame('يمكنك تصفّح كل شيء، لكن لا يُحفظ أي شيء تفعله هنا.', $this->text($window, '//*[@id="avd-demo-window-body"]'));
        $this->assertSame('هذا الجزء غير متاح في العرض التجريبي.', $window->getAttribute('data-closed'));
        $this->assertMatchesRegularExpression('/<html[^>]*\sdir="rtl"/', $html);

        // ... and a script's refusal.
        $this->asAccount($demo, ['Accept' => 'application/json'])->withSession(['app_locale' => 'ar'])->get('/admin/avdb-import/stats')
            ->assertForbidden()
            ->assertJsonPath('demo_window', ['kind' => 'closed', 'title' => 'هذا عرض تجريبي', 'body' => 'هذا الجزء غير متاح في العرض التجريبي.', 'button' => 'حسنًا']);
    }

    public function test_nothing_changes_for_other_accounts_and_visitors(): void
    {
        $owner = User::factory()->admin()->create();
        $member = User::factory()->create();
        $video = $this->publicVideo();

        foreach ([[$owner, '/admin'], [$owner, '/admin/videos'], [$owner, '/'], [$member, '/'], [$member, '/profile/edit'], [$member, $video->route]] as [$user, $url]) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $html = (string) $response->getContent();
            $response->assertHeaderMissing('X-View-Only-Demo');
            $this->assertStringNotContainsString('avd-demo-window', $html, "{$url}: no window for an account that is not view-only");
            $this->assertStringNotContainsString('data-view-only-demo', $html);
            $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, $html);
        }

        $this->app['auth']->forgetGuards();
        foreach (['/', $video->route, '/login', '/premium'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('avd-demo-window', $html, "{$url}: no window for a visitor");
        }

        // A refused member of a normal account does not exist: their changes work, no refusal header.
        $this->actingAs($member)->postJson('/api/like', ['id' => $video->id, 'model' => 'video'])
            ->assertHeaderMissing(self::REFUSAL_HEADER);
    }

    public function test_a_page_shown_in_a_frame_carries_no_window(): void
    {
        $demo = $this->viewOnly(User::factory()->admin()->create());

        // The page itself carries it ...
        $this->window((string) $this->actingAs($demo)->get('/admin/users')->assertOk()->getContent());

        // ... the same page in a frame does not.
        $html = (string) $this->actingAs($demo)->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get('/admin/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('avd-demo-window', $html);
    }

    // ------------------------------------------------------------------ helpers

    /** The account, with only these headers on the next requests (withHeaders() alone keeps the earlier ones). */
    private function asAccount(User $user, array $headers = []): static
    {
        $this->flushHeaders();
        $this->serverVariables = [];

        return $this->actingAs($user)->withHeaders($headers);
    }

    private function viewOnly(User $user): User
    {
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }

    private function publicVideo(): Video
    {
        $category = Category::query()->create(['title' => 'Demo', 'slug' => 'demo-'.uniqid(), 'icon' => 'film', 'in_menu' => true]);

        return Video::factory()
            ->for($category)
            ->for(User::factory()->create(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'views' => 0]);
    }

    private function window(string $html): DOMElement
    {
        $window = $this->xpath($html)->query('//dialog[@id="avd-demo-window"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $window, 'the page carries the window');

        return $window;
    }

    private function text(DOMElement $context, string $query): string
    {
        $node = (new DOMXPath($context->ownerDocument))->query($query)->item(0);

        return trim((string) $node?->textContent);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
