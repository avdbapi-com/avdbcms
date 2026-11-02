<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\ViewOnlyDemo;
use App\Jobs\DeleteUser;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Account settings (member design 6.9): one section per page, saves that come back to their section in the
 * member's language with the message on top and the errors under the fields, "Sign out all other devices", deleting
 * the account (closed at once: account-17), the password change that ends the other sign-ins (account-18) and
 * "Download my data" (OD-M6).
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As on every installed site (AppServiceProvider sets it from the settings): the language of addresses without
        // a language prefix, which App::setLocale() never changes.
        config(['site.default_locale' => 'en', 'app.locale' => 'en']);
    }

    public function test_profile_edit_opens_the_profile_section_and_each_section_has_its_own_page(): void
    {
        $member = User::factory()->create();
        $this->withoutVite();

        $profile = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-account-section="profile"', $profile);
        $this->assertStringContainsString('name="username"', $profile);
        $this->assertStringNotContainsString('name="new_password"', $profile, 'only the open section is in the page');
        $this->assertStringContainsString('aria-current="page"', $profile);

        foreach (['channel' => 'name="description"', 'security' => 'name="new_password"', 'delete' => 'id="mk-delete-dialog"'] as $section => $marker) {
            $html = $this->actingAs($member)->get(route('user.edit', ['section' => $section]))->assertOk()->getContent();
            $this->assertStringContainsString('data-account-section="'.$section.'"', $html, $section);
            $this->assertStringContainsString($marker, $html, $section);
            $this->assertStringNotContainsString('id="mk-profile-form"', $html, $section.' has no profile form');
        }

        // an unknown section opens Profile
        $this->actingAs($member)->get(route('user.edit', ['section' => 'nothing']))->assertOk()->assertSee('data-account-section="profile"', false);
    }

    public function test_a_save_comes_back_to_its_section_in_the_members_language_with_the_message(): void
    {
        $member = User::factory()->create(['country' => null]);

        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'channel'])
            ->put(route('user.update'), ['section' => 'channel', 'description' => 'A calm channel.'])
            ->assertRedirect(route('user.edit', ['section' => 'channel']))
            ->assertSessionHas('status', __('Your channel is saved.'));

        $this->assertSame('A calm channel.', $member->fresh()->description);

        // account-4: in French the redirect goes straight to the French page (no second redirect that eats the message)
        $this->withSession(['app_locale' => 'fr'])->actingAs($member)
            ->put(route('user.update'), ['section' => 'profile', 'country' => 'DE'])
            ->assertRedirect('/fr/profile/edit')
            ->assertSessionHas('status');
    }

    public function test_the_language_saved_in_the_profile_section_is_used_for_the_redirect_and_the_mails(): void
    {
        $member = User::factory()->create(['language' => 'en']);

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'language' => 'fr'])
            ->assertRedirect('/fr/profile/edit')
            ->assertSessionHas('app_locale', 'fr');

        $this->assertSame('fr', $member->fresh()->language);
        $this->assertSame('fr', $member->fresh()->preferredLocale());

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'language' => 'xx'])
            ->assertSessionHasErrors('language');
    }

    public function test_field_errors_are_printed_under_their_fields(): void
    {
        $taken = User::factory()->create(['username' => 'taken_name']);
        $member = User::factory()->create();
        $this->withoutVite();

        $this->actingAs($member)->fromRoute('user.edit')
            ->put(route('user.update'), ['section' => 'profile', 'username' => $taken->username])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors('username');

        $html = $this->actingAs($member)->withSession(['errors' => session('errors')])->get(route('user.edit'))->getContent();
        $this->assertMatchesRegularExpression('/id="username"[^>]*aria-invalid="true"/s', $html);
        $this->assertStringContainsString('<span class="mk-error" id="username-error">', $html);
    }

    public function test_a_new_username_follows_the_sign_up_rule_and_an_older_one_is_kept(): void
    {
        $member = User::factory()->create(['username' => 'old name with spaces']);

        // the member keeps an older name that the rule would not allow ...
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'username' => 'old name with spaces', 'country' => 'FR'])
            ->assertSessionHasNoErrors();
        $this->assertSame('FR', $member->fresh()->country);

        // ... but a new one follows it: letters (any language), numbers, dots, dashes and underscores
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'username' => 'new <name>'])
            ->assertSessionHasErrors('username');
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'username' => 'Nguyễn.van-an_2'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nguyễn.van-an_2', $member->fresh()->username);
    }

    public function test_the_channel_address_follows_the_sites_length_rules(): void
    {
        config(['validation.user.slug.min' => 5, 'validation.user.slug.max' => 12]);
        $member = User::factory()->create();
        $this->withoutVite();

        $html = $this->actingAs($member)->get(route('user.edit'))->getContent();
        $this->assertMatchesRegularExpression('/id="slug"[^>]*minlength="5"[^>]*maxlength="12"/s', $html);
        $this->assertStringContainsString(e(__('Letters, numbers, dots, dashes and underscores, :min to :max characters.', ['min' => 5, 'max' => 12])), $html);

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'slug' => 'abc'])->assertSessionHasErrors('slug');
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'slug' => 'abcdefghijklmn'])->assertSessionHasErrors('slug');
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'slug' => 'GoodName'])->assertSessionHasNoErrors();
    }

    public function test_the_profile_has_no_default_country_shows_when_the_member_joined_and_one_badge(): void
    {
        $member = User::factory()->create(['country' => null, 'created_at' => now()->setDate(2025, 3, 14)]);
        $this->withoutVite();

        $html = $this->actingAs($member)->get(route('user.edit'))->getContent();
        $this->assertStringContainsString(e(__('Joined :date', ['date' => 'March 2025'])), $html, 'account-26');
        $this->assertDoesNotMatchRegularExpression('/<option value="FR"[^>]*selected/', $html, 'account-28: no country is chosen for the member');
        $this->assertMatchesRegularExpression('/<option value="">[^<]+<\/option>/', $html);
        // one standard-account badge (the component may add layout classes such as align-self-start)
        $this->assertMatchesRegularExpression('/class="badge bg-secondary(?: [a-z0-9-]+)* mk-account-badge"/', $html);
        $this->assertStringNotContainsString('Member since', $html);

        // the country names follow the page's language (public/index.php takes /fr off the address and tells the
        // Language middleware through DETECTED_LOCALE, as on a real request to /fr/profile/edit)
        // The page sends the codes of the list (the browser writes their names in the page's language) and the chosen
        // country with its name (page-content budget, 4.3: 250 option elements fewer), the codes as one string of
        // two-letter codes (whole-document budget, 4.3).
        $this->assertMatchesRegularExpression('/window\.avdCountryCodes = "((?:[A-Z]{2})+)";/', $html);
        preg_match('/window\.avdCountryCodes = "((?:[A-Z]{2})+)";/', $html, $codes);
        $this->assertContains('DE', str_split($codes[1], 2));
        $this->assertGreaterThan(200, strlen($codes[1]) / 2, 'every country of the list');
        $this->assertSame(1, substr_count($html, '<option value="">'), 'only the empty choice for a member without a country');
        $member->forceFill(['country' => 'DE'])->save();
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        try {
            $french = $this->actingAs($member->fresh())->get(route('user.edit'))->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
        $this->assertStringContainsString('<option value="DE" selected>Allemagne</option>', $french);
        $this->assertStringNotContainsString('>Germany</option>', $french);
        $this->assertStringContainsString(e(__('Joined :date', ['date' => 'March 2025'])), $html);
    }

    public function test_the_view_only_demo_sees_its_masked_address_in_a_read_only_text_field(): void
    {
        $demo = User::factory()->create();
        $demo->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $this->withoutVite();

        $html = $this->actingAs($demo->fresh())->get(route('user.edit'))->assertOk()->getContent();

        // account-31: a text field (no browser bubble about the masked characters), read-only, never sent
        $this->assertMatchesRegularExpression('/<input class="mk-input" id="email" type="text" value="[^"]*" readonly/', $html);
        $this->assertStringNotContainsString('name="email"', $html);
        $this->assertStringNotContainsString($demo->email, $html, 'the address is masked for the demo');

        // the user name shows as stored (the demo's filter empties every field NAMED like a user name), read-only and
        // not required, so the form reaches the friendly refusal
        $this->assertStringContainsString('<input class="mk-input" id="username" type="text" value="'.e($demo->username).'" readonly', $html);
        $this->assertStringNotContainsString('name="username" required', $html);

        // a refused save comes back with the server's sentence in a focused alert, the stored values and a clean form
        $this->actingAs($demo->fresh())->from(route('user.edit'))->put(route('user.update'), ['section' => 'profile', 'description' => 'changed'])
            ->assertStatus(303)
            ->assertSessionHasErrors('view_only_demo');
        $refusal = $this->actingAs($demo->fresh())->withSession(['errors' => session('errors')])->get(route('user.edit'))->getContent();
        $this->assertStringContainsString('data-view-only-refusal', $refusal);
        $this->assertStringContainsString('x-data="avdAccountSettings(false)"', $refusal);
    }

    public function test_deleting_accounts_switched_off_hides_the_section_and_the_route_answers_403(): void
    {
        config(['site.allow_account_deletion' => false]);
        $member = User::factory()->create();
        $this->withoutVite();

        $html = $this->actingAs($member)->get(route('user.edit'))->getContent();
        $this->assertStringNotContainsString('section=delete', $html);
        $this->actingAs($member)->get(route('user.edit', ['section' => 'delete']))->assertSee('data-account-section="profile"', false);

        $this->actingAs($member)->delete(route('user.delete'), ['current_password' => 'password', 'understand' => '1'])->assertForbidden();
        $this->assertNotSoftDeleted($member);

        // the data download stays reachable: on Sign-in and security
        $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->assertSee(route('user.export'), false);
    }

    public function test_deleting_the_account_closes_it_at_once_and_the_job_removes_the_rest(): void
    {
        Bus::fake([DeleteUser::class]);
        $member = User::factory()->create();
        $member->createToken('phone');

        // a wrong password or a missing tick changes nothing and brings the dialog back
        $this->actingAs($member)->from(route('user.edit', ['section' => 'delete']))
            ->delete(route('user.delete'), ['current_password' => 'wrong-one', 'understand' => '1', '_sheet' => 'delete'])
            ->assertRedirect(route('user.edit', ['section' => 'delete']))
            ->assertSessionHasErrors('current_password');
        $this->actingAs($member)->from(route('user.edit', ['section' => 'delete']))
            ->delete(route('user.delete'), ['current_password' => 'password', '_sheet' => 'delete'])
            ->assertSessionHasErrors('understand');
        $this->assertNotSoftDeleted($member);

        $this->actingAs($member)
            ->delete(route('user.delete'), ['current_password' => 'password', 'understand' => '1', '_sheet' => 'delete'])
            ->assertRedirect(locale_url(route('pages.home')))
            ->assertSessionHas('toast');

        // closed at once (account-17): hidden from every query, no sign-in, no app sign-in, signed out
        $this->assertSoftDeleted($member);
        $this->assertNull(User::query()->find($member->id));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
        $this->assertGuest();
        Bus::assertDispatched(DeleteUser::class, fn (DeleteUser $job) => $job->user->is($member));

        // the queued job removes it for good, also when it finds the account already closed
        Notification::fake();
        (new DeleteUser(User::withTrashed()->findOrFail($member->id)))->handle();
        $this->assertNull(User::withTrashed()->find($member->id));
    }

    public function test_the_delete_section_counts_playlists_without_the_system_ones(): void
    {
        $member = User::factory()->create();
        Playlist::factory()->for($member)->create(['is_deletable' => false, 'title' => 'Watch later']);
        Playlist::factory()->for($member)->create(['is_deletable' => false, 'title' => 'Liked videos']);
        Playlist::factory()->count(2)->for($member)->create(['is_deletable' => true]);
        $this->withoutVite();

        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'delete']))->getContent();
        $this->assertStringContainsString(e(trans_choice('{0} Your Watch later and Liked videos lists|{1} :count playlist, and your Watch later and Liked videos lists|[2,*] :count playlists, and your Watch later and Liked videos lists', 2, ['count' => 2])), $html);
    }

    public function test_a_new_password_is_saved_with_a_new_remember_token_and_ends_the_other_sign_ins(): void
    {
        Notification::fake();
        $member = User::factory()->create(['remember_token' => 'old-remember-token-1234567890']);
        $member->createToken('tablet');

        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'security'])
            ->post(route('user.update.password'), [
                'current_password' => 'password',
                'new_password' => 'a-much-longer-password-42',
                'new_password_confirmation' => 'a-much-longer-password-42',
                'sign_out_others' => '1',
            ])
            ->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHas('status');

        $fresh = $member->fresh();
        $this->assertTrue(Hash::check('a-much-longer-password-42', $fresh->password), 'the new password works (it is never hashed twice)');
        $this->assertNotSame('old-remember-token-1234567890', $fresh->remember_token, 'account-18: the remember token is changed AND saved');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count(), 'the app sign-ins end');
    }

    public function test_sign_out_all_other_devices_needs_the_password_and_ends_every_other_sign_in(): void
    {
        $member = User::factory()->create(['remember_token' => 'remember-before-sign-out-0000']);
        $other = User::factory()->create();
        $member->createToken('old phone');

        // A site that keeps its sessions in the database (SESSION_DRIVER=database): the test's session store and guard
        // are made again on that driver, so the requests, the guard and the assertions share one store.
        $this->assertTrue(Schema::hasTable('sessions'));
        config(['session.driver' => 'database', 'session.connection' => null]);
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        $this->app['auth']->forgetGuards();

        $otherPhone = Str::random(40);
        DB::table('sessions')->insert([
            ['id' => $otherPhone, 'user_id' => $member->id, 'ip_address' => null, 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari/604.1', 'payload' => base64_encode(serialize([])), 'last_activity' => time() - 60],
            ['id' => Str::random(40), 'user_id' => $other->id, 'ip_address' => null, 'user_agent' => 'another member', 'payload' => base64_encode(serialize([])), 'last_activity' => time()],
        ]);

        // the other browser session is listed
        $this->withoutVite();
        $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->assertOk()->assertSee('iPhone'."\u{00A0}".'· Safari', false);

        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'security'])
            ->post(route('user.devices.sign-out-others'), ['current_password' => 'nope', '_sheet' => 'signout'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', $otherPhone)->count(), 'a wrong password ends nothing');

        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'security'])
            ->post(route('user.devices.sign-out-others'), ['current_password' => 'password', '_sheet' => 'signout'])
            ->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHas('status', __('You are signed out on every other device.'));

        $current = $this->app['session.store']->getId();
        $this->assertNotSame('remember-before-sign-out-0000', $member->fresh()->remember_token);
        $this->assertTrue(Hash::check('password', $member->fresh()->password), 'the password still works');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->where('id', '!=', $current)->count(), 'the other browser sessions end');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count(), "another member's session stays");
    }

    public function test_the_security_section_lists_only_the_services_the_site_has_set_up(): void
    {
        $member = User::factory()->create();
        $this->withoutVite();

        // account-13: no keys, nothing linked -> no "Connected accounts"
        config(['services.google.client_id' => '', 'services.google.client_secret' => '', 'services.facebook.client_id' => '', 'services.facebook.client_secret' => '']);
        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->getContent();
        $this->assertStringNotContainsString('/oauth/connect/', $html);

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->getContent();
        $this->assertStringContainsString(route('oauth.connect', ['service' => 'google']), $html);
        $this->assertStringNotContainsString(route('oauth.connect', ['service' => 'facebook']), $html);

        // "Sign in with Google or Facebook" off (allow_social_login): nothing to connect, but a link made earlier can
        // always be removed
        config(['site.allow_social_login' => false]);
        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->getContent();
        $this->assertStringNotContainsString('/oauth/connect/', $html);

        $member->forceFill(['google_id' => 'google-123'])->save();
        $html = $this->actingAs($member->fresh())->get(route('user.edit', ['section' => 'security']))->getContent();
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/oauth/unlink/google"#', $html);
        $this->assertStringNotContainsString('/oauth/connect/', $html);
    }

    public function test_the_devices_list_shows_this_device_and_the_app_sign_ins(): void
    {
        $member = User::factory()->create();
        $member->createToken('Pixel 8');
        $other = User::factory()->create();
        $other->createToken('Not mine');
        $this->withoutVite();

        $html = $this->actingAs($member)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36')
            ->get(route('user.edit', ['section' => 'security']))->getContent();

        $this->assertStringContainsString('Windows'."\u{00A0}".'· Chrome', $html);
        $this->assertStringContainsString(e(__('This device')), $html);
        $this->assertStringContainsString('Pixel 8', $html);
        $this->assertStringNotContainsString('Not mine', $html);
        $this->assertStringContainsString(route('user.devices.sign-out-others'), $html);
    }

    public function test_download_my_data_is_one_json_file_of_the_members_own_data(): void
    {
        $member = User::factory()->create(['username' => 'exporter']);
        $other = User::factory()->create();
        Video::factory()->for($member)->create(['title' => 'My own video']);
        Video::factory()->for($other)->create(['title' => 'Somebody else']);
        Playlist::factory()->for($member)->create(['title' => 'Road trips', 'is_deletable' => true]);
        $member->subscriptions()->attach($other->id, ['subscribe_at' => now()]);

        $response = $this->actingAs($member)->get(route('user.export'))->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $data = json_decode($response->streamedContent(), true);
        $this->assertIsArray($data, 'valid JSON');
        $this->assertSame('exporter', $data['profile']['username']);
        $this->assertSame(['My own video'], array_column($data['videos'], 'title'));
        $this->assertContains('Road trips', array_column($data['playlists'], 'title'));
        $this->assertSame($other->username, $data['subscriptions'][0]['channel']);
        $this->assertArrayHasKey('watch_history', $data);
        $this->assertArrayHasKey('invoices', $data);
        $this->assertArrayNotHasKey('password', $data['profile']);
        $this->assertStringNotContainsString((string) $member->password, $response->streamedContent());

        // the view-only demo cannot download (ViewOnlyDemo closes *.export)
        $member->forceFill([ViewOnlyDemo::COLUMN => true])->save();
        $this->assertNotSame(200, $this->actingAs($member->fresh())->get(route('user.export'))->getStatusCode());
    }

    public function test_this_device_keeps_its_remember_me_with_the_new_token_and_the_new_password_works(): void
    {
        Notification::fake();
        $member = User::factory()->create(['remember_token' => 'token-before-the-change-000']);
        $recaller = \Illuminate\Support\Facades\Auth::guard('web')->getRecallerName();

        // signed in with "remember me": the cookie comes back with the account's new token
        $response = $this->actingAs($member)
            ->withCookie($recaller, $member->id.'|token-before-the-change-000|'.$member->password)
            ->post(route('user.update.password'), [
                'current_password' => 'password',
                'new_password' => 'brand-new-password-77',
                'new_password_confirmation' => 'brand-new-password-77',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $cookie = $response->getCookie($recaller);
        $this->assertNotNull($cookie, 'the remember me cookie is handed again');
        // id | the new token | the guard's fingerprint of the password (as SessionGuard::queueRecallerCookie() makes it)
        $this->assertMatchesRegularExpression('/^'.$fresh->id.'\|'.preg_quote((string) $fresh->remember_token, '/').'\|[0-9a-f$.\/A-Za-z]{20,}$/', (string) $cookie->getValue());
        $this->assertNotSame('token-before-the-change-000', $fresh->remember_token);
        $this->assertTrue(Hash::check('brand-new-password-77', $fresh->password), 'the password is hashed once and works');

        // signed in without "remember me": no cookie is made for it
        $this->defaultCookies = [];
        $this->app['cookie']->flushQueuedCookies(); // one process per request on a real site: the test empties the queue
        $this->actingAs($fresh)
            ->post(route('user.devices.sign-out-others'), ['current_password' => 'brand-new-password-77'])
            ->assertSessionHasNoErrors()
            ->assertCookieMissing($recaller);
        $this->assertTrue(Hash::check('brand-new-password-77', $member->fresh()->password), 'signing out the others keeps the password');
    }

    public function test_a_delete_from_another_client_confirms_with_the_password_alone(): void
    {
        Bus::fake([DeleteUser::class]);
        $member = User::factory()->create();

        // the dialog sends _sheet=delete and needs the tick; an app or an older form sends the password only
        $this->actingAs($member)->delete(route('user.delete'), ['current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(locale_url(route('pages.home')));

        $this->assertSoftDeleted($member);
        Bus::assertDispatched(DeleteUser::class);
    }

    /**
     * The Language list starts on the language the member is reading (the page's address decides it), so a save of
     * other fields keeps the page in that language: a member whose stored language was English, reading /fr/, saved
     * a new user name and found the site in English.
     */
    public function test_the_language_list_starts_on_the_language_of_the_page_and_a_save_keeps_the_page_in_it(): void
    {
        $member = User::factory()->create(['language' => 'en']);
        $this->withoutVite();

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        try {
            $french = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }

        $this->assertMatchesRegularExpression('/<option value="fr" lang="fr" selected>/', $french);
        $this->assertDoesNotMatchRegularExpression('/<option value="en" lang="en" selected>/', $french);

        // the form as the page sends it: a new user name, and the language the list shows
        $this->withSession(['app_locale' => 'fr'])->actingAs($member)
            ->put(route('user.update'), ['section' => 'profile', 'username' => 'renamed_member', 'language' => 'fr'])
            ->assertRedirect('/fr/profile/edit')
            ->assertSessionHas('app_locale', 'fr')
            ->assertSessionHas('status');
        $this->assertSame('renamed_member', $member->fresh()->username);

        // on a page in the site language, a member who chose French sees French in the list until it is changed
        $english = $this->actingAs($member->fresh())->withSession(['app_locale' => 'en'])->get(route('user.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="en" lang="en" selected>/', $english);
    }

    /** A refused new password never reaches the session: Laravel's own redirect wrote it there as old input. */
    public function test_a_refused_new_password_is_never_kept_in_the_session(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->fromRoute('user.edit', ['section' => 'security'])
            ->post(route('user.update.password'), [
                'current_password' => 'password',
                'new_password' => 'Secret-Candidate-123!',
                'new_password_confirmation' => 'Secret-Candidate-124!',
                'sign_out_others' => '1',
                '_sheet' => 'password',
                'username' => $member->email,
            ])
            ->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHasErrors('new_password');

        $old = (array) session('_old_input', []);
        $this->assertSame(['_sheet', 'sign_out_others'], array_keys($old), 'only the form choices come back');
        $this->assertStringNotContainsString('Secret-Candidate', serialize(session()->all()));
        $this->assertTrue(Hash::check('password', $member->fresh()->password), 'nothing changed');

        // a script gets the usual 422
        $this->actingAs($member)->postJson(route('user.update.password'), [
            'current_password' => 'password', 'new_password' => 'short', 'new_password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('new_password');
    }

    /**
     * The fourth e-mail change in an hour: the other fields are saved and the page says so, with a plain warning that
     * the address did not change and when it can (before: "Too many attempts !" alone, and the member thought nothing
     * was saved).
     */
    public function test_too_many_email_changes_save_the_rest_and_say_when_the_address_can_change_again(): void
    {
        Notification::fake();
        $member = User::factory()->create(['country' => null]);
        $address = $member->email;
        foreach (range(1, \App\Support\EmailChangeBudget::MAX) as $mail) {
            $this->assertTrue(\App\Support\EmailChangeBudget::take($member->id));
        }

        $this->actingAs($member)->fromRoute('user.edit')
            ->put(route('user.update'), ['section' => 'profile', 'email' => 'brand-new@example.test', 'country' => 'DE'])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHas('status', __('Your profile is saved.'))
            ->assertSessionMissing('error');

        $warning = (string) session('warning');
        $this->assertMatchesRegularExpression('/^Your e-mail was not changed: you changed it too often\. Try again in (?:[2-9]|[1-5]\d|60) minutes\.$/', $warning);
        $this->assertStringNotContainsString('Too many attempts', $warning);
        $this->assertSame('DE', $member->fresh()->country, 'the other fields are saved');
        $this->assertSame($address, $member->fresh()->email);

        // the page prints both, as text
        $page = $this->actingAs($member->fresh())->get(route('user.edit'))->getContent();
        $this->assertStringContainsString(e(__('Your profile is saved.')), $page);
        $this->assertStringContainsString('mk-alert--warning', $page);
    }

    /**
     * Devices: this device, then the latest other sign-ins, a line that says how many more there are with "Show all",
     * and "Sign out all other devices" for every one of them (the list stopped at 20 without a word, and 20 rows took
     * the section over its page-content budget).
     */
    public function test_a_long_device_list_shows_a_few_says_how_many_more_and_can_show_all(): void
    {
        $member = User::factory()->create();
        foreach (range(1, 25) as $app) {
            $token = $member->createToken('App '.$app);
            Device::query()->whereKey($token->accessToken->getKey())->update(['last_used_at' => now()->subMinutes($app)]);
        }
        $this->withoutVite();

        $html = $this->actingAs($member)->get(route('user.edit', ['section' => 'security']))->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, 'signOut($el,'), 'the four used last');
        $this->assertStringContainsString('>App 1 <', $html);
        $this->assertStringContainsString('>App 4 <', $html);
        $this->assertStringNotContainsString('>App 5 <', $html);
        $this->assertStringContainsString('data-more-sign-ins="21"', $html);
        $this->assertStringContainsString(e(trans_choice('{1} :count more sign-in is not shown here.|[2,*] :count more sign-ins are not shown here.', 21, ['count' => 21])), $html);
        $this->assertStringContainsString(e(locale_url(route('user.edit', ['section' => 'security', 'devices' => 'all']))), $html);
        $this->assertStringContainsString(e(__('“Sign out all other devices” ends every one of them.')), $html);

        $all = $this->actingAs($member)->get(route('user.edit', ['section' => 'security', 'devices' => 'all']))->assertOk()->getContent();
        $this->assertSame(25, substr_count($all, 'signOut($el,'), 'every app sign-in, each with its own Sign out');
        $this->assertStringNotContainsString('data-more-sign-ins=', $all);
    }

    /** A list where text belongs (a hand-made request) gets a message or the Profile section, never an error page. */
    public function test_lists_where_text_belongs_are_refused_without_an_error_page(): void
    {
        Notification::fake();
        config(['services.brevo.api_key' => 'xkeysib-test']);
        $member = User::factory()->create();
        $this->withoutVite();

        $this->actingAs($member)->get('/profile/edit?section[]=security')->assertOk()->assertSee('data-account-section="profile"', false);
        $this->actingAs($member)->get('/profile/edit?section=channel&group[]=sizes')->assertOk();

        $cases = [
            'username' => ['username' => ['x']],
            'phone' => ['phone' => ['0612345678'], 'code' => 'FR'],
            'code' => ['phone' => '0612345678', 'code' => ['FR']],
            'prefix' => ['phone' => '612345678', 'prefix' => ['+33']],
            'website' => ['website' => ['https://example.com']],
            'slug' => ['slug' => ['a-slug']],
            'email' => ['email' => ['a@example.test']],
            'country' => ['country' => ['DE']],
            'language' => ['language' => ['fr']],
            'description' => ['description' => ['x']],
        ];

        foreach ($cases as $field => $input) {
            $response = $this->actingAs($member)->fromRoute('user.edit')->put(route('user.update'), ['section' => 'profile'] + $input);
            $this->assertSame(302, $response->getStatusCode(), $field);
            $response->assertSessionHasErrors($field);
        }

        $this->actingAs($member)->fromRoute('user.edit')->put(route('user.update'), ['section' => ['profile'], 'country' => 'DE'])
            ->assertStatus(302)
            ->assertSessionHasErrors('section');
        $this->assertNull($member->fresh()->getRawOriginal('phone'));
    }

    /**
     * The section column (desktop) and the phone strip stick while the page scrolls (6.9): #main-container is never a
     * scroll container on Account settings, in which a sticky element cannot stick.
     */
    public function test_the_main_column_of_account_settings_is_not_a_scroll_container(): void
    {
        $member = User::factory()->create();
        $this->withoutVite();

        foreach (['profile', 'channel', 'security', 'delete'] as $section) {
            $html = $this->actingAs($member)->get(route('user.edit', ['section' => $section]))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<div id="main-container" class="([^"]*)"/', $html, $section);
            preg_match('/<div id="main-container" class="([^"]*)"/', $html, $main);
            $this->assertStringNotContainsString('overflow-auto', $main[1], $section);
            $this->assertStringContainsString('class="mk-settings__nav"', $html, $section);
        }
    }

    public function test_the_device_list_answers_for_an_app_device_that_is_signed_out(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('Old phone');
        $device = Device::query()->findOrFail($token->accessToken->getKey());

        $this->actingAs($member)->deleteJson(route('devices.delete', $device))->assertNoContent();
        $this->assertNull(Device::query()->find($device->getKey()));
    }
}
