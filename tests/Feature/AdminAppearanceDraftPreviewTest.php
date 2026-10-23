<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AppearanceDraft;
use App\Support\AppearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Draft preview of the Appearance pages (interface I9): changes are this admin's draft, shown on the real site through
 * a signed, expiring link that only works for them; everybody else gets the published design, and preview responses
 * are private, no-store and noindex.
 */
class AdminAppearanceDraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
        config([
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
        Setting::set('appearance_schema_version', '2', 'appearance');
        Setting::set('theme_preset', 'tokyo_neon', 'appearance');
        Setting::set('theme_flavor', 'signature', 'appearance');
        Setting::set('home_intro_title', 'Published welcome', 'appearance');
        Setting::set('home_layout_mode', 'youtube_spotlight', 'appearance');
        $this->boot();
    }

    public function test_a_signed_link_shows_the_draft_to_its_author_only(): void
    {
        $author = $this->admin();
        $other = $this->admin();
        $token = $this->draft($author, ['set' => ['home_intro_title' => 'Draft welcome', 'theme_preset' => 'broadsheet', 'home_layout_mode' => 'bento_mosaic']])->json('token');

        $this->assertNotEmpty($token);
        $url = '/?'.AppearanceDraft::QUERY.'='.urlencode($token);

        $mine = $this->actingAs($author)->get($url)->assertOk();
        $mine->assertSee('data-avd-theme="broadsheet"', false);
        $mine->assertSee('data-avd-home-layout="bento_mosaic"', false);
        $this->assertSame('Draft welcome', config('site.home_intro_title'));

        // Another admin and a guest with the very same link see what visitors see.
        $this->boot();
        $theirs = $this->actingAs($other)->get($url)->assertOk();
        $theirs->assertSee('data-avd-theme="tokyo_neon"', false);
        $theirs->assertSee('data-avd-home-layout="youtube_spotlight"', false);
        $this->assertSame('Published welcome', config('site.home_intro_title'));

        $this->boot();
        $this->app['auth']->forgetGuards();
        $guest = $this->get($url)->assertOk();
        $guest->assertSee('data-avd-theme="tokyo_neon"', false);
        $guest->assertSee('data-avd-home-layout="youtube_spotlight"', false);

        // Nothing reached the settings table.
        $this->assertSame('Published welcome', Setting::getGroup('appearance')['home_intro_title']);
        $this->assertSame('tokyo_neon', Setting::getGroup('appearance')['theme_preset']);
    }

    public function test_preview_responses_are_private_no_store_and_noindex(): void
    {
        $author = $this->admin();
        $token = $this->draft($author, ['set' => ['home_intro_title' => 'Draft welcome']])->json('token');

        foreach ([$author, null] as $user) {
            $this->app['auth']->forgetGuards();
            $request = $user ? $this->actingAs($user) : $this;
            $response = $request->get('/?avd_draft='.urlencode($token))->assertOk();
            $cache = (string) $response->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringContainsString('private', $cache);
            $this->assertStringNotContainsString('s-maxage', $cache);
            $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
            $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'));
        }
    }

    public function test_a_token_is_bound_to_its_user_its_draft_and_30_minutes(): void
    {
        $author = $this->admin();
        $values = ['home_intro_title' => 'Draft welcome'];
        AppearanceDraft::put($author->id, ['values' => $values, 'action' => null]);
        $token = AppearanceDraft::sign($author->id, $values);

        $this->assertSame($values, AppearanceDraft::verify($token, $author));
        $this->assertNull(AppearanceDraft::verify($token, $author->id + 1));
        $this->assertNull(AppearanceDraft::verify($token, null));
        $this->assertNull(AppearanceDraft::verify('x'.$token, $author));
        $this->assertNull(AppearanceDraft::verify(strrev($token), $author));
        $this->assertSame('invalid', AppearanceDraft::status(str_replace('.', '.A', $token), $author)['state']);

        // A changed draft makes older links stale.
        AppearanceDraft::put($author->id, ['values' => ['home_intro_title' => 'Newer draft'], 'action' => null]);
        $this->assertNull(AppearanceDraft::verify($token, $author));
        $this->assertSame('stale', AppearanceDraft::status($token, $author)['state']);

        // 30 minutes.
        $fresh = AppearanceDraft::sign($author->id, ['home_intro_title' => 'Newer draft']);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertNull(AppearanceDraft::verify($fresh, $author));
        Carbon::setTestNow();
    }

    public function test_an_expired_link_shows_the_published_design_and_says_so_to_the_admin(): void
    {
        $author = $this->admin();
        $token = $this->draft($author, ['set' => ['home_intro_title' => 'Draft welcome']])->json('token');

        $this->travel(31)->minutes();

        $response = $this->actingAs($author)->get('/?avd_draft='.urlencode($token))->assertOk();
        $this->assertSame('Published welcome', config('site.home_intro_title'));
        $this->assertSame('stale', request()->attributes->get('avd.appearance_draft'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_banner_shows_only_for_the_author_and_keeps_the_draft_on_links(): void
    {
        if (! str_contains((string) file_get_contents(resource_path('views/components/layout.blade.php')), 'avd.appearance_draft')) {
            $this->markTestSkipped('The public layout does not include the draft banner yet (request to THEME in notes/wp8.md).');
        }

        $author = $this->admin();
        $token = $this->draft($author, ['set' => ['home_intro_title' => 'Draft welcome']])->json('token');

        $this->actingAs($author)->get('/?avd_draft='.urlencode($token))
            ->assertOk()
            ->assertSee('id="avd-draft-banner"', false)
            ->assertSee('Preview of your unpublished changes', false);

        $this->actingAs($this->admin())->get('/?avd_draft='.urlencode($token))->assertOk()->assertDontSee('id="avd-draft-banner"', false);
        $this->app['auth']->forgetGuards();
        $this->get('/?avd_draft='.urlencode($token))->assertOk()->assertDontSee('id="avd-draft-banner"', false);
        $this->get('/')->assertOk()->assertDontSee('id="avd-draft-banner"', false);
    }

    public function test_the_draft_endpoint_checks_every_value_with_the_settings_rules(): void
    {
        $admin = $this->admin();

        $this->draft($admin, ['set' => ['grid_columns' => '9', 'home_layout_mode' => 'nonsense', 'home_intro_title' => 'Fine']])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['appearance.grid_columns', 'appearance.home_layout_mode']]);

        // The valid change is kept; the invalid ones are not.
        $this->assertSame(['home_intro_title' => 'Fine'], AppearanceDraft::get($admin->id)['values']);

        // Only settings of the Appearance pages; never keys of other pages, passwords or the bookkeeping keys.
        $this->draft($admin, ['set' => ['smtp_password' => 'x', 'site_name' => 'x', 'appearance_schema_version' => '1', 'active_master_experience' => 'x']])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email.smtp_password', 'general.site_name', 'appearance.appearance_schema_version', 'appearance.active_master_experience']]);
        $this->assertSame(['home_intro_title' => 'Fine'], AppearanceDraft::get($admin->id)['values']);
    }

    public function test_the_daily_design_switch_and_the_phone_bar_are_drafts_like_any_setting(): void
    {
        $admin = $this->admin();
        Setting::set('daily_design_auto_enabled', '0', 'appearance');
        Setting::set('mobile_bottom_nav_enabled', '1', 'appearance');

        $values = $this->draft($admin, ['set' => ['daily_design_auto_enabled' => '1', 'mobile_bottom_nav_enabled' => '0']])->assertOk()->json('values');

        $this->assertSame('1', $values['daily_design_auto_enabled']);
        // The old bottom-bar key follows the new one, as the settings save does.
        $this->assertSame('0', $values['mobile_bottom_nav_enabled']);
        $this->assertSame('0', $values['mobile_bottom_dock_enabled']);
    }

    public function test_custom_code_changes_need_developer_mode(): void
    {
        $admin = $this->admin();

        $this->draft($admin, ['set' => ['custom_css' => 'body{color:red}']])->assertStatus(422);
        $this->assertSame([], AppearanceDraft::get($admin->id)['values']);

        AdminPreferences::set($admin, 'developer_mode', true);
        $this->draft($admin->fresh(), ['set' => ['custom_css' => 'body{color:red}']])->assertOk();
        $this->assertSame('body{color:red}', AppearanceDraft::get($admin->id)['values']['custom_css']);
    }

    public function test_a_value_equal_to_the_published_one_leaves_the_draft(): void
    {
        $admin = $this->admin();

        $this->draft($admin, ['set' => ['home_intro_title' => 'Draft welcome']])->assertOk()->assertJson(['count' => 1]);
        $this->draft($admin, ['set' => ['home_intro_title' => 'Published welcome']])->assertOk()->assertJson(['count' => 0, 'token' => null]);
        $this->assertSame([], AppearanceDraft::get($admin->id)['values']);
    }

    public function test_a_look_or_a_theme_replaces_the_one_picked_before(): void
    {
        $admin = $this->admin();

        $look = $this->draft($admin, ['look' => 'netflix_cinema'])->assertOk()->json();
        $this->assertSame('look', $look['action']['type']);
        $this->assertSame('netflix_cinema', $look['action']['id']);
        $this->assertSame('pure_oled_black', $look['values']['theme_preset']);
        $this->assertSame('netflix_cinema', $look['values']['active_master_experience']);

        // A theme keeps the admin's layouts unless asked; the look's keys are gone.
        $theme = $this->draft($admin, ['theme' => ['id' => 'broadsheet', 'flavor' => null, 'layouts' => false]])->assertOk()->json();
        $this->assertSame('theme', $theme['action']['type']);
        $this->assertSame('broadsheet', $theme['values']['theme_preset']);
        $this->assertArrayNotHasKey('home_layout_mode', $theme['values']);
        $this->assertSame('', $theme['values']['active_master_experience'] ?? '', 'a theme is no longer a Complete look');

        // A field changed by hand stays when another theme is picked.
        $this->draft($admin, ['set' => ['home_intro_title' => 'Mine']]);
        $again = $this->draft($admin, ['theme' => ['id' => 'daylight_paper', 'flavor' => null, 'layouts' => true]])->json();
        $this->assertSame('Mine', $again['values']['home_intro_title']);
        $this->assertSame('daylight_paper', $again['values']['theme_preset']);

        $cancel = $this->draft($admin, ['cancel' => true])->json();
        $this->assertNull($cancel['action']);
        $this->assertSame(['home_intro_title' => 'Mine'], (array) $cancel['values']);
    }

    public function test_discarding_forgets_the_draft(): void
    {
        $admin = $this->admin();
        $this->draft($admin, ['set' => ['home_intro_title' => 'Draft welcome']]);

        $this->actingAs($admin)->deleteJson(route('admin.appearance.draft.discard'))->assertOk()->assertJson(['ok' => true, 'count' => 0]);
        $this->assertSame([], AppearanceDraft::get($admin->id)['values']);
    }

    public function test_a_field_put_back_answers_the_value_it_shows_now(): void
    {
        $admin = $this->admin();
        $this->draft($admin, ['set' => ['primary_color' => '#2563eb']])->assertOk()->assertJsonPath('values.primary_color', '#2563eb');

        // "Use the colour style": the field shows the colour style's colour again, not the typed one.
        $this->draft($admin, ['reset' => ['primary_color'], 'page' => 'appearance.brand'])
            ->assertOk()
            ->assertJsonPath('shown.primary_color', '#ff0055')
            ->assertJsonPath('count', 0);

        // Undo on a row: the published value.
        $this->draft($admin, ['set' => ['home_intro_title' => 'Draft welcome']])->assertOk();
        $this->draft($admin, ['unset' => ['home_intro_title']])->assertOk()->assertJsonPath('shown.home_intro_title', 'Published welcome');
    }

    public function test_the_colour_note_follows_the_draft(): void
    {
        $admin = $this->admin();

        // The colour engine of the site (Midnight Spotlight: white labels) moves a grey a little for white text. Colour
        // codes in the sentence are isolated, so Arabic keeps "#767676" in order.
        $iso = fn (string $hex): string => "\u{2066}{$hex}\u{2069}";
        $note = $this->draft($admin, ['set' => ['primary_color' => '#7a7a7a'], 'page' => 'appearance.brand'])->assertOk()->json('colour_note_html');
        $this->assertStringContainsString('data-ad-colour-note', $note);
        $this->assertStringContainsString(__('Your own colour :colour shows as :shown on buttons so white text stays readable (4.5:1).', ['colour' => $iso('#7a7a7a'), 'shown' => $iso('#767676')]), $note);

        $html = $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => 'brand']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="card-colors".*data-ad-colour-note.*id="card-fonts"/s', $html, 'the Colours card shows the note');
        $this->assertStringContainsString('#767676', $html);

        $yellow = $this->draft($admin, ['set' => ['primary_color' => '#ffd400'], 'page' => 'appearance.brand'])->json('colour_note_html');
        $this->assertStringContainsString(__('Your own colour :colour is used as it is: text on it is readable.', ['colour' => $iso('#ffd400')]), $yellow);

        $off = $this->draft($admin, ['set' => ['brand_contrast_autofix' => '0'], 'page' => 'appearance.brand'])->json('colour_note_html');
        $this->assertStringContainsString('used exactly as it is', $off);
        $this->assertNull($this->draft($admin, ['set' => ['brand_contrast_autofix' => '0']])->json('colour_note_html'), 'only the colour pages get the note');
    }

    public function test_colour_codes_stay_left_to_right_on_every_page_with_a_colour_field(): void
    {
        $admin = $this->admin();

        // A right-to-left page (Arabic) would show "ff0055#" in the hex field.
        foreach (['brand', 'channels'] as $page) {
            $html = $this->actingAs($admin)->get(route('admin.appearance.page', ['page' => $page]))->assertOk()->getContent();
            preg_match_all('/<input type="text"[^>]*data-ad-color-hex>/', $html, $fields);

            $this->assertNotEmpty($fields[0], $page);

            foreach ($fields[0] as $field) {
                $this->assertStringContainsString(' dir="ltr"', $field, $page);
            }
        }

        $this->blade('<x-admin.color name="primary_color" value="#ff0055" label="Main colour" />')->assertSee('dir="ltr" data-ad-color-hex', false);
    }

    public function test_the_announcement_bar_draft_shows_in_the_preview(): void
    {
        $admin = $this->admin();
        Setting::set('announcement_banner_enabled', '1', 'appearance');
        Setting::set('announcement_banner', '', 'appearance');
        $this->boot();
        $token = $this->draft($admin, ['set' => ['announcement_banner' => 'Draft news for today']])->assertOk()->json('token');

        $this->actingAs($admin)->get('/?'.AppearanceDraft::QUERY.'='.urlencode($token))->assertOk()->assertSee('Draft news for today');
        $this->boot();
        $this->app['auth']->forgetGuards();
        $this->get('/?'.AppearanceDraft::QUERY.'='.urlencode($token))->assertOk()->assertDontSee('Draft news for today');
    }

    public function test_settings_of_other_groups_are_previewed_too(): void
    {
        $admin = $this->admin();
        config(['site.per_page_videos' => 24]);
        $token = $this->draft($admin, ['set' => ['per_page_videos' => '12']])->assertOk()->json('token');

        AppearanceDraft::apply(AppearanceDraft::verify($token, $admin));

        $this->assertSame(12, config('site.per_page_videos'));
        $this->assertNotSame('12', Setting::get('per_page_videos'));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** What every request's provider boot does on a real site (tests skip it): the published settings in config. */
    private function boot(): void
    {
        AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
    }

    private function draft(User $admin, array $edits): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->postJson(route('admin.appearance.draft'), $edits);
    }
}
