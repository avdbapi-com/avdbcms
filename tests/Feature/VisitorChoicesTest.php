<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Video;
use App\Providers\AppServiceProvider;
use App\Services\SeoService;
use App\Support\AdminPreferences;
use App\Support\AppearanceSettings;
use App\Support\MasterExperienceCatalog;
use App\Support\SiteSettingsSource;
use App\Support\VisitorChoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * A demo site's visitor buttons (1.2.3, the owner's request of 29 Oct; App\Support\VisitorChoices): "Let visitors try
 * the looks" (Appearance > Themes) and "Demo site: ads are off until a visitor turns them on" (Money > Ads), both off by
 * default.
 *
 *  - Off: no markup, CSS or script, the cookies are never read (a valid one changes nothing, an invalid one is not
 *    removed) and the pages keep their cache headers.
 *  - The look picker: a round palette button and a "Try a look" panel (the site's look, then the 12 looks in dark and
 *    light); a valid cookie renders that look for this browser only, like a ?theme_preset= preview; anything else is
 *    ignored and removed; a preview link shows the preview; the admin, the API, embed pages and mails never use it.
 *  - The demo ads switch: every ad (banners, the phone strip, the pop-under, window.AD_CONFIG with the pre-roll and the
 *    list ads, the watch page's side ad, the channel page's card ads) is left out until the visitor turns ads on; the
 *    "Demo · Ads off" pill turns them on and off; a Premium member is told they see no ads anyway.
 *  - Every page the public layout draws follows the choices, whatever its address or method: the 404 page of an /admin,
 *    /api or /install address, an error page under /profile, the answer to a form (without the controls there).
 *  - The footer keeps the dock's room at the page's end, in its own colours.
 *  - The "Try a look" panel keeps its title, close button, hint and "The site's look" in view (only its list of looks
 *    scrolls), and its script leaves no name on the page's window. It opens above or beside the dock only where its
 *    list keeps two rows of looks (else as the sheet over the screen's height), and below the header where it is and
 *    as tall as it is; it opens with the current choice brought into view the nearest way.
 *  - The dock is one level under the header (the header, its menus and its search are drawn over it); it rises over the
 *    header while the panel is open, while a control holds the keyboard focus (never obscured) and while a control lies
 *    over the header's bare box (never cut by it), but never over the header's own popups (its phone search, the
 *    search's suggestions, a menu). Where one of the header's controls or the view-only note lies under a control, the
 *    round button turns see-through and the pill moves to the corner beside it while that corner is free, so it stays
 *    visible; a pill nobody can see never takes a tap; the dock's own box takes no clicks; the keyboard safeguard and
 *    the player rule measure every control, a moved pill too, and the safeguard follows the focused element through
 *    every scroll (an observer, no scroll handler).
 *  - On phones the view-only demo account's note is a banner at the top of the site's own pages (no longer at the
 *    bottom, under "Back to top" and the bottom bar or over the player), below a see-through header at that header's
 *    real height; on wider screens it never meets "Back to top" (it sits above the button where a long language's note
 *    would reach it).
 *  - While either is on, the pages are private (never kept by a CDN for another visitor).
 */
class VisitorChoicesTest extends TestCase
{
    use RefreshDatabase;

    /** A look other than the site's, its theme and colour style (MasterExperienceCatalog). */
    private const LOOK = 'netflix_cinema';

    private const LOOK_THEME = 'pure_oled_black';

    /**
     * The view-only note's "Back to top" part (ViewOnlyDemo::note(), DW-VO-02): on wider screens, where the note is fixed
     * at the bottom centre, it sits 12px above "Back to top" wherever it would come within 8px of the button (beside it
     * or over it: a long language's note on a tablet), measured from the button's own layout box (its used left and top,
     * which a scrollbar gutter can set apart from the page's clientWidth); once the button is in
     * the page (a child of the body), again on resize and when the button changes size; a change is told to the demo
     * site's buttons (avd-vo-moved).
     */
    private const NOTE_LIFT = 'lift=function(){var s=w.getComputedStyle(n),k=t.offsetHeight,v="",r,c,x,y;'
        .'if(s.position==="fixed"&&s.display!=="none"&&k){r=n.getBoundingClientRect();c=w.getComputedStyle(t);x=parseFloat(c.left);y=parseFloat(c.top);'
        .'if(r.right>x-8&&r.left<x+t.offsetWidth+8&&(n.style.bottom||r.bottom>y-8&&r.top<y+k+8))v=Math.ceil(parseFloat(c.bottom)+k+12)+"px"}'
        .'if(n.style.bottom!==v){n.style.bottom=v;if(w.CustomEvent)n.dispatchEvent(new w.CustomEvent("avd-vo-moved"))}};'
        .'find=function(){t=d.getElementById("back-to-top");if(!t)return false;lift();w.addEventListener("resize",lift);if(w.ResizeObserver)new w.ResizeObserver(lift).observe(t);return true};'
        .'if(!find()&&w.MutationObserver){mt=new w.MutationObserver(function(){if(find())mt.disconnect()});mt.observe(b,{childList:true});'
        .'d.addEventListener("DOMContentLoaded",function(){mt.disconnect();if(!t)find()})}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Setting::set('appearance_schema_version', '2', 'appearance');
        config([
            'site.visitor_look_picker_enabled' => false,
            'site.demo_ads_switch_enabled' => false,
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- both off

    public function test_with_both_settings_off_the_page_has_none_of_the_markup_css_or_script(): void
    {
        $this->ads();
        $this->formAnswerRoute();

        foreach ([['GET', '/', 200], ['GET', '/terms', 200], ['GET', '/search?q=clip', 200], ['GET', '/admin', 404], ['GET', '/profile/no-such-page', 404], ['POST', '/dw-form-answer', 419]] as [$method, $url, $status]) {
            $response = $this->call($method, $url);
            $response->assertStatus($status);
            $html = (string) $response->getContent();

            foreach (['id="avdTry"', 'avd-try', 'avdTryTpl', 'avd_try_look', 'avd_demo_ads', 'Try another look', 'Turn ads on', '--avd-try-room'] as $part) {
                $this->assertStringNotContainsString($part, $html, "{$method} {$url}: {$part}");
            }

            // The ads as the site has them (nothing of the demo switch).
            $this->assertMatchesRegularExpression('/window\.AD_CONFIG = \{\s*enabled: true,\s*prerollEnabled: true,/', $html, "{$method} {$url}");
        }

        $this->assertNull(VisitorChoices::dock(Request::create('/'), '', true));
    }

    public function test_with_both_settings_off_the_cookies_are_never_read(): void
    {
        $this->ads();
        $site = $this->siteTheme();

        // A valid look and "ads off" by the demo cookie's absence: nothing changes, the ads show as the site has them.
        $valid = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.light'])->get('/terms')->assertOk();
        $this->assertSame($site, $this->htmlAttribute((string) $valid->getContent(), 'data-avd-theme'));
        $this->assertStringContainsString('dw-ad-header', (string) $valid->getContent());
        $this->assertNull($valid->baseRequest->attributes->get(VisitorChoices::LOOK_ATTRIBUTE));

        // Invalid values are not even looked at: no Set-Cookie removes them.
        $invalid = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => 'no.such', VisitorChoices::ADS_COOKIE => 'x'])->get('/terms')->assertOk();
        $this->assertNull($this->cookie($invalid, VisitorChoices::LOOK_COOKIE));
        $this->assertNull($this->cookie($invalid, VisitorChoices::ADS_COOKIE));
    }

    public function test_with_both_settings_off_the_pages_keep_their_edge_cache_headers(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage=', (string) $response->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------------------------------------------- the look picker

    public function test_the_look_picker_prints_the_palette_button_and_the_panel_with_the_twelve_looks(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);

        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString('<style id="avd-try-css">', $html, 'the CSS is inline, in the head');
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, '<style id="avd-try-css">'));
        $this->assertMatchesRegularExpression('/<button type="button" class="avd-try__btn" id="avdTryLook" aria-haspopup="dialog" aria-expanded="false" aria-label="Try another look" title="Try another look">/', $html);
        $this->assertStringContainsString('<template id="avdTryTpl">', $html, 'the panel is a template until it opens');
        $this->assertMatchesRegularExpression('/role="dialog" aria-modal="false" aria-labelledby="avdTryTitle"/', $html);
        $this->assertStringContainsString('<h2 class="avd-try__title" id="avdTryTitle">Try a look</h2>', $html);
        $this->assertStringContainsString(e("The site's look"), $html);
        $this->assertStringNotContainsString('id="avdTryAds"', $html, 'no ads pill while the demo ads switch is off');

        // The site's look first (the current choice without a cookie), then each look in dark and light, by name, with
        // a preview of its own colours.
        $this->assertMatchesRegularExpression('/class="avd-try__site" data-look="" data-ctx="" aria-pressed="true"/', $html);
        $looks = MasterExperienceCatalog::all();
        $this->assertCount(12, $looks);
        $this->assertSame(24, preg_match_all('/<button type="button" class="avd-try__mode" /', $html));

        foreach ($looks as $id => $look) {
            $this->assertStringContainsString('>'.e($look['label']).'</span>', $html, $id);

            foreach (['dark', 'light'] as $mode) {
                $this->assertMatchesRegularExpression('/data-look="'.$id.'" data-mode="'.$mode.'" data-ctx="'.$look['apply_settings']['theme_preset'].':'.$mode.'" aria-pressed="false" aria-labelledby="avdTryN\d+ avdTryM\d+'.$mode.'" style="--m:#[0-9a-f]{6}"/', $html, "{$id} {$mode}");
            }
        }

        $this->assertSame(1, preg_match_all('/<button type="button" class="avd-try__site" [^>]*style="--a:#[0-9a-f]{6};--b:#[0-9a-f]{6};--c:#[0-9a-f]{6}"><span class="avd-try__sw"/', $html), 'the site\'s look has its own colours');
        $this->assertSame(12, preg_match_all('/<li class="avd-try__row" style="--a:#[0-9a-f]{6};--b:#[0-9a-f]{6};--c:#[0-9a-f]{6}">/', $html));
        // The script: never in a frame, cookies with the site's path, SameSite=Lax and Secure on https.
        $this->assertStringContainsString("try{alone=w.self===w.top}catch(e){alone=false}", $html);
        $this->assertStringContainsString("';path='+path+';max-age='+a+';SameSite=Lax'+sec", $html);
        $this->assertStringContainsString('data-path="/"', $html);
    }

    public function test_a_chosen_look_renders_that_look_for_this_browser_only(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);
        $stored = Setting::getGroup('appearance');
        $site = $this->siteTheme();
        $this->assertNotSame(self::LOOK_THEME, $site, 'the test needs a look that is not the site\'s');

        $tried = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark'])->get('/')->assertOk()->getContent();

        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($tried, 'data-avd-theme'));
        $this->assertSame('dark', $this->htmlAttribute($tried, 'data-bs-theme'));
        $this->assertSame('cinema_carousel', $this->htmlAttribute($tried, 'data-avd-home-layout'));
        $this->assertMatchesRegularExpression('/data-look="'.self::LOOK.'" data-mode="dark" [^>]*aria-pressed="true"/', $tried, 'the current one is marked');
        $this->assertMatchesRegularExpression('/class="avd-try__site" data-look="" data-ctx="" aria-pressed="false"/', $tried);
        $this->assertStringContainsString('data-look-now="'.self::LOOK.'.dark"', $tried);

        // Another visitor (no cookie) and the settings: unchanged.
        $other = (string) $this->get('/')->assertOk()->getContent();
        $this->assertSame($site, $this->htmlAttribute($other, 'data-avd-theme'));
        $this->assertSame($stored, Setting::getGroup('appearance'));
    }

    public function test_every_look_renders_in_dark_and_light_like_its_theme_preview(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);

        foreach (MasterExperienceCatalog::all() as $id => $look) {
            $theme = (string) $look['apply_settings']['theme_preset'];
            $flavor = (string) $look['apply_settings']['theme_flavor'];

            foreach (['dark', 'light'] as $mode) {
                $tried = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => $id.'.'.$mode])->get('/terms')->assertOk()->getContent();
                $preview = (string) $this->get('/terms?'.http_build_query(['theme_preset' => $theme, 'theme_flavor' => $flavor, 'theme_mode' => $mode]))->assertOk()->getContent();

                $this->assertSame($theme, $this->htmlAttribute($tried, 'data-avd-theme'), "{$id} {$mode}: its theme");
                $this->assertSame($mode, $this->htmlAttribute($tried, 'data-bs-theme'), "{$id} {$mode}: the mode");
                $this->assertSame($mode, $this->htmlAttribute($tried, 'data-avd-theme-default'), "{$id} {$mode}: the mode the visitor's switch starts from");

                // What the theme itself decides is the preview's (the look may differ in a few layout choices of its own).
                foreach (['data-avd-theme', 'data-avd-flavor', 'data-bs-theme', 'data-avd-theme-family', 'data-avd-header-deco', 'data-avd-hero-deco', 'data-avd-card-frame', 'data-avd-chip-style', 'data-avd-section-style', 'data-avd-avatar-shape', 'data-avd-backdrop'] as $attribute) {
                    $this->assertSame($this->htmlAttribute($preview, $attribute), $this->htmlAttribute($tried, $attribute), "{$id} {$mode}: {$attribute} as the ?theme_preset= preview");
                }

                $this->assertMatchesRegularExpression('/data-look="'.$id.'" data-mode="'.$mode.'" [^>]*aria-pressed="true"/', $tried, "{$id} {$mode}: marked");
            }
        }
    }

    public function test_an_invalid_look_cookie_is_ignored_and_removed(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);
        $site = $this->siteTheme();

        foreach (['nope.dark', self::LOOK, self::LOOK.".dark\n", self::LOOK.'.sepia', 'default_factory.dark', strtoupper(self::LOOK).'.dark', '../'.self::LOOK.'.dark', self::LOOK.'.dark;x', str_repeat('a', 90).'.dark', '<b>.dark', ''] as $value) {
            $response = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => $value])->get('/terms')->assertOk();
            $html = (string) $response->getContent();

            $this->assertSame($site, $this->htmlAttribute($html, 'data-avd-theme'), "{$value}: the site's look");
            $this->assertMatchesRegularExpression('/class="avd-try__site" data-look="" data-ctx="" aria-pressed="true"/', $html, $value);
            $this->assertStringContainsString('data-look-now=""', $html, "{$value}: nothing of it is printed");

            $cookie = $this->cookie($response, VisitorChoices::LOOK_COOKIE);
            $this->assertNotNull($cookie, "{$value}: removed");
            $this->assertTrue($cookie->isCleared(), "{$value}: removed");
            $this->assertSame('/', $cookie->getPath());
            $this->assertSame('lax', $cookie->getSameSite());
        }

        $this->assertNull(VisitorChoices::parseLook(['look' => self::LOOK]));
        $this->assertSame(['look' => self::LOOK, 'mode' => 'light'], VisitorChoices::parseLook(self::LOOK.'.light'));

        // A cookie sent as an array (avd_try_look[x]=...) is no choice either, and no error page.
        config(['site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => ['x' => self::LOOK.'.dark'], VisitorChoices::ADS_COOKIE => ['1']])
            ->get('/terms')->assertOk()->getContent();
        $this->assertSame($site, $this->htmlAttribute($html, 'data-avd-theme'));
    }

    public function test_a_preview_link_shows_the_preview_and_not_the_tried_look(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);

        $html = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark'])
            ->get('/terms?theme_preset=broadsheet')->assertOk()->getContent();

        $this->assertSame('broadsheet', $this->htmlAttribute($html, 'data-avd-theme'));
    }

    public function test_the_404_page_shows_the_tried_look_too(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);

        $response = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.light'])->get('/dw-no-such-page-at-all');

        $response->assertNotFound();
        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute((string) $response->getContent(), 'data-avd-theme'));
        $this->assertSame('light', $this->htmlAttribute((string) $response->getContent(), 'data-bs-theme'));
        $this->assertStringContainsString('id="avdTryLook"', (string) $response->getContent());
    }

    public function test_the_404_page_of_an_admin_api_or_install_address_follows_the_choices_like_every_page(): void
    {
        // What a guest, a member, a Premium member or the view-only demo account gets for /admin (and any unknown
        // address under /admin, /api or /install) is the site's 404 page in the public layout: a page of the public site.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $member = User::factory()->create();
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $premium = User::factory()->create();
        Subscription::factory()->for($premium)->for(Plan::factory())->create(['ends_at' => null]);

        foreach (['guest' => null, 'member' => $member, 'view-only' => $demo, 'premium' => $premium] as $who => $user) {
            foreach (['/admin', '/admin/appearance', '/admin/no-such-page', '/api/no-such-thing', '/install/no-such-step'] as $url) {
                $where = "{$who} {$url}";
                $as = fn () => $user === null ? $this : $this->actingAs($user->fresh());

                $off = $as()->get($url)->assertNotFound();
                $html = (string) $off->getContent();
                $this->assertNoAds($html, $where);
                $this->assertStringContainsString('data-state="'.($who === 'premium' ? 'premium' : 'off').'"', $html, "{$where}: the pill says what the page shows");
                $this->assertStringContainsString('id="avdTryLook"', $html, $where);
                $this->assertStringContainsString('<style id="avd-try-css">', $html, $where);

                if ($who !== 'premium') {
                    $this->assertAds((string) $as()->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => '1'])->get($url)->assertNotFound()->getContent(), "{$where} with ads turned on");
                }

                $tried = (string) $as()->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.light'])->get($url)->assertNotFound()->getContent();
                $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($tried, 'data-avd-theme'), "{$where}: the tried look");
                $this->assertSame('light', $this->htmlAttribute($tried, 'data-bs-theme'), $where);
            }
        }

        // An invalid cookie is removed there too.
        $response = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => 'no.such', VisitorChoices::ADS_COOKIE => 'x'])->get('/admin')->assertNotFound();
        $this->assertTrue($this->cookie($response, VisitorChoices::LOOK_COOKIE)?->isCleared() ?? false);
        $this->assertTrue($this->cookie($response, VisitorChoices::ADS_COOKIE)?->isCleared() ?? false);
    }

    public function test_an_error_page_of_the_member_back_office_leaves_the_ads_out(): void
    {
        // The studio and Account settings print no ad and keep the site's ad options in their forms (their own layout);
        // an error page under /profile is the public layout's and leaves the ads out like any page (the pill said "Ads
        // off" over every ad).
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true]);
        $member = User::factory()->create();

        foreach (['/profile/no-such-page', '/profile/videos/987654321/edit'] as $url) {
            $html = (string) $this->actingAs($member)->get($url)->assertNotFound()->getContent();
            $this->assertNoAds($html, $url);
            $this->assertStringContainsString('data-state="off"', $html, $url);

            $this->assertAds((string) $this->actingAs($member)->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => '1'])->get($url)->assertNotFound()->getContent(), "{$url} with ads turned on");
        }
    }

    public function test_the_answer_to_a_form_follows_the_choices_without_the_controls(): void
    {
        // A page the public layout draws for a POST ("This page timed out" when a form's token expired): the visitor's
        // choices count (no ad unless they turned ads on, the tried look), but no control: they reload the page, which
        // would send the form again.
        $this->ads();
        $this->formAnswerRoute();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);

        $response = $this->post('/dw-form-answer')->assertStatus(419);
        $html = (string) $response->getContent();
        $this->assertStringContainsString('This page timed out', $html);
        $this->assertNoAds($html, 'POST');

        foreach (['id="avdTry"', 'avd-try-css', 'avdTryTpl', '--avd-try-room'] as $part) {
            $this->assertStringNotContainsString($part, $html, "POST: {$part}");
        }

        $this->assertAds((string) $this->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => '1'])->post('/dw-form-answer')->assertStatus(419)->getContent(), 'POST with ads turned on');

        $tried = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark'])->post('/dw-form-answer')->assertStatus(419)->getContent();
        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($tried, 'data-avd-theme'));

        $bad = $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => 'no.such', VisitorChoices::ADS_COOKIE => '0'])->post('/dw-form-answer')->assertStatus(419);
        $this->assertTrue($this->cookie($bad, VisitorChoices::LOOK_COOKIE)?->isCleared() ?? false);
        $this->assertTrue($this->cookie($bad, VisitorChoices::ADS_COOKIE)?->isCleared() ?? false);
        $this->assertNull(VisitorChoices::dock(Request::create('/terms', 'POST'), '', true));
    }

    public function test_a_page_rendered_without_the_middleware_applies_the_choices_in_the_layout(): void
    {
        // A page the web middleware did not prepare (the maintenance page of the global middleware): the layout applies
        // this browser's choices itself, once.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);

        $html = (string) $this->withoutMiddleware(\App\Http\Middleware\ApplyVisitorChoices::class)
            ->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark'])
            ->get('/terms')->assertOk()->getContent();

        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($html, 'data-avd-theme'));
        $this->assertNoAds($html, 'without the middleware');

        $request = Request::create('/terms', 'GET', [], [VisitorChoices::LOOK_COOKIE => 'bad']);
        $this->assertSame([VisitorChoices::LOOK_COOKIE], VisitorChoices::applyFor($request));
        $this->assertSame([], VisitorChoices::applyFor($request), 'once per request');
    }

    public function test_a_member_channel_keeps_its_own_accent_inside_a_tried_look(): void
    {
        config(['site.visitor_look_picker_enabled' => true, 'site.channel_customization_enabled' => true]);
        $channel = User::factory()->create(['channel_customization' => ['accent' => 'custom', 'custom_accent' => '#fff200']]);

        $plain = (string) $this->get($channel->route)->assertOk()->getContent();
        $tried = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark'])->get($channel->route)->assertOk()->getContent();

        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($tried, 'data-avd-theme'));
        $this->assertSame(1, preg_match('/data-channel-accent="custom"\s+style="([^"]+)"/', $plain, $own));
        $this->assertStringContainsString('data-channel-accent="custom"', $tried);
        $this->assertStringContainsString($own[1], $tried, 'the creator\'s own accent is printed as in the site\'s look');
    }

    public function test_the_admin_the_api_and_embed_pages_never_use_the_choices(): void
    {
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true, 'admin.ui' => 'v2']);
        $cookies = [VisitorChoices::LOOK_COOKIE => self::LOOK.'.light', VisitorChoices::ADS_COOKIE => '1'];

        // The API (a guest here: the player's answer) and an embed page.
        $video = $this->video();
        $player = fn (array $with) => $this->withCredentials()->withUnencryptedCookies($with)->getJson('/api/videos/'.$video->uuid.'/player')->assertOk()->json();
        $this->assertSame($player([]), $player($cookies), 'an API answer is the same with or without the cookies');
        $this->assertSame($player([]), $player([VisitorChoices::ADS_COOKIE => 'x', VisitorChoices::LOOK_COOKIE => 'x']));

        $embed = $this->withUnencryptedCookies($cookies)->get('/video/'.$video->uuid.'/embed');
        $this->assertStringNotContainsString('avdTry', (string) $embed->getContent());
        $this->assertNull($embed->baseRequest->attributes->get(VisitorChoices::LOOK_ATTRIBUTE));
        $this->assertNull($this->cookie($embed, VisitorChoices::LOOK_COOKIE));

        // The admin's pages.
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', false);

        foreach (['/admin', '/admin/appearance', '/admin/money/ads'] as $url) {
            $response = $this->actingAs($admin)->withUnencryptedCookies($cookies)->get($url)->assertOk();
            $html = (string) $response->getContent();
            $this->assertStringNotContainsString('id="avdTry"', $html, $url);
            $this->assertStringNotContainsString('avd-try-css', $html, $url);
            $this->assertNotSame(self::LOOK_THEME, $this->htmlAttribute($html, 'data-avd-theme'), $url);
            $this->assertNull($response->baseRequest->attributes->get(VisitorChoices::LOOK_ATTRIBUTE), $url);
            $this->assertNull($this->cookie($response, VisitorChoices::LOOK_COOKIE), $url);
        }
    }

    public function test_a_mail_sent_while_a_look_is_tried_is_made_in_the_site_look(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        $site = (string) config('site.theme_preset');

        $request = Request::create('/terms', 'GET', [], [VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark']);
        $this->app->instance('request', $request);
        VisitorChoices::applyFor($request);
        $this->assertSame(self::LOOK_THEME, config('site.theme_preset'), 'the page shows the tried look');

        // A database notification changes nothing; a mail is made in the site's look (MailTheme reads this config).
        event(new NotificationSending(User::factory()->create(), new class extends Notification {}, 'database'));
        $this->assertSame(self::LOOK_THEME, config('site.theme_preset'));
        event(new NotificationSending(User::factory()->create(), new class extends Notification {}, 'mail'));
        $this->assertSame($site, config('site.theme_preset'));
    }

    // ---------------------------------------------------------------------------------------------------- the demo ads switch

    public function test_the_demo_switch_leaves_every_ad_out_until_the_visitor_turns_ads_on(): void
    {
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true]);

        foreach (['/', '/terms', '/search?q=clip'] as $url) {
            $off = $this->get($url)->assertOk();
            $html = (string) $off->getContent();
            $this->assertNoAds($html, $url);
            $this->assertStringContainsString('<button type="button" class="avd-try__ads" id="avdTryAds" data-state="off" aria-labelledby="avdTryAdsKicker avdTryAdsState avdTryAdsDo">', $html, $url);
            $this->assertStringContainsString('<span class="avd-try__kicker" id="avdTryAdsKicker">Demo</span><span class="avd-try__state" id="avdTryAdsState">Ads off</span>', $html);
            $this->assertStringContainsString('<span class="visually-hidden" id="avdTryAdsDo">Turn ads on</span>', $html);
            $this->assertStringNotContainsString('id="avdTryLook"', $html, 'no palette button while the look picker is off');

            $on = (string) $this->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => '1'])->get($url)->assertOk()->getContent();
            $this->assertAds($on, $url);
            $this->assertStringContainsString('data-state="on"', $on);
            $this->assertStringContainsString('<span class="avd-try__state" id="avdTryAdsState">Ads on</span>', $on);
            $this->assertStringContainsString('<span class="visually-hidden" id="avdTryAdsDo">Turn ads off</span>', $on);
        }
    }

    public function test_the_watch_page_leaves_its_side_ad_and_the_pre_roll_out_by_default(): void
    {
        $this->ads();
        // The "Up next" list with its own list ad (after every 2nd video): the newest videos, so the list is known.
        config(['site.demo_ads_switch_enabled' => true, 'site.watch_related_source' => 'latest', 'site.ad_code_grid_watch_related' => '<div class="dw-ad-related">up next ad</div>']);
        $channel = User::factory()->create();
        $url = app(SeoService::class)->videoUrl($this->video($channel));

        foreach (range(1, 4) as $n) {
            $this->video($channel, ['title' => 'Dw up next '.$n, 'published_at' => now()->subHours(30 + $n)]);
        }

        $off = (string) $this->get($url)->assertOk()->getContent();
        $this->assertNoAds($off, 'watch');
        $this->assertStringNotContainsString('dw-ad-side', $off, 'the side ad next to the player');
        $this->assertStringNotContainsString('dw-ad-related', $off, 'the Up next list ad');
        $this->assertStringContainsString('Dw up next 1', $off, 'the Up next list is there');

        $on = (string) $this->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => '1'])->get($url)->assertOk()->getContent();
        $this->assertAds($on, 'watch');
        $this->assertStringContainsString('dw-ad-side', $on);
        $this->assertStringContainsString('dw-ad-related', $on, 'the Up next list ad is back with the cookie');
    }

    public function test_the_channel_page_leaves_its_card_ads_out_by_default(): void
    {
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true, 'site.channel_customization_enabled' => true]);
        $channel = User::factory()->create();

        foreach (range(1, 6) as $n) {
            $this->video($channel, ['title' => 'Dw clip '.$n, 'published_at' => now()->subHours($n)]);
        }

        $part = fn (array $cookies): string => (string) $this->withCredentials()->withUnencryptedCookies($cookies)->getJson($channel->route.'?part=videos&sort=latest')->assertOk()->json('html');
        $this->assertStringNotContainsString('dw-ad-channel', $part([]), 'the Videos tab: no list ad');
        $this->assertStringContainsString('dw-ad-channel', $part([VisitorChoices::ADS_COOKIE => '1']), 'the Videos tab: its list ad');
        $this->assertStringNotContainsString('dw-ad-channel', (string) $this->get($channel->route)->assertOk()->getContent(), 'the channel page');
    }

    public function test_an_invalid_ads_cookie_counts_as_off_and_is_removed(): void
    {
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true]);

        foreach (['0', 'on', 'true', '1 ', '2', ''] as $value) {
            $response = $this->withUnencryptedCookies([VisitorChoices::ADS_COOKIE => $value])->get('/terms')->assertOk();
            $this->assertNoAds((string) $response->getContent(), "cookie {$value}");
            $cookie = $this->cookie($response, VisitorChoices::ADS_COOKIE);
            $this->assertNotNull($cookie, "{$value}: removed");
            $this->assertTrue($cookie->isCleared());
        }
    }

    public function test_a_premium_member_is_told_they_see_no_ads(): void
    {
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true, 'site.ads_hide_for_premium' => true]);
        $premium = User::factory()->create();
        Subscription::factory()->for($premium)->for(Plan::factory())->create(['ends_at' => null]);
        $this->assertTrue($premium->fresh()->is_premium);

        foreach ([[], [VisitorChoices::ADS_COOKIE => '1']] as $cookies) {
            $html = (string) $this->actingAs($premium->fresh())->withUnencryptedCookies($cookies)->get('/terms')->assertOk()->getContent();
            $this->assertNoAds($html, 'premium');
            $this->assertStringContainsString('data-state="premium"', $html);
            $this->assertStringContainsString('<span class="visually-hidden" id="avdTryAdsDo">You see no ads: you have Premium.</span>', $html);
            $this->assertStringContainsString('data-t-premium="You see no ads: you have Premium."', $html);
        }
    }

    public function test_the_pill_is_left_out_on_a_site_that_shows_no_ads(): void
    {
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true, 'site.ads_enabled' => false]);

        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringNotContainsString('avd-try', $html, 'nothing to switch: no pill, no CSS, no script');

        config(['site.visitor_look_picker_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringContainsString('id="avdTryLook"', $html);
        $this->assertStringNotContainsString('id="avdTryAds"', $html);
    }

    public function test_both_controls_stack_and_the_view_only_demo_account_uses_them_like_any_visitor(): void
    {
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        $html = (string) $this->actingAs($demo->fresh())
            ->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => self::LOOK.'.dark', VisitorChoices::ADS_COOKIE => '1'])
            ->get('/terms')->assertOk()->getContent();

        $this->assertSame(self::LOOK_THEME, $this->htmlAttribute($html, 'data-avd-theme'));
        $this->assertAds($html, 'view-only demo account');
        // The pill above the round button, in one dock that leaves room for "Back to top".
        $this->assertMatchesRegularExpression('/<div class="avd-try avd-try--btt avd-try--both" id="avdTry" hidden/', $html);
        $this->assertLessThan(strpos($html, 'id="avdTryLook"'), strpos($html, 'id="avdTryAds"'));
        // On a phone the account's note (bottom centre) is never under the dock: the script measures where the note is and
        // the dock (and the footer's end room) rise above it where they would meet.
        $this->assertStringContainsString('var(--avd-try-note,0px)', $html);
        $this->assertStringContainsString("note=d.querySelector('.avd-view-only-note')", $html);
        $this->assertStringContainsString('class="'.ViewOnlyDemo::NOTE_CLASS.'"', $html);
    }

    public function test_the_footer_keeps_the_room_of_the_dock_at_the_end_of_the_page_in_its_own_colours(): void
    {
        // The page's last row scrolls clear of the dock: the footer's own end padding is as tall as the dock needs (the
        // dock's place above the bottom layers and "Back to top", its height, 4px), the way the site keeps room for "Back
        // to top" (.avd-footer--btt-room), so the room has the footer's colours in every look. Static CSS in the head:
        // nothing moves once the page shows. Not a block after the footer (it was a band in the page's colour).
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $room = '#site-footer,#site-footer~#ad-slot-footer{--avd-try-room:calc(max(calc(max(calc(var(--avd-bottom-chrome,0px) + 12px),calc(1rem + env(safe-area-inset-bottom,0px))) + %dpx),var(--avd-try-note,0px)) + %dpx - var(--avd-bottom-chrome,0px))}';

        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringContainsString('id="site-footer"', $html, 'the footer the room belongs to');
        $this->assertStringContainsString(sprintf($room, 56, 100), $html, 'both controls and "Back to top": 56px + 96px + 4px');
        $this->assertStringContainsString('html[data-avd-member] #site-footer,html[data-avd-member] #site-footer~#ad-slot-footer{--avd-try-room:calc(max(calc(var(--avd-bottom-chrome,0px) + 12px + env(safe-area-inset-bottom,0px) + 56px),var(--avd-try-note,0px)) + 100px - var(--avd-bottom-chrome,0px))}', $html, 'the member pages\' "Back to top" sits on its own line');
        $this->assertStringContainsString('#site-footer{padding-block-end:var(--avd-try-room)}', $html);
        $this->assertStringContainsString('html[data-mk-toast] #site-footer{padding-block-end:max(var(--avd-try-room),var(--mk-toast-room,6rem))}', $html, 'a toast\'s room as the site keeps it');
        $this->assertStringContainsString('#site-footer~#ad-slot-footer{margin-block-end:var(--avd-try-room)}', $html, 'below the footer ad, the page\'s last box');
        $this->assertStringContainsString('@media screen{', $html, 'not in print');
        $this->assertStringNotContainsString('avdTryRoom', $html);
        $this->assertStringNotContainsString('avd-try-room"', $html);

        // One control, no "Back to top": 44px + 4px, nothing for "Back to top".
        config(['site.demo_ads_switch_enabled' => false, 'site.back_to_top_enabled' => false]);
        $html = (string) $this->get('/terms?back_to_top_enabled=0')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="avd-try" id="avdTry" hidden', $html);
        $this->assertStringContainsString(sprintf($room, 0, 48), $html);
        $this->assertStringNotContainsString('html[data-avd-member] #site-footer', $html);

        // In a frame (the admin's preview monitor) the script removes the dock and this CSS with it.
        $this->assertStringContainsString("if(!alone){root.parentNode.removeChild(root);var css=d.getElementById('avd-try-css');if(css)css.parentNode.removeChild(css);return}", $html);
    }

    public function test_only_the_list_of_looks_scrolls_so_the_panel_keeps_its_head_in_view(): void
    {
        // Opened again while a look far down the list is tried (and after End or Home), the panel scrolled as a whole:
        // its title, its close button, its hint and "The site's look" left the screen on phones and landscape phones.
        // The panel is a column whose list of looks is the only part that scrolls, with room for the focus ring; the
        // focused choice is scrolled fully into view when the panel opens and when the keyboard moves (never under a
        // press, which would move the button being clicked); a side panel shorter than 200px opens as the sheet over
        // the screen's height; where even that is too short, the whole panel scrolls under its title and close button.
        // The layouts themselves are proven in three browsers (lane notes: runs/p3/reopen_<engine>_after.json).
        config(['site.visitor_look_picker_enabled' => true]);
        $html = (string) $this->withUnencryptedCookies([VisitorChoices::LOOK_COOKIE => 'daylight_creator.light'])->get('/terms')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-look="daylight_creator" data-mode="light" [^>]*aria-pressed="true"/', $html, 'the last look of the list is the current one');

        $this->assertMatchesRegularExpression('/\.avd-try__panel\{[^}]*display:flex;flex-direction:column;[^}]*overflow:auto;overscroll-behavior:contain;scroll-padding-block-start:52px;/', $html, 'a column; the whole panel scrolls only where nothing else fits, and never under its head');
        $this->assertStringContainsString('.avd-try__list{display:grid;gap:4px;flex:0 1 auto;min-block-size:4rem;overflow:auto;overscroll-behavior:contain;margin:2px -6px -6px;padding:6px;list-style:none}', $html, 'the list of looks is the part that scrolls, with 6px of room for the focus ring');
        $this->assertStringContainsString('.avd-try__list button{scroll-margin-block:6px}', $html);
        $this->assertStringContainsString('<ul class="avd-try__list" role="list" tabindex="-1">', $html, 'no Tab stop of its own (Firefox puts every scrolling box in the Tab order)');
        $this->assertStringContainsString('.avd-try__head{position:sticky;inset-block-start:0;z-index:1;', $html, 'the title and the close button stay at the top');
        // Opening it: the current choice in view the nearest way. focus() alone scrolls it too, and centres a choice the
        // list does not show (Chromium, Firefox): where the list has room for two rows of looks it opened with one and
        // two halves; so it is focused without that scroll and near() brings it in.
        $this->assertStringContainsString("f=panel.querySelector('[aria-pressed=\"true\"]')||panel.querySelector('button[data-look]');f.focus({preventScroll:true});near(f)", $html, 'opening it: the current choice in view');
        $this->assertStringNotContainsString('f.focus();near(f)', $html, 'never the browser\'s own (centring) scroll on opening');
        $this->assertStringContainsString("near=function(el){try{el.scrollIntoView({block:'nearest',inline:'nearest'})}catch(x){}}", $html);
        $this->assertStringContainsString("panel.addEventListener('focusin',function(e){if(!press)near(e.target)})", $html, 'the keyboard: the focused choice in view, not under a press');
        $this->assertStringContainsString("s.maxHeight=Math.max(how==='is-sheet'?vh-16:how?tall:above,120)+'px'", $html, 'the sheet over the screen\'s height');
    }

    public function test_the_panel_opens_above_or_beside_the_dock_only_where_its_list_keeps_two_rows_of_looks(): void
    {
        // On landscape phones (640x360, 667x375, 740x360) the side panel took the 200px beside the dock, its title, hint
        // and "The site's look" 134-152px of them: the list (the only part that scrolls) kept 64-97px, one look at a time
        // in most languages. A place now counts only where the list keeps two rows: the panel is laid out there (its
        // width: the texts wrap there), its fixed part (the panel's height without the list) and the two tallest
        // neighbouring rows are measured, so it holds in every language; else the sheet over the screen's height
        // (about four looks there, the head in view). The scroll positions survive the measuring (a rotation while open).
        // The rows in three browsers and the 17 languages: lane notes runs/p4/rows_<engine>_*.json.
        config(['site.visitor_look_picker_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString("list=panel.querySelector('.avd-try__list'),was=[panel.scrollTop,list.scrollTop],how,hd=d.querySelector('[data-avd-header]'),", $html);
        // Above the dock it stays below the header where the header really is and as tall as it really is: a header ad
        // or the view-only account's note at the page's top moves the header down (the panel covered its lower part
        // there), and the two-row header of Esports Arena and Magazine is 104px / 113px tall from 992px (88px kept, the
        // panel lay 16px / 25px over its second row of section links). The 88px below its top stays the floor, so every
        // header up to 76px tall keeps the place it had.
        $this->assertStringContainsString("hd=d.querySelector('[data-avd-header]'),hb=hd?hd.getBoundingClientRect():null,\nabove=Math.floor(r.top-8-(vh>=600?(hb?Math.max(88+Math.max(0,hb.top),hb.bottom+12):88):8)),", $html);
        $this->assertStringNotContainsString('88+Math.max(0,hd?hd.getBoundingClientRect().top:0)', $html, 'not a fixed 88px header');
        $this->assertStringContainsString("set=function(h){panel.className='avd-try__panel'+(h?' '+h:'');s.width=h==='is-side'?Math.min(352,side)+'px':''}", $html, 'a place is tried in its own width');
        $this->assertStringContainsString("rows=function(h,room){var li=list.children,n=0,i,a,b;set(h);s.maxHeight='none';\nfor(i=1;i<li.length;i++){a=li[i-1].getBoundingClientRect();b=li[i].getBoundingClientRect();n=Math.max(n,b.bottom-a.top)}\nreturn room>=Math.ceil(panel.offsetHeight-list.offsetHeight+n+12)}", $html, 'the fixed part + the two tallest neighbouring rows + the list\'s 6px above and below');
        $this->assertStringContainsString("how=above>=240&&rows('',above)?'':(side>=280&&rows('is-side',tall)?'is-side':'is-sheet');", $html, 'above or beside only with two rows, else the sheet');
        $this->assertStringContainsString("set(how);s.maxHeight=Math.max(how==='is-sheet'?vh-16:how?tall:above,120)+'px';panel.scrollTop=was[0];list.scrollTop=was[1]}", $html);
        $this->assertStringNotContainsString('tall>=200', $html, 'no fixed 200px floor for the side panel');
    }

    public function test_on_phones_the_view_only_note_is_a_banner_at_the_top_of_the_sites_own_pages(): void
    {
        // The view-only demo account's note sat at the bottom centre on phones, where "Back to top" covered its end (the
        // public demo account of a demo site meets it on every page), the phone bottom bar hid it on landscape phones and
        // it lay over the watch page's player there. On phones (portrait, and landscape up to 480px tall) the site's own
        // pages show it as a slim banner at the top of the page, in its flow, as the member pages do; under a header that
        // lies over the top banner (fixed there) it starts below that header. Wider screens, the member pages (their own
        // rule), the admin's pages and every other account are unchanged. The boxes in Chromium and WebKit, every look,
        // English and Arabic: lane notes runs/p4/vo_<engine>_*.json.
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        // While the phone search is open the note steps aside (also on the member pages, where it is at the top too), so the
        // header and its search row are at the top of the screen, where the search places its suggestions (112px).
        $phone = '@media (max-width:767.98px),(max-height:480px) and (max-width:991.98px){html:not([data-avd-member])>body.avd-app>.'.ViewOnlyDemo::NOTE_CLASS
            .'{position:static;transform:none;width:auto;max-width:none;border-radius:0;box-shadow:none;opacity:1}'
            .'body:has(#mobile-search-panel:not([x-cloak]):not([style*="display: none"]))>.'.ViewOnlyDemo::NOTE_CLASS.'{display:none}'
            .'@supports selector(:has(a)){html[data-avd-header-style="transparent_overlay"]:not([data-avd-member])>body.avd-app.has-hero-zone>.'.ViewOnlyDemo::NOTE_CLASS
            .'{margin-top:var(--avd-vo-head,var(--avd-header-h,56px))}}}';
        $wide = '<style>.'.ViewOnlyDemo::NOTE_CLASS.'{position:fixed;z-index:65;left:50%;bottom:16px;transform:translateX(-50%);'
            .'width:max-content;max-width:calc(100vw - 32px);box-sizing:border-box;padding:7px 14px;border-radius:999px;';
        // Under the see-through header the note starts at that header's real height (its rows and padding: 68px on
        // 768-991px wide landscape phones where --avd-header-h says 64px, so the note's top lay 4px under it): the script
        // right after the note reads it as soon as the header is in the page and whenever its size changes, only where
        // that header is fixed over the page's top banner; --avd-header-h until then.
        $measure = '<div class="'.ViewOnlyDemo::NOTE_CLASS.'" role="note">View-only demo: look around freely, changes are switched off.</div>'
            .'<script>(function(d,w){var n=d.currentScript&&d.currentScript.previousElementSibling,b=d.body,e=d.documentElement,t,mt,mo,go,lift,find;'
            .'if(!n||!b)return;'
            .self::NOTE_LIFT
            .'if(!w.ResizeObserver||e.getAttribute("data-avd-header-style")!=="transparent_overlay"||!b.classList.contains("has-hero-zone"))return;'
            .'go=function(){var h=d.querySelector("[data-avd-header]");if(!h)return false;new w.ResizeObserver(function(){'
            .'n.style.setProperty("--avd-vo-head",w.getComputedStyle(h).position==="fixed"?h.getBoundingClientRect().height+"px":"")}).observe(h);return true};'
            .'if(!go()&&w.MutationObserver){mo=new w.MutationObserver(function(){if(go())mo.disconnect()});mo.observe(b,{childList:true,subtree:true});'
            .'d.addEventListener("DOMContentLoaded",function(){mo.disconnect()})}})(document,window)</script>';

        foreach (['/terms', '/'] as $path) {
            $html = (string) $this->actingAs($demo->fresh())->get($path)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'), $path);
            $this->assertStringContainsString($phone, $html, $path.': phones, the site\'s own pages');
            $this->assertStringContainsString($wide, $html, $path.': wider screens keep the note at the bottom centre');
            $this->assertStringContainsString($measure, $html, $path.': the see-through header measured right after the note');
            $this->assertStringNotContainsString('{margin-top:var(--avd-header-h,56px)}', $html, $path.': not the variable alone (64px under a 68px header)');
            $this->assertMatchesRegularExpression('/<body\b[^>]*\bclass="[^"]*\bavd-app\b[^"]*"[^>]*><style>\.'.ViewOnlyDemo::NOTE_CLASS.'\{/', $html, $path.': the note is the body\'s first child, so the rule\'s ">" selector finds it');
        }

        // Every other account and a guest: no note, no rule, no script. actingAs() keeps its account for the requests
        // after it, so the guards are forgotten first and each request is checked for who sent it.
        $this->app['auth']->forgetGuards();
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertGuest();
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, $html, 'a guest');
        $this->assertStringNotContainsString('--avd-vo-head', $html, 'a guest');

        $member = User::factory()->create();
        $html = (string) $this->actingAs($member)->get('/terms')->assertOk()->getContent();
        $this->assertAuthenticatedAs($member);
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, $html, 'another account');
        $this->assertStringNotContainsString('--avd-vo-head', $html, 'another account');
    }

    public function test_the_script_keeps_its_names_to_itself(): void
    {
        // The panel's functions were assigned without a declaration (show, pick, keys after "place"): in a script that
        // is not strict they became globals of the page, so another script with a global "show" (an owner's custom
        // code, a plugin) took the palette button over, and ours overwrote theirs. One var statement holds them all.
        config(['site.visitor_look_picker_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $start = strpos($html, "<script>(function(d,w){var root=d.getElementById('avdTry')");
        $this->assertNotFalse($start, 'the dock\'s script');
        $script = substr($html, $start, strpos($html, '</script>', $start) - $start);

        $from = strpos($script, 'if(btn&&tpl){var outside=function(e){');
        $to = strpos($script, "btn.addEventListener('click',function(){if(open)hide(true);else show()});");
        $this->assertNotFalse($from);
        $this->assertNotFalse($to);
        $declaration = substr($script, $from, $to - $from);
        foreach (['near=function(el){', 'place=function(){', 'show=function(){', 'pick=function(e){', 'keys=function(e){'] as $name) {
            $at = strpos($declaration, "\n".$name);
            $this->assertNotFalse($at, $name);
            $this->assertSame(',', substr($declaration, $at - 1, 1), $name.' continues the var statement');
        }
        $this->assertSame(1, substr_count($declaration, "};\n"), 'the var statement ends once');
        $this->assertStringEndsWith("all[n].focus()};\n", $declaration, 'after the keyboard handler');
    }

    public function test_a_tab_out_of_the_dock_closes_the_panel_at_once(): void
    {
        // The Tab key pressed in the dock and the focus gone without a new target (past the last choice, out of the
        // page): the panel closes in the focusout itself. Waiting for the browser to settle the focus (a timer) is kept
        // for the rest: a press inside the dock (Safari blurs the focused button) and a switch to another app keep it
        // open. Before, the Tab-out also waited for that timer, which a busy browser runs only after its pending input:
        // a check right after the key found the panel still open (the browsers: lane notes runs/p3/tabout_probe_*).
        config(['site.visitor_look_picker_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringContainsString("root.addEventListener('focusout',function(e){var to=e.relatedTarget;if(!open)return;if(to){if(!root.contains(to))hide(false);return}\nif(tab&&!press){hide(false);return}\nw.setTimeout(function(){var a=d.activeElement;if(open&&!press&&(!a||!root.contains(a)||(tab&&!d.hasFocus())))hide(false)},0)})", $html);
        $this->assertStringContainsString("root.addEventListener('pointerdown',function(){press=true;tab=false},true);root.addEventListener('keydown',function(e){press=false;tab=e.key==='Tab';", $html, 'a press is not a Tab; any key ends a press');
    }

    public function test_the_dock_stays_under_the_header_and_each_control_steps_aside_where_the_header_or_the_note_lies_under_it(): void
    {
        // On a landscape phone (up to 767px wide: the bottom bar and the ad strip) with the demo's ads turned on, the
        // header ad pushes the header down into the dock's corner at the page's top, and at 568x320 the dock reaches the
        // header itself: the palette button and the pill lay over "Sign in", "Create account" and "Search" (every look,
        // a guest too), and over the end of the view-only account's note under Cinema Night's see-through header. The
        // dock is one level under the header now (the header is drawn over it where they meet: its controls, menus and
        // search stay on top and take the taps); it rises over the header while the panel is open, while a control holds
        // the keyboard focus (so the focus is never obscured by the header, WCAG 2.4.11) and while a control lies over
        // the header's bare box with no header control under it (so the header's background never cuts it). Where one of
        // the header's controls, the maintenance notice's link or the note lies under a control, the round button turns
        // see-through (clicks go through) and the pill moves to the corner beside the round button while that corner is
        // free, so it stays visible and covers neither a header control nor the note's text (the view-only demo account,
        // whose note is at the page's top on a landscape phone, keeps a working pill at 568x320). One
        // IntersectionObserver per control with its resting box as the root (no work on scroll), armed after the first
        // layout, again on resize and when the bottom layers move (the ad strip's height, a save bar); each run
        // measures the controls from their resting position first; one decide() sets every state from what lies under
        // each control. The browsers: lane notes runs/p7/*_<engine>_*.json.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $header = (string) file_get_contents(resource_path('sass/header.scss'));
        $this->assertMatchesRegularExpression('/\.avd-header,\s*\.navbar\.header\s*\{[^}]*z-index:\s*1035;/', $header, 'the header\'s own place in the stacking order, which the dock\'s two values are set around');
        $this->assertStringContainsString('.avd-try{position:fixed;z-index:1034;', $html, 'one level under the header');
        $this->assertStringContainsString('.avd-try.is-open,.avd-try.is-raise,.avd-try:focus-within{z-index:1036}', $html, 'the open panel, a control with the keyboard focus (never obscured by the header), or a control over the header\'s bare box, over the header');
        $this->assertStringContainsString(';transition:opacity .12s ease;pointer-events:none}', $html, 'the dock\'s own box takes no clicks: the corner beside the round button and the gap were dead spots of the page');
        $this->assertStringContainsString('.avd-try__ads,.avd-try__btn,.avd-try__panel{pointer-events:var(--try-pe,auto)}', $html, 'its controls and the panel do');
        $this->assertStringContainsString('html[data-mk-toast] .avd-try:not(.is-open),.avd-try.is-under:not(.is-open){opacity:0;pointer-events:none;--try-pe:none}', $html, 'stepping aside for a toast or the keyboard focus still lets every click through');
        $this->assertStringContainsString('.avd-try:not(.is-open) .is-shy:not(:focus){opacity:0;pointer-events:none}', $html, 'a control steps aside, never while the panel is open or while it has the focus');
        $this->assertStringContainsString('.avd-try:not(.is-open) .is-moved{position:absolute;inset-block-end:0;inset-inline-end:calc(100% + 8px)}', $html, 'a pill that steps aside moves to the corner beside the round button (while that corner is free), so it stays visible and covers neither a header control nor the note');
        $this->assertStringContainsString('.avd-try.is-shy:not(.is-open):not(:focus-within){opacity:0}', $html, 'the whole dock see-through while every control has stepped aside');
        $this->assertMatchesRegularExpression('/<div class="avd-try avd-try--btt avd-try--both" id="avdTry" hidden/', $html);

        $this->assertStringContainsString("note=d.querySelector('.avd-view-only-note'),hd=d.querySelector('[data-avd-header]'),ctl=[ads,btn].filter(function(c){return c}),", $html, 'the note, the header and the controls, once');
        $this->assertStringContainsString("if(w.IntersectionObserver&&w.requestAnimationFrame){var aim=[].slice.call(d.querySelectorAll('[data-avd-header] a[href],[data-avd-header] button,[data-avd-header] input,[data-avd-header] select,[data-avd-header] [role=button],#avd-maintenance-notice a')),ios=[],fr=0,", $html, 'what a visitor taps in the header and the maintenance notice');
        $this->assertStringContainsString("if(note)aim.push(note);var all=hd?aim.concat([hd]):aim;", $html, 'the view-only account\'s note too, and the header\'s own box (to raise a control that lies over the bare header, where no header control does)');
        $this->assertStringContainsString("calm=function(){ctl.forEach(function(c){c._a=null});decide()};", $html, 'reset: nothing measured, every state cleared (see-through, moved, raised)');
        $this->assertStringContainsString("shy=function(){var vw=cw(),vh=w.innerHeight;calm();", $html, 'each placement measures the controls from their resting position (a moved pill must not measure itself)');
        $this->assertStringContainsString("ctl.forEach(function(c){var r=c.getBoundingClientRect(),on=[],o;if(!r.width||!all.length)return;\no=new w.IntersectionObserver(function(es){es.forEach(function(e){on[all.indexOf(e.target)]=e.isIntersecting});c._a=(on.slice(0,aim.length).indexOf(true)>=0)*2+(on[aim.length]===true);decide()},\n{rootMargin:[-(r.top+1),-(vw-r.right+1),-(vh-r.bottom+1),-(r.left+1)].map(Math.round).join('px ')+'px'});", $html, 'each control on its own, its resting box as the root: 2 a header control or the note under it, 1 the header\'s bare box, 3 both');
        $this->assertStringContainsString("decide=function(){var r=0,m=0,s=1,k=root.classList;ctl.forEach(function(c){var a=c._a,b=a>1,v=b&&c===ads&&btn&&btn._a===0;c.classList.toggle('is-shy',b&&!v);c.classList.toggle('is-moved',!!v);\nif(v)m=1;if(a==1)r=1;if(!b||v)s=0});k.toggle('is-shy',!!s);k.toggle('is-raise',!!r);k.toggle('is-low',!!(hd&&hd.querySelector('[aria-expanded=true]')));if(m!=moved){moved=m;w.requestAnimationFrame(re2)}},", $html, 'one decision: see-through in place, moved, the whole dock see-through, raised over the bare header box, kept under an open popup of the header');
        $this->assertStringContainsString("arm=function(){w.cancelAnimationFrame(fr);fr=w.requestAnimationFrame(function(){fr=w.requestAnimationFrame(shy)})};", $html, 'after the layout settles');
        $this->assertStringContainsString("mo.observe(d.body,{attributes:true,attributeFilter:['style']});mo.observe(html,{attributes:true,attributeFilter:['data-mk-bar']})", $html, 'again when the ad strip\'s height or a save bar moves the dock');
        $this->assertStringContainsString("re=function(){if(calm)calm();fit();if(open&&place)place();re2();if(shy)shy()},", $html, 'placed again: the dock measured whole first');
        $this->assertStringContainsString("all.forEach(function(a){o.observe(a)});ios.push(o)});re2()};arm();", $html, 'the player rule and the keyboard safeguard measured again with the controls');
        $this->assertStringContainsString("w.addEventListener('resize',function(){w.clearTimeout(later);later=w.setTimeout(re,200)});", $html, 'again on resize');

        // One control (one setting on): no pill to take out of the box; the dock see-through when it steps aside.
        config(['site.demo_ads_switch_enabled' => false]);
        $one = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="avd-try avd-try--btt" id="avdTry" hidden/', $one);

        // The script's scroll work: none (observers only; the keyboard safeguard too since R3DW-P7-03).
        $start = strpos($html, "<script>(function(d,w){var root=d.getElementById('avdTry')");
        $script = substr($html, $start, strpos($html, '</script>', $start) - $start);
        $this->assertSame(0, substr_count($script, "'scroll'"), 'no scroll listener at all');
    }

    public function test_a_pill_that_steps_aside_never_takes_a_tap_nobody_can_see(): void
    {
        // R3DW-V5-01: the sixth pass moved a pill that steps aside beside the round button with its own
        // "opacity:1;pointer-events:auto", which beat the dock's own switch (--try-pe) that a toast and the keyboard
        // safeguard turn off with the dock's opacity: the moved pill stayed a live but INVISIBLE button that switched the
        // ads and reloaded the page under a visitor's tap (during a toast, while the focus was on a page element under
        // the dock, and with both controls aside on Cinema Night's home for the view-only account at 568x320, where the
        // corner lay over the note). Now a moved pill keeps the dock's switch (no pointer-events of its own); it moves
        // only while the round button's own place is wholly free (the corner is in the round button's band: the
        // header, its controls and the note's banner reach it only together with that place), else it steps aside in
        // its place like the round button (see-through, click-through); and the keyboard safeguard and the player rule
        // measure every control, a moved pill too. The real taps in three browsers: lane notes runs/p7/trap_*.json.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $css = substr($html, (int) strpos($html, '<style id="avd-try-css">'));
        $css = substr($css, 0, (int) strpos($css, '</style>'));

        $this->assertStringContainsString('.avd-try:not(.is-open) .is-moved{position:absolute;inset-block-end:0;inset-inline-end:calc(100% + 8px)}', $css, 'moved: its place only');
        $this->assertStringNotContainsString('pointer-events:auto', $css, 'no control takes the clicks on its own: the dock\'s switch decides');
        $this->assertStringNotContainsString('.avd-try__ads.is-shy{position:absolute', $css, 'a see-through pill is never moved (and never live)');
        $this->assertStringContainsString('.avd-try__ads,.avd-try__btn,.avd-try__panel{pointer-events:var(--try-pe,auto)}', $css, 'the dock\'s switch');
        $this->assertStringContainsString('html[data-mk-toast] .avd-try:not(.is-open),.avd-try.is-under:not(.is-open){opacity:0;pointer-events:none;--try-pe:none}', $css, 'a toast and the keyboard safeguard switch it off');
        $this->assertStringContainsString('.avd-try:not(.is-open) .is-shy:not(:focus){opacity:0;pointer-events:none}', $css, 'a control aside in its place takes no tap');
        $this->assertStringContainsString('.avd-try.is-shy:not(.is-open):not(:focus-within){opacity:0}', $css, 'the whole dock see-through only while every control is aside in its place');

        // Moved only while the round button's place is wholly free (0: no header control, no note, not the header's box);
        // see-through and moved never together; the whole dock see-through only while no control is moved or shown.
        $this->assertStringContainsString("var a=c._a,b=a>1,v=b&&c===ads&&btn&&btn._a===0;c.classList.toggle('is-shy',b&&!v);c.classList.toggle('is-moved',!!v);\nif(v)m=1;if(a==1)r=1;if(!b||v)s=0});", $html);
        $this->assertStringNotContainsString("ads._m", $html, 'no second, unmeasured corner');

        // The keyboard safeguard and the player rule: every control, a moved pill too (zone(): the dock's box and the
        // moved pill's, which leaves the dock's box).
        $this->assertStringContainsString("zone=function(){var r=root.getBoundingClientRect(),p=moved?ads.getBoundingClientRect():r;return{top:r.top,bottom:r.bottom,left:Math.min(r.left,p.left),right:Math.max(r.right,p.right)}},", $html);
        $this->assertStringContainsString("see=function(o,el,p,c){var r=zone(),vw=cw(),vh=w.innerHeight;", $html, 'the keyboard safeguard and the player rule: an observer against zone()');
        $this->assertStringContainsString("re2=function(){if(watch)watch();uo=see(uo,fo,1,'is-under')},", $html, 'the keyboard safeguard');
        $this->assertStringContainsString("watch=function(){po=see(po,player,4,'is-clear')};", $html, 'the player rule');
        $this->assertStringContainsString("if(m!=moved){moved=m;w.requestAnimationFrame(re2)}", $html, 'both measured again when the pill moves');
        // The observers' boxes are exactly the controls': the width of the box the dock is fixed in is measured on the dock
        // itself (html.clientWidth counted the page's scrollbar gutter, html{scrollbar-gutter:stable}, in desktop
        // Chromium, so each observed box missed its control's last 11px at the end side; the side panel in Arabic too).
        $this->assertStringContainsString("cw=function(){return root.getBoundingClientRect().right+parseFloat(w.getComputedStyle(root).right)},", $html);
        $this->assertStringContainsString('scrollbar-gutter: stable;', (string) file_get_contents(resource_path('sass/app.scss')), 'the page keeps a scrollbar gutter');
        $start = strpos($html, "<script>(function(d,w){var root=d.getElementById('avdTry')");
        $script = substr($html, $start, strpos($html, '</script>', $start) - $start);
        $this->assertStringNotContainsString('html.clientWidth', $script, "nowhere in the dock's script");
        $this->assertSame(3, substr_count($script, 'vw=cw()'), "the panel's place, the observers' helper see() (the player rule, the keyboard safeguard) and the controls' observers");
        $this->assertStringNotContainsString("b=root.getBoundingClientRect();\non=a.width>0", $html, 'not the dock\'s own box alone');

        // One control (one setting on): nothing to move.
        config(['site.visitor_look_picker_enabled' => false]);
        $one = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="avd-try avd-try--btt" id="avdTry" hidden/', $one);
        $this->assertStringNotContainsString('id="avdTryLook"', $one);
    }

    public function test_the_keyboard_safeguard_follows_the_focused_element_through_every_scroll(): void
    {
        // R3DW-P7-03: the safeguard measured the focused page element once, one frame after the focus, and the next
        // scroll event switched it off. The site scrolls smoothly (html{scroll-behavior:smooth}): a Tab to an element out
        // of view starts a scroll that is still running in that frame, so the measure saw the element on its way and the
        // scroll's own events cleared it - the moved pill over the focused card on the view-only account's home at
        // 568x320 with the demo's ads on, the dock not stepping aside. Now the safeguard is an IntersectionObserver of
        // the focused element against the dock's zone (its box and a moved pill's), the player rule's helper: it follows
        // the element through every scroll and lets the dock come back when it leaves, with no scroll handler. Real Tab
        // presses in three browsers: lane notes verify_p7/runs/tabwalk_*.json.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $start = strpos($html, "<script>(function(d,w){var root=d.getElementById('avdTry')");
        $this->assertNotFalse($start);
        $script = substr($html, $start, strpos($html, '</script>', $start) - $start);

        $this->assertStringContainsString('html[data-mk-toast] .avd-try:not(.is-open),.avd-try.is-under:not(.is-open){opacity:0;pointer-events:none;--try-pe:none}', $html, 'the safeguard\'s look: unchanged');
        $this->assertStringContainsString("calm=null,fo=null,uo=null,po=null,", $script, 'the focused element and the two observers, declared with the rest');
        $this->assertStringContainsString("d.addEventListener('focusin',function(e){var t=e.target;if(open&&t&&!root.contains(t))hide(false);fo=t&&t!==d.body&&!root.contains(t)?t:null;w.requestAnimationFrame(re2)});", $script, 'every focus: the element to follow (none inside the dock)');
        $this->assertStringContainsString("re2=function(){if(watch)watch();uo=see(uo,fo,1,'is-under')},", $script);
        $this->assertStringContainsString("see=function(o,el,p,c){var r=zone(),vw=cw(),vh=w.innerHeight;if(o)o.disconnect();if(!el||!r.right||!w.IntersectionObserver){root.classList.remove(c);return null}\no=new w.IntersectionObserver(function(es){root.classList.toggle(c,es[es.length-1].isIntersecting)},\n{rootMargin:[-(r.top+p),-(vw-r.right+p),-(vh-r.bottom+p),-(r.left+p)].map(Math.round).join('px ')+'px'});o.observe(el);return o};", $script, 'one observer of the element against the dock\'s zone, the class switched as they meet or part');
        $this->assertStringNotContainsString("'scroll'", $script, 'no scroll handler');
        $this->assertStringNotContainsString('on=a.width>0&&a.right>b.left', $script, 'no one-off measure that a running scroll outdates');
        // measured again whenever the zone can change: the pill moves, resize / the note moves, the controls are measured
        $this->assertStringContainsString("if(m!=moved){moved=m;w.requestAnimationFrame(re2)}", $script);
        $this->assertStringContainsString("re=function(){if(calm)calm();fit();if(open&&place)place();re2();if(shy)shy()},", $script);
        $this->assertStringContainsString("ios.push(o)});re2()};arm();", $script);

        // One control (one setting on): the same safeguard.
        config(['site.demo_ads_switch_enabled' => false]);
        $one = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringContainsString("uo=see(uo,fo,1,'is-under')", $one);
    }

    public function test_the_dock_never_rises_over_the_headers_own_popups(): void
    {
        // R3DW-V5-02: the sixth pass raised the dock over the header (1036) while a control lay over the header's bare
        // box; opening the phone search hides the header's row, so on landscape phones with the demo's ads on the raised
        // pill and palette button were drawn over the search's suggestions (fixed 112px from the top, inside the
        // header): a tap on a suggestion opened "Try a look" or switched the ads. While a control of the header says
        // aria-expanded="true" (the phone search open, the suggestions shown, the account or notifications panel, the
        // side menu), the dock stays one level under the header whatever else; only its own open panel (the visitor's
        // dialog) stays over it. R3DW-P7-02: that low state is a class the script sets (a MutationObserver on the
        // header's aria-expanded calls decide(), before the next frame), not a :has() on the body, which every appended
        // feed page is inserted under (the 2026-10 performance contract: ThemeCssContractTest). The popups in three
        // browsers: lane notes runs/p7/popup_*.json; the frame the popup opens in: runs/p7/low_frame_*.json.
        $this->ads();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $raise = '.avd-try.is-open,.avd-try.is-raise,.avd-try:focus-within{z-index:1036}';
        $low = '.avd-try.is-low:not(.is-open){z-index:1034}';
        $this->assertStringContainsString($raise, $html);
        $this->assertStringContainsString($low, $html, 'never over an open popup of the header: the raise for the bare header box and for the keyboard focus both give way');
        $this->assertGreaterThan(strpos($html, $raise), strpos($html, $low), 'after the raise (and of a higher weight anyway)');
        $this->assertStringContainsString("k.toggle('is-raise',!!r);k.toggle('is-low',!!(hd&&hd.querySelector('[aria-expanded=true]')));", $html, 'decide() sets the low state with the others, from the header itself');
        $this->assertStringContainsString("if(hd)new w.MutationObserver(decide).observe(hd,{attributes:true,subtree:true,attributeFilter:['aria-expanded']})", $html, 'at once whenever a control of the header opens or closes its popup (an observer runs before the next frame), the search and notifications React mounts later included (the subtree)');
        $start = strpos($html, '<style id="avd-try-css">');
        $this->assertNotFalse($start);
        $css = substr($html, $start, strpos($html, '</style>', $start) - $start);
        $this->assertStringNotContainsString(':has(', $css, 'no :has() in the dock\'s CSS: one on the body is checked again, with all it styles, for every appended feed page');

        // The contract the rule relies on: each popup of the header says it is open.
        $this->assertMatchesRegularExpression('/<button id="mobile-search-toggle" [^>]*aria-expanded="false" :aria-expanded="search"/', $html, 'the phone search');
        $this->assertStringContainsString('aria-expanded={resultsVisible}', (string) file_get_contents(resource_path('js/components/Search/SearchBar.tsx')), 'the search\'s suggestions (phone and wide)');
        $this->assertStringContainsString('aria-expanded={open}', (string) file_get_contents(resource_path('js/components/Notifications/NotificationsList.tsx')), 'the notifications panel');
        $this->assertStringContainsString("sidebarToggles.forEach(toggle => toggle.setAttribute('aria-expanded', String(expanded)));", (string) file_get_contents(resource_path('js/app.ts')), 'the side menu');
    }

    public function test_on_wider_screens_the_view_only_note_never_meets_back_to_top(): void
    {
        // DW-VO-02 (int-C3's own, in scope for 1.2.3: the public demo account of clfcms.com): on wider screens the
        // view-only account's note is fixed at the bottom centre and "Back to top" at the bottom-end corner; a long
        // language's note (German, Russian) ran 55px under the button at 768x1024 and 820x1180, on home and Terms, with
        // both demo settings off too. The note's own script now sits it 12px above the button wherever it would come
        // within 8px of it; English and every other place are unchanged. The demo site's buttons rise above the note
        // again when it moves. Every width 320-1920, every look, the long languages: lane notes runs/p7/note_*.json.
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        foreach ([false, true] as $dock) {
            config(['site.visitor_look_picker_enabled' => $dock, 'site.demo_ads_switch_enabled' => false]);
            $html = (string) $this->actingAs($demo->fresh())->get('/terms')->assertOk()->getContent();
            $this->assertStringContainsString('<div class="'.ViewOnlyDemo::NOTE_CLASS.'" role="note">', $html);
            $this->assertStringContainsString('<script>(function(d,w){var n=d.currentScript&&d.currentScript.previousElementSibling,b=d.body,e=d.documentElement,t,mt,mo,go,lift,find;if(!n||!b)return;'.self::NOTE_LIFT, $html, 'the note\'s own script, with or without the demo site\'s buttons');
            $this->assertStringContainsString('id="back-to-top"', $html);

            // The note watches the body's own children for the button: the button must be one of them.
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $button = $dom->getElementById('back-to-top');
            $this->assertNotNull($button);
            $this->assertSame('body', $button->parentNode->nodeName, '"Back to top" is a child of the body');

            if ($dock) {
                $this->assertStringContainsString("if(note)note.addEventListener('avd-vo-moved',re);", $html, 'the demo site\'s buttons placed again when the note moves');
            } else {
                $this->assertStringNotContainsString('avd-try', $html);
            }
        }

        // "Back to top" off: nothing to keep clear of (the script finds no button and stops at the end of the page).
        config(['site.back_to_top_enabled' => false]);
        $html = (string) $this->actingAs($demo->fresh())->get('/terms?back_to_top_enabled=0')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="back-to-top"', $html);
        $this->assertStringContainsString('d.addEventListener("DOMContentLoaded",function(){mt.disconnect();if(!t)find()})', $html);

        // Every other account: no note, no script.
        $this->app['auth']->forgetGuards();
        $html = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertGuest();
        $this->assertStringNotContainsString('class="'.ViewOnlyDemo::NOTE_CLASS.'"', $html, 'a guest: no note');
        $this->assertStringNotContainsString('lift=function(){', $html, 'a guest: no note script');
    }

    public function test_the_studio_keeps_the_sites_own_ad_option_of_a_video(): void
    {
        // The member back office prints no ad; Video details keeps "Show ads on this video" while the site shows ads,
        // whether this browser turned ads on or not.
        $this->ads();
        config(['site.demo_ads_switch_enabled' => true]);
        $member = User::factory()->create();
        $video = $this->video($member);

        foreach ([[], [VisitorChoices::ADS_COOKIE => '1']] as $cookies) {
            $html = (string) $this->actingAs($member)->withUnencryptedCookies($cookies)->get(route('user.videos.edit', $video))->assertOk()->getContent();
            $this->assertStringContainsString('Show ads on this video', $html);
            $this->assertStringContainsString('id="avdTryAds"', $html, 'the pill is there too');
        }
    }

    public function test_the_member_back_office_shows_the_controls_and_the_sign_in_page_too(): void
    {
        config(['site.visitor_look_picker_enabled' => true]);
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('user.edit'))->assertOk()->assertSee('id="avdTryLook"', false);
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('id="avdTryLook"', false);
    }

    // ---------------------------------------------------------------------------------------------------- caching

    public function test_while_a_setting_is_on_the_pages_are_private(): void
    {
        $video = $this->video();
        $watch = app(SeoService::class)->videoUrl($video);

        $this->assertStringContainsString('s-maxage', (string) $this->get($watch)->assertOk()->headers->get('Cache-Control'), 'the watch page is public for guests');

        foreach ([['site.visitor_look_picker_enabled' => true], ['site.demo_ads_switch_enabled' => true]] as $setting) {
            config(array_merge(['site.visitor_look_picker_enabled' => false, 'site.demo_ads_switch_enabled' => false], $setting));

            foreach (['/', '/terms', $watch] as $url) {
                $response = $this->get($url)->assertOk();
                $cacheControl = (string) $response->headers->get('Cache-Control');
                $where = key($setting).' '.$url;

                $this->assertStringContainsString('no-store', $cacheControl, $where);
                $this->assertStringContainsString('private', $cacheControl, $where);
                $this->assertStringNotContainsString('public', $cacheControl, $where);
                $this->assertStringNotContainsString('s-maxage', $cacheControl, $where);
                $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'), $where);
                $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'), $where);
                $this->assertContains('cookie', array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Vary')))), $where);
                $this->assertNull($response->headers->get('X-Robots-Tag'), "{$where}: still a page for search engines");
            }
        }
    }

    public function test_a_crawler_without_cookies_sees_the_site_look_no_ads_and_the_same_addresses(): void
    {
        $this->ads();
        $plain = (string) $this->get('/terms')->assertOk()->getContent();
        config(['site.visitor_look_picker_enabled' => true, 'site.demo_ads_switch_enabled' => true]);
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $this->assertSame($this->htmlAttribute($plain, 'data-avd-theme'), $this->htmlAttribute($html, 'data-avd-theme'));
        $this->assertNoAds($html, 'crawler');

        foreach (['/<link rel="canonical" href="[^"]*">/', '/<link rel="alternate" hreflang="[^"]*" href="[^"]*">/', '/<meta name="robots" content="[^"]*">/'] as $pattern) {
            preg_match_all($pattern, $plain, $before);
            preg_match_all($pattern, $html, $after);
            $this->assertNotSame([], $before[0], $pattern);
            $this->assertSame($before[0], $after[0], $pattern);
        }

        // The controls are buttons, never links.
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*avd-try/', $html);
    }

    // ---------------------------------------------------------------------------------------------------- the settings

    public function test_the_two_settings_are_off_by_default_on_their_pages_and_saved(): void
    {
        config(['admin.ui' => 'v2']);
        $look = config('admin_settings.settings.visitor_look_picker_enabled');
        $ads = config('admin_settings.settings.demo_ads_switch_enabled');
        $this->assertSame(['general', 'bool', 'appearance.themes', 'visitors', '0', 'Let visitors try the looks'], [$look['group'], $look['type'], $look['page'], $look['card'], $look['default'], $look['label']]);
        $this->assertSame(['monetization', 'bool', 'money.ads', 'switch', '0', 'Demo site: ads are off until a visitor turns them on'], [$ads['group'], $ads['type'], $ads['page'], $ads['card'], $ads['default'], $ads['label']]);

        $this->app->getProvider(AppServiceProvider::class)->applySettings(SiteSettingsSource::web());
        $this->assertFalse(config('site.visitor_look_picker_enabled'));
        $this->assertFalse(config('site.demo_ads_switch_enabled'));

        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', false);
        $this->actingAs($admin)->get('/admin/appearance')->assertOk()
            ->assertSee('id="card-visitors"', false)->assertSee('id="visitor_look_picker_enabled-row"', false)->assertSee('Let visitors try the looks');
        $this->actingAs($admin)->get('/admin/money/ads')->assertOk()
            ->assertSee('id="demo_ads_switch_enabled-row"', false)->assertSee('Demo site: ads are off until a visitor turns them on');

        $this->actingAs($admin)->putJson('/admin/settings/save', [
            'groups' => ['monetization' => ['demo_ads_switch_enabled' => '1']],
            'fields' => ['monetization' => ['demo_ads_switch_enabled']],
        ])->assertOk();
        $this->assertSame('1', Setting::getGroup('monetization')['demo_ads_switch_enabled'] ?? null);

        // The look picker is published from the Themes page's draft, like the other settings of the Appearance pages.
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => ['visitor_look_picker_enabled' => '1'], 'page' => 'appearance.themes'])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();
        $this->assertSame('1', Setting::getGroup('general')['visitor_look_picker_enabled'] ?? null);

        $this->app->getProvider(AppServiceProvider::class)->applySettings(SiteSettingsSource::web());
        $this->assertTrue(config('site.visitor_look_picker_enabled'));
        $this->assertTrue(config('site.demo_ads_switch_enabled'));

        // Old clients of the group save (the old settings page) never write them.
        $schema = app(\App\Http\Controllers\Admin\SettingsController::class)->saveSchema();
        $this->assertTrue($schema['general']['visitor_look_picker_enabled']['only_when_sent'] ?? false);
        $this->assertTrue($schema['monetization']['demo_ads_switch_enabled']['only_when_sent'] ?? false);
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    /**
     * Every request of these tests carries only the cookies given for it (withUnencryptedCookies() right before it, with
     * withCredentials() for a JSON request):
     * the test's cookie jar would otherwise send one request's choice with the next one, as no browser of another
     * visitor does.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];
            $this->withCredentials = false;
        }
    }

    /**
     * The answer to a form drawn by the public layout: "This page timed out" (419), what a POST whose token expired gets
     * (the token check itself is off in tests, so a route of the web group answers it the same way).
     */
    private function formAnswerRoute(): void
    {
        Route::middleware('web')->post('/dw-form-answer', fn () => abort(419));
    }

    /** Every kind of ad, each with its own marker; the site shows ads. */
    private function ads(): void
    {
        config([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => true,
            'site.ad_code_header' => '<div class="dw-ad-header">header ad</div>',
            'site.ad_code_footer' => '<div class="dw-ad-footer">footer ad</div>',
            'site.ad_code_mobile' => '<div class="dw-ad-mobile">phone ad</div>',
            'site.ad_onclick_enabled' => true,
            'site.ad_code_onclick' => '<script>window.dwPopunder = 1;</script>',
            'site.ad_code_sidebar' => '<div class="dw-ad-side">side ad</div>',
            'site.ad_code_video' => '<div class="dw-ad-video">video ad</div>',
            'site.ad_preroll_enabled' => true,
            'site.ad_preroll_media_url' => 'https://ads.dw.example/pre.mp4 | https://ads.dw.example/go',
            'site.ad_grid_position' => 2,
            'site.ad_grid_max_per_feed' => 2,
            'site.ad_code_grid_new_videos' => '<div class="dw-ad-list">list ad</div>',
            'site.ad_code_grid_home_feed' => '<div class="dw-ad-home">home list ad</div>',
            'site.ad_code_grid_search_results' => '<div class="dw-ad-search">search list ad</div>',
            'site.ad_code_grid_channel_home_latest' => '<div class="dw-ad-channel">channel ad</div>',
            'site.ad_code_grid_channel_home_featured' => '<div class="dw-ad-channel">channel ad</div>',
            'site.ad_code_grid_channel_videos' => '<div class="dw-ad-channel">channel ad</div>',
            'site.watch_sidebar_ads_enabled' => true,
        ]);
    }

    private function assertNoAds(string $html, string $where): void
    {
        foreach (['dw-ad-', 'dwPopunder', 'ads.dw.example', 'id="ad-slot-header"', 'id="ad-slot-footer"', 'id="ad-slot-mobile"', 'id="ad-slot-onclick"'] as $part) {
            $this->assertStringNotContainsString($part, $html, "{$where}: {$part}");
        }

        $this->assertMatchesRegularExpression('/window\.AD_CONFIG = \{\s*enabled: false,\s*prerollEnabled: false,/', $html, "{$where}: the pre-roll gets no configuration");
        $this->assertMatchesRegularExpression('/prerollAds: \[\],/', $html, $where);
        $this->assertMatchesRegularExpression('/gridAdCodeByContext: \[\],/', $html, $where);
        $this->assertMatchesRegularExpression('/sidebarAdCode: "",/', $html, $where);
    }

    private function assertAds(string $html, string $where): void
    {
        foreach (['id="ad-slot-header"', 'id="ad-slot-footer"', 'id="ad-slot-mobile"', 'id="ad-slot-onclick"', 'dwPopunder', 'ads.dw.example/pre.mp4', 'dw-ad-list'] as $part) {
            $this->assertStringContainsString($part, $html, "{$where}: {$part}");
        }

        $this->assertMatchesRegularExpression('/window\.AD_CONFIG = \{\s*enabled: true,\s*prerollEnabled: true,/', $html, $where);
    }

    private function video(?User $channel = null, array $attributes = []): Video
    {
        $at = $attributes['published_at'] ?? now()->subDay();

        return Video::factory()->for($channel ?? User::factory())->withStatus(VideoStatus::PUBLIC)->create($attributes + ['published_at' => $at, 'uploaded_at' => $at]);
    }

    private function siteTheme(): string
    {
        return (string) $this->htmlAttribute((string) $this->get('/terms')->assertOk()->getContent(), 'data-avd-theme');
    }

    private function htmlAttribute(string $html, string $name): ?string
    {
        return preg_match('/<html\b[^>]*\s'.preg_quote($name, '/').'="([^"]*)"/', $html, $match) === 1 ? $match[1] : null;
    }

    private function cookie(TestResponse $response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
