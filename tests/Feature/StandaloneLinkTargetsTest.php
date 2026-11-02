<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (R3-061, member design 4.3 / 8: nothing interactive under 24 x 24px, 44 x 44px on touch screens): the
 * About tab's website link was 22.5px tall, the guest Subscriptions page's "Discover channels" 18px, the Discover cards'
 * and Manage rows' names 23px (with a mouse; round 1's R1-023 gave them 44px on touch screens only), and the reset
 * form's "Not you?" 16px (13px in Magazine). With a mouse they now take at least 25px, on touch screens 44px, and the
 * page does not move (padding with an equal negative margin; "Not you?" grows downward, with room under its line on
 * touch screens). The rows' check boxes (20px labels) take clicks from a 24px band. Browser proof: lane FE's
 * target_probe.js, about_link.js and layout_diff.js.
 */
class StandaloneLinkTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_you_on_the_reset_form_is_a_standalone_target(): void
    {
        $html = $this->get(route('password.reset', ['token' => 'r3-061-token']).'?email='.urlencode('someone@example.test'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<span class="mk-help mk-help--link" id="email-help">[^<]*<a href="[^"]+">'.preg_quote(e(__('Not you?')), '#').'</a></span>#', $html);

        $scss = (string) file_get_contents(resource_path('sass/member/_auth.scss'));
        // 5px up (the read-only field is 6-9px above), the rest down: content + 12px with a mouse, + 31px on touch
        $this->assertMatchesRegularExpression('/:root \.mk-auth \.mk-help--link > a \{\s*padding-block: 5px calc\(20px - 1em\);\s*\}/', $scss);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{\s*:root \.mk-auth \.mk-help--link \{\s*margin-block-end: \.75rem;\s*\}\s*:root \.mk-auth \.mk-help--link > a \{\s*padding-block: 4px calc\(40px - 1em\);\s*\}\s*\}/', $scss);
    }

    public function test_list_names_quiet_links_and_details_links_reach_24px_with_a_mouse(): void
    {
        $kit = (string) file_get_contents(resource_path('sass/member/_kit.scss'));

        // with every pointer: at least 25px for any font whose content box is 1em; clamped titles keep their thumbnail
        // as the bigger target (padding would show their hidden lines)
        $this->assertMatchesRegularExpression(
            '/:root :is\(\.mk-item__title, \.mk-quiet-link, \.mk-item__ref, \.mk-crumbs a, \.mk-dl dd > a\):where\(:not\(\.mk-clamp-1, \.mk-clamp-2, \.mk-clamp-3\)\) \{\s*--mk-touch-ext: max\(\.1rem, calc\(12\.5px - \.5em\)\);\s*padding-block: var\(--mk-touch-ext\);\s*margin-block: calc\(-1 \* var\(--mk-touch-ext\)\);\s*\}/',
            $kit,
        );
        // on touch screens round 1's 44px rule, now with the details list's links (the About tab's website)
        $this->assertMatchesRegularExpression(
            '/:root :is\(\.mk-item__title, \.mk-quiet-link, \.mk-item__ref, \.mk-crumbs a, \.mk-dl dd > a\) \{\s*--mk-touch-ext: max\(\.9rem, calc\(22px - \.45em\)\);\s*padding-block: var\(--mk-touch-ext\);\s*margin-block: calc\(-1 \* var\(--mk-touch-ext\)\);\s*\}/',
            $kit,
        );
        // the base rule comes before the touch one (same weight: the touch value wins on touch screens)
        $this->assertLessThan(strpos($kit, '--mk-touch-ext: max(.9rem'), strpos($kit, '--mk-touch-ext: max(.1rem'));
        // a row's check box: a 24px band with a mouse (its label is 20px tall)
        $this->assertMatchesRegularExpression('/@media \(pointer: fine\) \{\s*:root \.mk-row__check::after,\s*:root \.mk-item__check::after \{\s*content: "";\s*position: absolute;\s*inset: min\(0px, calc\(50% - 12px\)\);\s*\}\s*\}/', $kit);
    }

    public function test_the_links_named_by_the_finding_carry_those_classes(): void
    {
        // the About tab's website (a details list's link), the guest feed's "Discover channels" (a quiet link), the
        // Discover cards' and Manage rows' names (item titles), each by the start of its tag (its class, then its
        // address); other attributes may follow the address, as the names' own direction on pages of the other
        // direction does (R3-015 / R3-060: TextDirection::attr(), asserted by CreatorTextDirectionTest)
        $about = (string) file_get_contents(resource_path('views/library/channel-about.blade.php'));
        $this->assertMatchesRegularExpression('#<dl class="mk-dl mk-channel__details">.*<dd><a href="\{\{ \$details\[\'website\'\] \}\}"#s', $about);
        $this->assertStringContainsString('<a class="mk-quiet-link" href="{{ route(\'subscription.discover\') }}"', (string) file_get_contents(resource_path('views/subscription/index.blade.php')));
        $this->assertStringContainsString('<a class="mk-item__title mk-chan-card__name"', (string) file_get_contents(resource_path('views/subscription/partials/user.blade.php')));
        $this->assertStringContainsString('<a class="mk-item__title" href="{{ $channelUser->route }}"', (string) file_get_contents(resource_path('views/subscription/manage.blade.php')));
    }
}
