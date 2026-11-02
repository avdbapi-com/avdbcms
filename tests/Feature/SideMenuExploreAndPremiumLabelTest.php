<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\NavigationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 2a.
 *
 * R2A-024: R1-036 made the side menu's headings flex-end (a "See all" on a line of its own ends that line); the plain
 * "Explore" heading had no title box, so its text went to the end of its line (141px from the start at 1366, mirrored
 * in Arabic) while "Playlists (4)" and "Subscriptions (21)" start at the start. It has the headings' title box now.
 *
 * R2A-043: the side menu's Premium label was __(siteLabel('{site_name} Premium')): the site's name went in before the
 * lookup, so the key ("Sandbox Site Premium") never existed and every language showed the English label. The label is
 * translated first (the default is the translated ":site Premium") and the name put in afterwards.
 */
class SideMenuExploreAndPremiumLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        parent::tearDown();
    }

    public function test_the_explore_heading_has_the_headings_title_box(): void
    {
        config(['site.sidebar_explore_enabled' => true]);
        Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'in_menu' => true]);
        Cache::forget('categories');

        foreach ([null, 'ar'] as $locale) {
            if ($locale) {
                $_SERVER['DETECTED_LOCALE'] = $locale;
            }
            $menu = $this->between($this->get(route('pages.trend'))->assertOk()->getContent(), 'avd-sidebar-section--explore', '</ul>');

            $this->assertMatchesRegularExpression('#<div class="avd-sidebar-heading"><span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">'.preg_quote(e(__('Explore')), '#').'</span></span></div>#', $menu, $locale ?? 'en');
            unset($_SERVER['DETECTED_LOCALE']);
        }

        // an owner's own title the same way
        config(['site.sidebar_explore_title' => 'Browse the shelves']);
        $menu = $this->between($this->get(route('pages.trend'))->assertOk()->getContent(), 'avd-sidebar-section--explore', '</ul>');
        $this->assertStringContainsString('<span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">Browse the shelves</span></span>', $menu);

        // no heading of the side menu is bare text any more: every one starts with its title box
        $html = $this->get(route('pages.trend'))->getContent();
        preg_match_all('#<div class="avd-sidebar-heading">(.{0,60})#s', $html, $headings);
        $this->assertNotEmpty($headings[1]);
        foreach ($headings[1] as $start) {
            $this->assertStringStartsWith('<span class="avd-sidebar-heading__title">', ltrim($start), $start);
        }
    }

    public function test_the_premium_link_reads_in_the_readers_language(): void
    {
        config(['app.name' => 'Sandbox Site', 'site.sidebar_premium_link_enabled' => true, 'site.sidebar_premium_label' => '{site_name} Premium']);

        // English as before (first: a language page keeps its language in the session)
        $en = $this->premiumItem($this->get(route('pages.trend'))->assertOk()->getContent());
        $this->assertStringContainsString('Sandbox Site Premium', $en, 'English as before');

        $_SERVER['DETECTED_LOCALE'] = 'ru';
        $ru = $this->premiumItem($this->get(route('pages.trend'))->assertOk()->getContent());
        $this->assertStringContainsString('Sandbox Site Премиум', $ru, 'Russian: the translated ":site Premium"');
        $this->assertStringNotContainsString('Sandbox Site Premium', $ru);

        $_SERVER['DETECTED_LOCALE'] = 'ar';
        $this->assertStringContainsString(e('Sandbox Site بريميوم'), $this->premiumItem($this->get(route('pages.trend'))->assertOk()->getContent()), 'Arabic');

        $_SERVER['DETECTED_LOCALE'] = 'de';
        $this->assertStringContainsString('Sandbox Site Premium', $this->premiumItem($this->get(route('pages.trend'))->assertOk()->getContent()), 'German (the same word)');

        // a member without Premium too (Russian)
        $_SERVER['DETECTED_LOCALE'] = 'ru';
        $member = User::factory()->create(['language' => 'ru']);
        $this->assertStringContainsString('Sandbox Site Премиум', $this->premiumItem($this->actingAs($member)->get(route('pages.trend'))->assertOk()->getContent()));
    }

    public function test_an_owners_own_premium_label_reads_as_typed_with_the_site_name(): void
    {
        config(['app.name' => 'Sandbox Site', 'site.sidebar_premium_link_enabled' => true, 'site.sidebar_premium_label' => 'Join {site_name} Plus']);

        $_SERVER['DETECTED_LOCALE'] = 'ru';
        $item = $this->premiumItem($this->get(route('pages.trend'))->assertOk()->getContent());

        $this->assertStringContainsString('Join Sandbox Site Plus', $item);
        $this->assertStringNotContainsString('{site_name}', $item);
        $this->assertStringNotContainsString(':site', $item);
    }

    public function test_the_label_helper_translates_before_the_site_name_goes_in(): void
    {
        config(['app.name' => 'Ünïcode & <Site>']);

        app()->setLocale('ru');
        $this->assertSame('Ünïcode & <Site> Премиум', NavigationSettings::translatedSiteLabel('{site_name} Premium'));
        $this->assertSame('Премиум', NavigationSettings::translatedSiteLabel('Premium'));
        $this->assertSame('Go Ünïcode & <Site> now', NavigationSettings::translatedSiteLabel('Go {site_name} now'));

        app()->setLocale('en');
        $this->assertSame('Ünïcode & <Site> Premium', NavigationSettings::translatedSiteLabel('{site_name} Premium'));
        // the old helper is unchanged for its other readers
        $this->assertSame('Ünïcode & <Site> Premium', NavigationSettings::siteLabel('{site_name} Premium'));
    }

    private function premiumItem(string $html): string
    {
        return $this->between($html, 'avd-sidebar-section--premium', '</ul>');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'missing: '.$from);
        $end = strpos($html, $to, (int) $start);

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
    }
}
