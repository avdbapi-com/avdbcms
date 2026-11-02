<?php

namespace Tests\Feature\Member;

use App\Models\User;
use App\Notifications\Account\PasswordUpdate;
use App\Support\AvdbCreatorAvatarFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The smaller parts of Account settings: mails in the member's language (account-39), the neutral generated
 * avatar (account-35 = watch-43), the picture dialog that says what the server says (account-21), translated and
 * escaped messages (account-22), the password check's own message under its field, "Preview channel" that never wraps
 * (account-19), the admin's invoice settings (account-1) and the Info pages help text.
 */
class AccountDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'app.locale' => 'en']);
        $this->withoutVite();
    }

    public function test_mails_to_a_member_use_the_language_they_chose(): void
    {
        Notification::fake();
        $french = User::factory()->create(['language' => 'fr']);
        $unknown = User::factory()->create(['language' => 'xx']);

        $this->assertInstanceOf(HasLocalePreference::class, $french);
        $this->assertSame('fr', $french->preferredLocale());
        $this->assertSame('en', $unknown->preferredLocale(), 'a language the site does not have: the site language');

        $french->notify(new PasswordUpdate());
        Notification::assertSentTo($french, PasswordUpdate::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'fr');
    }

    public function test_a_member_gets_a_neutral_generated_avatar_without_a_label(): void
    {
        Storage::fake(config('filesystems.default'));
        $member = User::factory()->create(['username' => 'nightdrive_tv', 'avatar' => null]);

        $filename = AvdbCreatorAvatarFactory::ensureForUser($member);

        $this->assertNotNull($filename);
        $svg = (string) Storage::get(User::AVATAR_FOLDER.'/'.$filename);
        $this->assertStringContainsString('data-avdb-avatar-style="member-neutral"', $svg);
        $this->assertStringContainsString('>NT</text>', $svg, 'the initials of the two words');
        $this->assertStringNotContainsStringIgnoringCase('creator', $svg, 'account-35: no "CREATOR" label');
        $this->assertStringNotContainsString('<animate', $svg);
    }

    public function test_an_older_generated_avatar_is_replaced_and_an_uploaded_picture_is_kept(): void
    {
        Storage::fake(config('filesystems.default'));
        $member = User::factory()->create(['username' => 'old_badge', 'avatar' => null]);
        $old = 'avdb-creator-0123456789abcdef01234567.svg';
        Storage::put(User::AVATAR_FOLDER.'/'.$old, '<svg><text>CREATOR</text></svg>');
        $member->forceFill(['avatar' => $old])->saveQuietly();

        $new = AvdbCreatorAvatarFactory::ensureForUser($member->fresh());
        $this->assertNotSame($old, $new);
        $this->assertSame($new, $member->fresh()->avatar);

        $uploaded = User::factory()->create(['username' => 'has_photo', 'avatar' => null]);
        Storage::put(User::AVATAR_FOLDER.'/my-photo.jpg', 'jpeg');
        $uploaded->forceFill(['avatar' => 'my-photo.jpg'])->saveQuietly();
        $this->assertSame('my-photo.jpg', AvdbCreatorAvatarFactory::ensureForUser($uploaded->fresh()));
    }

    public function test_the_picture_dialog_carries_the_sites_limits_and_complete_messages(): void
    {
        config(['site.max_avatar_size' => 2048, 'site.max_banner_size' => 5120]);
        $member = User::factory()->create();

        $html = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('id="crop"', $html);
        $this->assertStringContainsString('data-limits="{&quot;avatar&quot;:2048,&quot;banner&quot;:5120', $html, 'account-21: the server limits');
        $this->assertStringContainsString(e(__('This file type cannot be used ({type}). Use {types}.')), $html);
        $this->assertStringContainsString(e(__('This picture is too large ({size} MB). Use one of {max} MB or less.')), $html);
        $this->assertStringContainsString('mk-dialog mk-confirm mk-crop-dialog', $html);

        // a picture over the limit is refused by the server with the same words
        $this->actingAs($member)->put(route('user.update'), [
            'section' => 'profile',
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('big.jpg', 400, 400)->size(3000),
        ])->assertSessionHasErrors(['avatar' => __('This picture is too large. Use one of :size MB or less.', ['size' => '2'])]);
    }

    public function test_saved_messages_are_translated_and_printed_as_text(): void
    {
        $member = User::factory()->create();

        $html = $this->actingAs($member)
            ->withSession(['status' => '<img src=x onerror=alert(1)> saved'])
            ->get(route('user.edit'))->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html, 'account-22: escaped');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; saved', $html);

        // the save itself says it in the page's language
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'country' => 'FR'])
            ->assertSessionHas('status', __('Your profile is saved.'));
    }

    public function test_a_wrong_password_comes_back_under_its_field_in_plain_words(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->from(route('user.edit', ['section' => 'security']))
            ->post(route('user.update.password'), ['current_password' => 'not-it', 'new_password' => 'another-password-1', 'new_password_confirmation' => 'another-password-1'])
            ->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHasErrors(['current_password' => __('That password is wrong. Try again.')]);

        $this->actingAs($member)->post(route('user.update.password'), ['new_password' => 'x'])
            ->assertSessionHasErrors(['current_password' => __('Enter your password.')]);

        // a request that wants JSON gets JSON
        $this->actingAs($member)->postJson(route('user.update.password'), ['current_password' => 'not-it'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_preview_channel_never_wraps_and_the_banner_has_a_token_placeholder(): void
    {
        $member = User::factory()->create(['banner' => null]);

        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="[^"]*mk-nowrap[^"]*mk-panel__action"[^>]*>\s*<i[^>]*><\/i>\s*<span>'.preg_quote(e(__('Preview channel')), '/').'/', $html, 'account-19');
        $this->assertStringContainsString('mk-banner-edit__empty', $html, 'account-35: no stock banner');
    }

    public function test_a_section_is_printed_without_template_indentation_and_typed_text_is_kept(): void
    {
        $html = "<div>\n        <p>a</p>\n    <textarea name=\"d\">  line one\n    line two</textarea>\n  <pre>  x\n   y</pre>\n</div>";

        $this->assertSame(
            "<div>\n<p>a</p>\n<textarea name=\"d\">  line one\n    line two</textarea>\n<pre>  x\n   y</pre>\n</div>",
            \App\Http\Controllers\User\ProfileController::compactHtml($html)
        );

        // a description with indented lines comes back exactly as typed
        $member = User::factory()->create(['description' => "First line\n    indented second line"]);
        $page = $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString(e("First line\n    indented second line").'</textarea>', $page);
    }

    public function test_closed_channel_groups_load_when_opened_and_a_refused_save_keeps_every_group_in_the_page(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['channel_customization' => ['featured_columns' => '5']])->save();

        // the page: the first group open, the others only their title and current choice (page-content budget, 4.3)
        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $groups = substr_count($html, '<details class="mk-group-block"');
        $this->assertSame(5, $groups, 'Colours, Header, Motion, Sections, Sizes');
        $this->assertSame(1, substr_count($html, '<details class="mk-group-block" open>'));
        $this->assertSame(0, substr_count($html, '<template>'));
        $this->assertStringContainsString('<details class="mk-group-block" data-group="sizes">', $html);
        $this->assertStringNotContainsString('id="cc-featured_columns"', $html);
        $this->assertStringContainsString('data-group-src="'.e(\App\Http\Controllers\User\ProfileController::sectionUrl('channel')).'"', $html);
        $this->assertStringContainsString('@toggle.capture="avdChannelGroup($event.target, $el)"', $html);

        // the group the page asks for: its fields with the stored values, nothing else, never cached
        $fragment = $this->actingAs($member)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'sizes']))
            ->assertOk()
            ->assertHeader('X-Mk-Fragment', 'channel-group');
        $this->assertStringContainsString('no-store', (string) $fragment->headers->get('Cache-Control'));
        $body = (string) $fragment->getContent();
        $this->assertStringStartsWith('<div class="mk-form">', $body);
        $this->assertMatchesRegularExpression('/id="cc-featured_columns"[^>]*value="5"/', $body);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('mk-page', $body);
        $this->assertStringNotContainsString('mk-channel-form', $body);
        $this->assertStringNotContainsString("\n    ", $body, 'printed without the template indentation');

        // an unknown group, and a group whose options the admin switched off, are not there
        $ajax = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];
        $this->actingAs($member)->withHeaders($ajax)->get(route('user.edit', ['section' => 'channel', 'group' => 'nope']))->assertNotFound();
        config(['site.channel_customization_motion_enabled' => false]);
        $this->actingAs($member)->withHeaders($ajax)->get(route('user.edit', ['section' => 'channel', 'group' => 'motion']))->assertNotFound();
        config(['site.channel_customization_motion_enabled' => true]);

        // a normal visit with ?group= opens that group in the page (the script's headers are not sent again)
        $this->flushHeaders();
        $opened = $this->actingAs($member)->get(route('user.edit', ['section' => 'channel', 'group' => 'sizes']))->assertOk()->getContent();
        $this->assertSame(2, substr_count($opened, '<details class="mk-group-block" open>'));
        $this->assertStringContainsString('id="cc-featured_columns"', $opened);
        $this->assertStringContainsString('class="avd-mk mk-page', $opened);

        // a refused save: the Sizes group opens with the message under its field, the other closed groups keep what was
        // typed in templates (no request can bring old input back later)
        $this->actingAs($member)->from(route('user.edit', ['section' => 'channel']))
            ->put(route('user.update'), ['section' => 'channel', 'channel_customization' => ['featured_columns' => '99', 'motion' => 'pulse']])
            ->assertSessionHasErrors('channel_customization.featured_columns');
        $withError = $this->actingAs($member)
            ->withSession(['errors' => session('errors'), '_old_input' => session('_old_input')])
            ->get(route('user.edit', ['section' => 'channel']))->getContent();
        $this->assertSame($groups - 2, substr_count($withError, '<template>'), 'the first group and the group with the error are open');
        $this->assertStringNotContainsString('data-group-src=', $withError);
        $this->assertDoesNotMatchRegularExpression('/<template>[^<]*(?:<(?!\/template>)[^<]*)*id="cc-featured_columns"/s', $withError);
        $this->assertStringContainsString('id="channel_customization-featured_columns-error"', $withError);
        $this->assertMatchesRegularExpression('/<template>(?:(?!<\/template>).)*<option value="pulse" selected>/s', $withError, 'the typed motion waits in its template');
    }

    /**
     * After a refused save the page sent back only what it had loaded (the Channel page fields and the open Colours
     * group): the groups it never loaded keep the member's stored choices, in their summaries and in their templates,
     * and a save after opening them keeps those choices (before: the site's defaults, saved over the member's).
     */
    public function test_a_refused_channel_save_keeps_the_stored_choices_of_the_groups_it_did_not_send(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['channel_customization' => [
            'motion' => 'pulse', 'show_featured_video' => '0', 'show_playlists' => '0', 'latest_uploads_rows' => '4', 'theme' => 'forest',
        ]])->save();

        // what the page sends: the Channel page fields and the open Colours group, with a website the rule refuses
        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'channel'])
            ->put(route('user.update'), [
                'section' => 'channel',
                'website' => 'not a website !!',
                'description' => 'Typed before the refusal',
                'channel_customization' => ['tagline' => 'Typed tagline', 'theme' => 'ocean', 'accent' => 'coral', 'surface' => 'solid'],
            ])
            ->assertSessionHasErrors('website');

        $page = $this->actingAs($member)
            ->withSession(['errors' => session('errors'), '_old_input' => session('_old_input')])
            ->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();

        // what was typed is kept ...
        $this->assertStringContainsString('value="Typed tagline"', $page);
        $this->assertMatchesRegularExpression('/name="channel_customization\[theme\]" value="ocean" checked/', $page);
        // ... and every group it did not send shows and carries the stored choice, not the site's default
        $this->assertMatchesRegularExpression('/<template>(?:(?!<\/template>).)*<option value="pulse" selected>/s', $page, 'Motion');
        $this->assertStringContainsString('<span class="mk-muted">'.e(__('Pulse')).'</span>', $page, 'the Motion summary');
        $this->assertMatchesRegularExpression('/<template>(?:(?!<\/template>).)*id="cc-latest_uploads_rows"[^>]*value="4"/s', $page, 'Sizes');
        $this->assertDoesNotMatchRegularExpression('/name="channel_customization\[show_featured_video\]" value="1" checked/', $page, 'Featured video stays off');

        // the member opens Motion and Sections (their fields are sent as the templates hold them) and saves
        $this->actingAs($member)->put(route('user.update'), [
            'section' => 'channel',
            'channel_customization' => [
                'tagline' => 'Typed tagline', 'theme' => 'ocean', 'accent' => 'coral', 'surface' => 'solid', 'motion' => 'pulse',
                'show_featured_video' => '0', 'show_latest_uploads' => '1', 'show_playlists' => '0',
            ],
        ])->assertSessionHasNoErrors();

        $stored = $member->fresh()->channel_customization;
        $this->assertSame(['pulse', '0', '0', '4', 'ocean'], [$stored['motion'], $stored['show_featured_video'], $stored['show_playlists'], (string) $stored['latest_uploads_rows'], $stored['theme']]);
    }

    /**
     * The website and the button link take the addresses members really use: capitals, a path, a query, a fragment, no
     * scheme; never another scheme (the website rule refused "https://www.MyChannel.com" and every address with "?").
     */
    public function test_ordinary_web_addresses_are_accepted_and_other_schemes_refused(): void
    {
        $member = User::factory()->create();
        $save = fn (array $input) => $this->actingAs($member)->fromRoute('user.edit', ['section' => 'channel'])
            ->put(route('user.update'), ['section' => 'channel'] + $input);

        foreach ([
            'https://www.MyChannel.com' => 'https://www.MyChannel.com',
            'https://linktr.ee/name?ref=yt' => 'https://linktr.ee/name?ref=yt',
            'HTTPS://Example.com/videos#latest' => 'https://Example.com/videos#latest',
            'mychannel.com' => 'mychannel.com',
            '  shop.example.org/path/page  ' => 'shop.example.org/path/page',
            'example.com:8080/watch' => 'example.com:8080/watch',
            'https://bücher.example/' => 'https://bücher.example/',
        ] as $typed => $kept) {
            $save(['website' => $typed])->assertSessionHasNoErrors();
            $this->assertSame($kept, $member->fresh()->website, $typed);
        }

        foreach (['javascript:alert(1)', 'data:text/html,hi', 'not a website !!', 'https://exa mple.com', 'localhost', 'ftp://example.com/file'] as $typed) {
            $save(['website' => $typed])->assertSessionHasErrors(['website' => __('Use a web address like example.com or https://example.com/page.')]);
        }

        // the creator's button: http(s) only, an address without its scheme gets https:// (the channel page only links those)
        $save(['channel_customization' => ['cta_url' => 'shop.example.org/luts', 'cta_label' => 'Shop']])->assertSessionHasNoErrors();
        $this->assertSame('https://shop.example.org/luts', $member->fresh()->channel_customization['cta_url']);
        foreach (['javascript:alert(1)', 'data:text/html,hi', 'mailto:me@example.org'] as $typed) {
            $save(['channel_customization' => ['cta_url' => $typed]])->assertSessionHasErrors('channel_customization.cta_url');
        }
    }

    /**
     * Words of their own where a shared word meant something else in other languages ("Code" read "video code",
     * "Accent" a speech accent), and addresses, e-mails and numbers that read left to right in Arabic.
     */
    public function test_labels_say_what_they_are_and_addresses_read_left_to_right(): void
    {
        config(['services.brevo.api_key' => 'xkeysib-test']);
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+33612345678'])->save();

        $profile = $this->actingAs($member->fresh())->get(route('user.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="">'.e(__('Country code')).'</option>', $profile);
        $this->assertMatchesRegularExpression('/id="email" type="email"[^>]*dir="ltr"/', $profile);
        $this->assertMatchesRegularExpression('/id="phone" type="tel"[^>]*dir="ltr"/', $profile);

        $channel = $this->actingAs($member)->get(route('user.edit', ['section' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Accent colour')), $channel);
        $this->assertStringNotContainsString('id="accent-label">'.e(__('Accent')).' <', $channel);
        $this->assertMatchesRegularExpression('/id="website"[^>]*dir="ltr"/', $channel);
        $this->assertMatchesRegularExpression('/id="cta_url"[^>]*dir="ltr"/', $channel);

        $header = $this->actingAs($member)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'header']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Frame around your picture')), $header);
        $sizes = $this->actingAs($member)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'sizes']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Featured video layout')), $sizes);
    }

    public function test_a_group_request_of_a_visitor_who_is_not_signed_in_gets_no_fragment(): void
    {
        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get(route('user.edit', ['section' => 'channel', 'group' => 'sizes']));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Mk-Fragment'));
    }

    public function test_the_picture_dialog_follows_the_page_root(): void
    {
        $member = User::factory()->create();

        foreach (['profile' => [], 'channel' => ['section' => 'channel']] as $section => $query) {
            $html = (string) $this->actingAs($member)->get(route('user.edit', $query))->assertOk()->getContent();
            $root = strpos($html, '<div class="avd-mk mk-page');
            $dialog = strpos($html, 'id="crop"');
            $this->assertNotFalse($root, $section);
            $this->assertNotFalse($dialog, $section);
            $dialogStart = strrpos(substr($html, 0, $dialog), '<div');
            $this->assertGreaterThan($root, $dialogStart, $section);

            // the page root is closed before the dialog's tag opens: as many divs open as close between them
            $between = substr($html, $root, $dialogStart - $root);
            $this->assertSame(substr_count($between, '<div'), substr_count($between, '</div>'), $section.': the dialog is outside the page root');
        }
    }

    public function test_the_admin_has_the_invoice_settings_and_the_info_pages_help(): void
    {
        $settings = (array) config('admin_settings.settings');
        foreach (['invoice_seller_name', 'invoice_seller_address', 'invoice_seller_tax_id', 'invoice_tax_label', 'invoice_tax_rate'] as $key) {
            $this->assertArrayHasKey($key, $settings, $key);
            $this->assertSame(['monetization', 'money.setup', 'invoices', 'basic'], [$settings[$key]['group'], $settings[$key]['page'], $settings[$key]['card'], $settings[$key]['level']], $key);
        }
        $this->assertSame('textarea', $settings['invoice_seller_address']['control']);
        $this->assertSame('0', (string) $settings['invoice_tax_rate']['default']);

        $pages = (array) config('admin_settings.pages');
        $this->assertArrayHasKey('invoices', $pages['money.setup']['cards']);
        $this->assertSame('Contact, Terms and similar pages.', $pages['appearance.other_pages']['cards']['info_pages']['description']);
        $this->assertSame('Contact, Terms and similar pages use the theme design.', $settings['secondary_page_design_enabled']['help']);
        $this->assertSame('0', (string) $settings['premium_feature_downloads_enabled']['default'], 'OD-M7 / account-6');

        // the admin search finds them
        $index = json_decode((string) file_get_contents(resource_path('admin/lib/settings-index.json')), true);
        $this->assertContains('invoice_seller_name', array_column($index, 'key'));
        $this->assertContains('invoice_seller_name', (array) config('admin_settings.synonyms.invoice'));
    }
}
