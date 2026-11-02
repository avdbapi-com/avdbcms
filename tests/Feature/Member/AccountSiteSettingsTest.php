<?php

namespace Tests\Feature\Member;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two site settings of member design 6.0 marked (T) that the account pages honour (9.4: "the settings of 6.0 that the
 * pages honour are covered by tests"):
 * - enhanced_selects_enabled ("Searchable drop-down lists", 5.9): on Account settings > Profile the Country list is the
 *   one searchable list while the setting is on (the select script turns every select.form-select without
 *   data-native-select into a TomSelect); Language and the phone's country code stay native; the "Type to search the
 *   list." help shows only while it is on, and with it off the page tells the script so (html[data-avd-enhanced-selects],
 *   AVD_SETTINGS.enhancedSelects): every list is native;
 * - modal_presentation_style ("Pop-up window style", 5.11, one test per style): the account pages' dialogs (the picture window
 *   of Profile and Channel, the "Sign out all other devices" and Delete account confirmations, the Welcome to Premium
 *   sheet) are the kit's dialogs in Bootstrap modals, with no size or style of their own, so the style's rules
 *   (html[data-avd-modal-style], the site's and the kit's) shape them like every pop-up of the site.
 */
class AccountSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['site.default_locale' => 'en', 'app.locale' => 'en']);
        $this->withoutVite();
    }

    public function test_the_country_list_is_the_one_searchable_list_and_follows_the_setting(): void
    {
        // text messages can be sent, so the phone number and its country code are on the page (6.17)
        config(['services.brevo.api_key' => 'xkeysib-test', 'site.allow_language_switch' => true]);
        $member = User::factory()->create(['country' => 'DE']);

        foreach (['on' => true, 'off' => false] as $printed => $enabled) {
            config(['site.enhanced_selects_enabled' => $enabled]);
            $html = (string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();

            // the page tells the select script whether lists may be searchable
            $this->assertMatchesRegularExpression('#<html\s[^>]*data-avd-enhanced-selects="'.$printed.'"#', $html, $printed);
            $this->assertStringContainsString('"enhancedSelects":'.($enabled ? 'true' : 'false'), $html, $printed);

            $selects = $this->selects($html);
            $this->assertSame(['country', 'phone-code', 'language'], array_keys($selects), $printed.': the lists of the Profile section');

            // Country: the kind of list the script makes searchable (5.9)
            $this->assertStringContainsString(' class="form-select"', $selects['country'], $printed);
            $this->assertStringNotContainsString('data-native-select', $selects['country'], $printed.': Country is the searchable list');
            // Language and the country code of the phone number stay native
            $this->assertStringContainsString(' data-native-select', $selects['language'], $printed.': Language stays native');
            $this->assertStringContainsString(' data-native-select', $selects['phone-code'], $printed.': the country code stays native');

            // the help line says the list can be searched only while it can
            $this->assertSame($enabled ? 1 : 0, substr_count($html, '<span class="mk-help">'.e(__('Type to search the list.')).'</span>'), $printed);
        }
    }

    /** Channel > Channel look: its short lists (header, motion, featured video, sizes) stay native whatever the setting. */
    public function test_the_channel_look_lists_stay_native_with_searchable_lists_on_and_off(): void
    {
        $member = User::factory()->create();
        $script = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];

        foreach (['on' => true, 'off' => false] as $printed => $enabled) {
            config(['site.enhanced_selects_enabled' => $enabled]);
            $page = (string) $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
            $tags = $this->selects($page);

            // the closed groups come when they are opened (4.3): each group's own lists too
            foreach (['colours', 'header', 'motion', 'sections', 'sizes'] as $group) {
                $fragment = (string) $this->actingAs($member)->withHeaders($script)
                    ->get(route('user.edit', ['section' => 'channel', 'group' => $group]))->assertOk()->getContent();
                $tags += $this->selects($fragment, false);
            }

            $this->assertGreaterThanOrEqual(4, count($tags), $printed.': the lists of Channel look');
            foreach ($tags as $id => $tag) {
                $this->assertStringContainsString(' data-native-select', $tag, $printed.': '.$id.' stays native');
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function popUpWindowStyles(): array
    {
        return [
            'centred' => ['modal_glassmorphic_center'],
            'sliding sheet' => ['modal_slide_sheet'],
            'full screen' => ['modal_fullscreen_cinema'],
            'sharp' => ['modal_brutalist_sharp'],
        ];
    }

    #[DataProvider('popUpWindowStyles')]
    public function test_the_lanes_dialogs_are_kit_dialogs_in_every_pop_up_window_style(string $style): void
    {
        config(['site.modal_presentation_style' => $style, 'site.allow_account_deletion' => true]);
        $member = User::factory()->create();

        $pages = [
            'the picture window of Profile' => [route('user.edit'), 'crop', 'mk-confirm mk-crop-dialog'],
            'the picture window of Channel' => [route('user.edit', ['section' => 'channel']), 'crop', 'mk-confirm mk-crop-dialog'],
            'Sign out all other devices' => [route('user.edit', ['section' => 'security']), 'mk-signout-dialog', 'mk-confirm'],
            'Delete account' => [route('user.edit', ['section' => 'delete']), 'mk-delete-dialog', 'mk-confirm'],
        ];

        foreach ($pages as $label => [$url, $id, $kind]) {
            $html = (string) $this->actingAs($member)->get($url)->assertOk()->getContent();
            $this->assertKitDialog($html, $style, $id, $kind, $label);
        }

        // Welcome to Premium: back from a paid checkout, with Premium on
        $plan = Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500]);
        Subscription::factory()->for($member)->for($plan)->create(['stripe_status' => 'active', 'ends_at' => null]);
        $welcome = (string) $this->actingAs($member->fresh())->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();
        $this->assertKitDialog($welcome, $style, 'premium', 'mk-prompt', 'Welcome to Premium');
    }

    /**
     * The page prints the style, and the dialog `$id` is a Bootstrap modal whose first child is the kit dialog
     * (.modal-dialog.modal-dialog-centered.mk-dialog + its kind), with the kit's close button and nothing that would size
     * or style it on its own (a full-screen or size class, Bootstrap's white X).
     */
    private function assertKitDialog(string $html, string $style, string $id, string $kind, string $label): void
    {
        $this->assertMatchesRegularExpression('#<html\s[^>]*data-avd-modal-style="'.preg_quote($style, '#').'"#', $html, $label);

        $at = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($at, $label.': the dialog is on the page');
        $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), $label.': once');

        // the element that carries the id: a modal (its opening tag may hold an Alpine expression with "=>")
        $open = strrpos(substr($html, 0, (int) $at), '<div ');
        $this->assertNotFalse($open);
        $this->assertStringStartsWith('<div class="modal fade"', substr($html, (int) $open, 30), $label);

        // its first child: the kit dialog, centred markup; the style's rules decide sheet, full screen or sharp corners
        $this->assertSame(1, preg_match('#<div class="([^"]*)">#', $html, $child, 0, (int) $at), $label);
        $this->assertSame('modal-dialog modal-dialog-centered mk-dialog '.$kind, $child[1], $label);

        // the dialog itself (the modal element, up to its own closing tag): the kit's close button, and no size or style
        // of its own
        $dialog = $this->element($html, (int) $open);
        $this->assertStringContainsString('class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal"', $dialog, $label.': the kit close button');
        foreach (['modal-fullscreen', 'modal-lg', 'modal-xl', 'modal-sm', 'btn-close'] as $own) {
            $this->assertStringNotContainsString($own, $dialog, $label.': no '.$own);
        }
    }

    /** The <div> element that opens at `$open`, up to its own closing tag (the nested divs counted). */
    private function element(string $html, int $open): string
    {
        $this->assertGreaterThan(0, preg_match_all('#<div\b|</div>#', $html, $tags, PREG_OFFSET_CAPTURE, $open));
        $depth = 0;
        foreach ($tags[0] as [$tag, $offset]) {
            $depth += $tag === '</div>' ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $open, $offset + strlen('</div>') - $open);
            }
        }
        $this->fail('the element at '.$open.' is not closed');
    }

    /**
     * The opening tags of the selects in the page root (the .mk-page of #main-container), or in the whole of an HTML
     * fragment, by id, in page order.
     *
     * @return array<string, string>
     */
    private function selects(string $html, bool $pageRoot = true): array
    {
        $root = $html;
        if ($pageRoot) {
            $start = strpos($html, 'class="avd-mk mk-page');
            $this->assertNotFalse($start, 'the page root');
            // the page's own part: the layout prints the upload window and the account panel (with its native Language
            // list) after it, still inside <main>
            $ends = array_filter([
                strpos($html, '</main>', (int) $start),
                strpos($html, 'id="video_create"', (int) $start),
                strpos($html, 'id="account" aria-labelledby="account-title"', (int) $start),
            ], fn ($at) => $at !== false);
            $end = $ends === [] ? false : min($ends);
            $root = substr($html, (int) $start, $end === false ? null : $end - (int) $start);
        }

        $found = [];
        $offset = 0;
        while (($at = strpos($root, '<select', $offset)) !== false) {
            // the tag ends at the first ">" outside a quoted attribute (Alpine expressions hold "=>")
            $quote = null;
            $length = strlen($root);
            for ($i = $at; $i < $length; $i++) {
                $char = $root[$i];
                if ($quote !== null) {
                    $quote = $char === $quote ? null : $quote;
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            }
            $tag = substr($root, $at, $i - $at + 1);
            $this->assertSame(1, preg_match('/\sid="([^"]+)"/', $tag, $id), 'every list has an id: '.$tag);
            $found[$id[1]] = $tag;
            $offset = $i + 1;
        }

        return $found;
    }
}
