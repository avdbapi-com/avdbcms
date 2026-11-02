<?php

namespace App\Http\Controllers\User;

use App\Events\Account\EmailUpdated;
use App\Events\Account\PasswordUpdated;
use App\Helpers\File;
use App\Http\Middleware\ViewOnlyDemo;
use App\Http\Requests\UpdateUserRequest;
use App\Jobs\DeleteUser;
use App\Models\Playlist;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Services\InvoiceService;
use App\Services\SeoService;
use App\Support\AvdbCreatorAvatarFactory;
use App\Support\BillingFormat;
use App\Support\ChannelCustomizationSettings;
use App\Support\EmailChangeBudget;
use App\Support\OAuthProviders;
use App\Support\PremiumAvailability;
use App\Support\SmsAvailability;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Intl\Countries;
use Throwable;

/**
 * Account settings, /profile/edit (member design 6.9): one server-rendered section per page (?section=: Profile,
 * Channel, Sign-in and security, Notifications, Premium and invoices, Delete account), each one form with its own save
 * bar. A save comes back to its own section, in the member's language (account-4: the locale redirect of the
 * Language middleware used up the one-time message), with the message on top and the errors under the fields.
 */
class ProfileController
{
    /**
     * How many other sign-ins (app sign-ins, other browsers) the Devices list shows before "Show all": the section stays
     * within the page-content budget (member design 4.3).
     */
    private const DEVICES_SHOWN = 4;

    /** The most "Show all" lists (an account with thousands of app sign-ins still gets a page). */
    private const DEVICES_ALL = 100;

    /**
     * Set on a request that ended the member's other sessions (it read the session folder): the named limit
     * "member-sessions" counts these only (App\Providers\RouteServiceProvider, R1B-026).
     */
    public const ENDED_OTHER_SESSIONS = 'member.ended_other_sessions';

    /**
     * The sections of Account settings for this member, in order: key => [label, icon]. Notifications is its own
     * partial (shown once it exists); Premium and invoices when Premium can be bought or the member has a plan or
     * invoices; Delete account while the site lets members delete their account.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sections(User $user): array
    {
        $sections = [
            'profile' => [__('Profile'), 'fa-regular fa-user'],
            'channel' => [__('Channel'), 'fa-solid fa-clapperboard'],
            'security' => [__('Sign-in and security'), 'fa-solid fa-shield-halved'],
        ];

        if (view()->exists('users.profile.parts.notifications')) {
            $sections['notifications'] = [__('Notifications'), 'fa-regular fa-bell'];
        }

        if (PremiumAvailability::canSell()
            || Subscription::query()->where('user_id', $user->getKey())->exists()
            || Transaction::query()->where('user_id', $user->getKey())->exists()) {
            $sections['premium'] = [__('Premium and invoices'), 'fa-regular fa-star'];
        }

        if (config('site.allow_account_deletion', true)) {
            $sections['delete'] = [__('Delete account'), 'fa-regular fa-trash-can'];
        }

        return $sections;
    }

    /**
     * A section's HTML without the indentation of its template lines (about half of its bytes: page-content budget,
     * member design 4.3) and without its empty lines (1.2.3 round 3, R3-086): a run of white space that holds a line
     * break (a template saved with Windows line ends too) becomes one line break, which reads as the same one space.
     * Text inside <textarea> and <pre> is kept exactly as it is. A run is matched from its first character only (the
     * look-behind), so a long run of spaces without a line break costs one pass, also without the PCRE JIT.
     */
    public static function compactHtml(string $html): string
    {
        $compact = preg_replace_callback(
            '#(<textarea\b[^>]*>.*?</textarea>|<pre\b[^>]*>.*?</pre>)|(?<![ \t\r])[ \t\r]*\n\s*#si',
            static fn (array $match): string => ($match[1] ?? '') !== '' ? $match[1] : "\n",
            $html
        );

        return is_string($compact) ? $compact : $html;
    }

    /** The address of a section, in the page's language: /profile/edit (Profile) or /profile/edit?section=channel. */
    public static function sectionUrl(string $section = 'profile'): string
    {
        return locale_url(route('user.edit', $section === 'profile' ? [] : ['section' => $section]));
    }

    public function edit(Request $request): View|Response
    {
        $user = $request->user();
        $sections = self::sections($user);
        // a hand-made address (?section[]=...) opens Profile like any unknown section, never an error
        $section = $request->query('section', 'profile');

        if (! is_string($section) || ! array_key_exists($section, $sections)) {
            $section = 'profile';
        }

        $data = [
            'user' => $user,
            'section' => $section,
            'sections' => $sections,
            'viewOnly' => ViewOnlyDemo::isViewOnlyUser($user),
        ];

        $data += match ($section) {
            'profile' => $this->profileData($user),
            'channel' => $this->channelData($user),
            'security' => $this->securityData($request, $user),
            'premium' => $this->premiumData($user),
            'delete' => $this->deleteData($user),
            default => [],
        };

        // Channel > Channel look: the page fetches a closed group when the member opens it the first time (page-content
        // budget, member design 4.3); on a normal visit ?group= opens that group.
        $group = $section === 'channel' ? $request->query('group') : null;

        if (is_string($group) && $group !== '') {
            if ($request->ajax()) {
                return $this->channelGroup($data, $group);
            }

            $data['openGroup'] = $group;
        }

        return view('users.profile.edit', $data);
    }

    /**
     * One group of Channel > Channel look as an HTML fragment, for the page that opens it: the group's fields with the
     * stored values, never cached. A group this site does not show (an unknown name, every option of it switched off by
     * the admin, channel customization off) is a 404.
     *
     * @param  array<string, mixed>  $data  the Channel section's view data
     */
    private function channelGroup(array $data, string $group): Response
    {
        $html = trim(self::compactHtml(view('users.profile.sections.channel', $data + ['onlyGroup' => $group])->render()));

        abort_if($html === '', 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Vary' => 'X-Requested-With',
            'X-Mk-Fragment' => 'channel-group',
        ]);
    }

    public function update(UpdateUserRequest $request) : RedirectResponse
    {
        $user = $request->user();
        $section = $this->section($request->input('section'));
        $validated = $request->safe()->except([
            'email', 'section', 'code', 'prefix', 'phone', 'avatar', 'banner', 'remove_avatar', 'remove_banner', 'remove_phone',
        ]);

        // Pictures: a new one replaces the old file; "Remove" goes back to the generated avatar / the look's band.
        if ($request->hasFile('avatar')) {
            $validated['avatar'] = File::storeAndDelete($request->file('avatar'), User::AVATAR_FOLDER, $user->avatar);
        } elseif ($request->boolean('remove_avatar') && filled($user->avatar) && ! AvdbCreatorAvatarFactory::isGenerated($user->avatar)) {
            File::deleteIfExist($user->avatar, User::AVATAR_FOLDER);
            $validated['avatar'] = null;
        }

        if ($request->hasFile('banner')) {
            $validated['banner'] = File::storeAndDelete($request->file('banner'), User::BANNER_FOLDER, $user->banner);
        } elseif ($request->boolean('remove_banner') && filled($user->banner)) {
            File::deleteIfExist($user->banner, User::BANNER_FOLDER);
            $validated['banner'] = null;
        }

        if (array_key_exists('channel_customization', $validated) && config('site.channel_customization_enabled', true)) {
            $validated['channel_customization'] = ChannelCustomizationSettings::normalizeForStorage(
                $validated['channel_customization'] ?? [],
                $user->channel_customization ?? []
            );
        } elseif (array_key_exists('channel_customization', $validated)) {
            unset($validated['channel_customization']);
        }

        // The language of the site and of the member's e-mails (Profile > Language and theme).
        $language = $validated['language'] ?? null;
        unset($validated['language']);

        if (is_string($language) && $language !== '') {
            $validated['language'] = $language;
        }

        $user->update($validated);

        if (array_key_exists('avatar', $validated) && $validated['avatar'] === null) {
            AvdbCreatorAvatarFactory::ensureForUser($user);
        }

        // The phone number (6.17): a new number waits for its code; an emptied field or "Remove" clears it.
        $codeSent = false;
        $warnings = [];

        if ($request->safe()->has('phone')) {
            $phone = $request->safe()->input('phone');

            if ($phone === null || $phone === '') {
                if ($user->phone !== null || $user->phone_confirmation_code !== null) {
                    $user->clearPhone();
                }
            } elseif ((string) $user->getRawOriginal('phone') !== (string) $phone) {
                $user->forceFill([
                    'phone' => $phone,
                    'phone_verified_at' => null,
                    'phone_confirmation_code' => null,
                ])->save();

                $codeSent = $user->sendPhoneVerificationNotification();

                if (! $codeSent && $user->phoneCodeSendBlockedSeconds() > 0) {
                    // the hourly limit of codes: the number is saved, its code can be asked for later
                    $warnings[] = $user->phoneCodeLimitMessage();
                }
            }
        }

        if (isset($validated['language'])) {
            try {
                $request->session()->put('app_locale', $validated['language']);
            } catch (Throwable) {
            }

            App::setLocale($validated['language']);
            Carbon::setLocale($validated['language']);
        }

        $emailRefused = false;
        $emailPending = false;

        if ($request->get('email') && $request->get('email') !== $user->email && !$user->getTemporaryEmailForVerification()) {
            // Each change mails the new address: at most 3 per member per hour (shared with "send the link again").
            if (EmailChangeBudget::take($user->id)) {
                EmailUpdated::dispatch($request->get('email'));
                $emailPending = true;
            } else {
                $emailRefused = true;
            }
        }

        $redirect = redirect()->to(self::sectionUrl($section));
        $saved = $section === 'channel' ? __('Your channel is saved.') : __('Your profile is saved.');

        if ($emailPending) {
            $saved .= ' '.__('Confirm your new e-mail with the link we sent to it.');
        }

        if ($emailRefused) {
            // The other fields are saved (the message says so); only the address stays as it was until the hour's
            // budget frees up again, and the member reads when that is.
            $minutes = max(1, (int) ceil(RateLimiter::availableIn(EmailChangeBudget::key($user->id)) / 60));

            $warnings[] = trans_choice(
                '{1} Your e-mail was not changed: you changed it too often. Try again in :count minute.|[2,*] Your e-mail was not changed: you changed it too often. Try again in :count minutes.',
                $minutes,
                ['count' => $minutes]
            );
        }

        if ($warnings !== []) {
            $redirect->with('warning', implode(' ', $warnings));
        }

        if ($codeSent) {
            // the same counted line as "Send a new code" (R2A-040)
            $redirect->with('info', \App\Http\Controllers\Auth\PhoneVerification::codeSentMessage());
        }

        return $redirect->with('status', $saved);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $password = Password::min((int) config('validation.user.password.min', 6));

        if (config('site.require_strong_password', false)) {
            $password = $password->mixedCase()->numbers()->symbols();
        }

        $validator = Validator::make(
            $request->all(),
            ['new_password' => ['required', 'confirmed', $password]],
            [],
            ['new_password' => __('New password')]
        );

        if ($validator->fails()) {
            // Back to the form, as Laravel's own answer does, but never with the typed passwords in the session (that
            // answer would write a refused new password there in plain text as old input): only the form's own choices
            // come back. A script gets the usual 422.
            if ($request->expectsJson()) {
                throw new ValidationException($validator);
            }

            return back()
                ->withErrors($validator)
                ->withInput($request->only(['_sheet', 'sign_out_others']));
        }

        $user = $request->user();
        $new = (string) $request->input('new_password');

        // The new password and a new "remember me" token, saved together (account-18).
        PasswordUpdated::dispatch($new);

        // This device keeps its own "remember me" with the new token.
        $this->keepThisDeviceSignedIn($request, $user);

        $message = __('Your password is changed.');

        if ($request->boolean('sign_out_others')) {
            $this->endOtherSessions($request, $user);
            $message .= ' '.__('Your other devices are signed out.');
        }

        return redirect()->to(self::sectionUrl('security'))->with('status', $message);
    }

    /**
     * "Sign out all other devices" (account-23, account-39), behind the member's password: a new "remember me" token,
     * the app sign-ins and every other browser session end; this device stays signed in.
     */
    public function signOutOtherDevices(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->keepThisDeviceSignedIn($request, $user);
        $this->endOtherSessions($request, $user);

        return redirect()->to(self::sectionUrl('security'))->with('status', __('You are signed out on every other device.'));
    }

    /**
     * Delete the account (account-17): closed at once (signed out everywhere, no sign-in, hidden from every page), the
     * queued job removes the rest. Before, nothing changed until a queue worker ran, and the member could sign in again.
     */
    public function delete(Request $request): RedirectResponse
    {
        if (! config('site.allow_account_deletion', true)) {
            abort(403, __('Deleting accounts is switched off on this site.'));
        }

        // The dialog (_sheet=delete) asks for the password and an "I understand" tick; other clients of this route
        // (apps, older forms) confirm with the password alone, as before.
        $request->validate(
            ['understand' => $request->input('_sheet') === 'delete' ? ['accepted'] : ['nullable']],
            ['understand.accepted' => __('Tick the box to confirm that you understand.')]
        );

        $user = $request->user();

        $user->tokens()->delete();
        $this->endOtherSessions($request, $user);

        // Sign out first (it saves a new "remember me" token on the live account), then close it and queue the rest.
        Auth::guard('web')->logout();
        $user->delete();
        DeleteUser::dispatch($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(locale_url(route('pages.home')))->with('toast', [
            'message' => __('Your account is closed. Everything in it is removed in the next minutes.'),
            'type' => 'success',
        ]);
    }

    /**
     * "Download my data" (OD-M6, account-39): one JSON file with the profile, the channel settings, the videos, the
     * playlists, the comments, the subscriptions, the last 1,000 videos watched and the invoices. Written piece by piece
     * (a large account never fills the memory); the route allows 3 downloads per 10 minutes.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $filename = Str::slug((string) config('app.name')) ?: 'account';
        $filename .= '-'.(Str::slug((string) $user->username) ?: 'member').'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($user): void {
            $this->writeExport($user);
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return string one of UpdateUserRequest::SECTIONS (profile when none or unknown) */
    private function section(mixed $section): string
    {
        return is_string($section) && in_array($section, UpdateUserRequest::SECTIONS, true) ? $section : 'profile';
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(User $user): array
    {
        $countries = self::countryNames();
        $smsReady = SmsAvailability::canSend();
        $phoneCodes = [];

        if ($smsReady || $user->phone !== null) {
            foreach ((array) config('phone.countries', []) as $country) {
                $code = (string) ($country['code'] ?? '');

                if ($code !== '') {
                    $phoneCodes[] = [$code, (string) ($country['prefix'] ?? ''), (string) ($countries[$code] ?? ($country['name'] ?? $code))];
                }
            }
        }

        return [
            'countries' => $countries,
            'smsReady' => $smsReady,
            'phoneCodes' => $phoneCodes,
            'languages' => (array) config('languages', []),
            'canSell' => PremiumAvailability::canSell(),
        ];
    }

    /** Country names in the page's language (account-28: always English before), sorted by name. */
    private static function countryNames(): array
    {
        foreach (array_unique([str_replace('-', '_', App::getLocale()), 'en']) as $locale) {
            try {
                $names = Countries::getNames($locale);

                if ($names !== []) {
                    return $names;
                }
            } catch (Throwable) {
                // A language the Intl data does not know: English names.
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelData(User $user): array
    {
        // After a refused save the page sent back only what it had loaded (the Channel page fields and the open
        // groups): what it sent is laid over the member's stored choices, never over the site's defaults, so the
        // groups it never loaded keep showing (and sending) what the member chose.
        $stored = is_array($user->channel_customization) ? $user->channel_customization : [];
        $sent = old('channel_customization');

        return [
            'channelOptions' => ChannelCustomizationSettings::options(),
            'channelCustomization' => ChannelCustomizationSettings::merge(is_array($sent) ? array_replace($stored, $sent) : $stored),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function securityData(Request $request, User $user): array
    {
        $providers = [];

        foreach (OAuthProviders::supported() as $service) {
            $linked = filled($user->getAttribute($service.'_id'));
            $available = config('site.allow_social_login', true) && OAuthProviders::isAvailable($service);

            // Only the services this site has set up (account-13); a link made earlier can always be removed.
            if ($linked || $available) {
                $providers[] = ['service' => $service, 'linked' => $linked, 'available' => $available];
            }
        }

        // Devices: this device, then the other sign-ins (app sign-ins and, with database sessions, other browsers), the
        // most recently used first. The page lists DEVICES_SHOWN of them and says how many more there are, with "Show
        // all" (?devices=all, at most DEVICES_ALL); "Sign out all other devices" ends every one, shown or not.
        $showAll = $request->query('devices') === 'all';
        $limit = $showAll ? self::DEVICES_ALL : self::DEVICES_SHOWN;

        $apps = $user->devices()
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(fn ($device): array => [
                'kind' => 'app',
                'device' => $device,
                'name' => filled($device->name) ? (string) $device->name : __('An app'),
                'icon' => 'fa-mobile-screen-button',
                'time' => $device->last_used_at ?? $device->created_at,
                'used' => $device->last_used_at !== null,
            ]);
        [$browsers, $browserCount] = $this->otherBrowserSessions($request, $user, $limit);

        $signIns = $apps->concat($browsers)
            ->sortByDesc(fn (array $row): int => $row['time'] instanceof \DateTimeInterface ? $row['time']->getTimestamp() : 0)
            ->take($limit)
            ->values();
        $total = $user->devices()->count() + $browserCount;

        return [
            'providers' => $providers,
            'thisDevice' => self::deviceName((string) $request->userAgent()),
            'signIns' => $signIns,
            'moreSignIns' => max(0, $total - $signIns->count()),
            'allSignInsShown' => $showAll,
            'passwordMin' => (int) config('validation.user.password.min', 6),
            'strongPassword' => (bool) config('site.require_strong_password', false),
        ];
    }

    /**
     * The member's other browser sessions, when the site keeps sessions in its database (SESSION_DRIVER=database),
     * and how many there are; with the default file sessions they cannot be listed (and "Sign out all other devices"
     * still ends them).
     *
     * @return array{0: Collection<int, array{kind: string, device: null, name: string, icon: string, time: Carbon|null, used: bool}>, 1: int}
     */
    private function otherBrowserSessions(Request $request, User $user, int $limit): array
    {
        if (config('session.driver') !== 'database') {
            return [collect(), 0];
        }

        try {
            $current = $request->hasSession() ? $request->session()->getId() : null;
            $query = DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($current, fn ($query) => $query->where('id', '!=', $current));

            $rows = (clone $query)
                ->orderByDesc('last_activity')
                ->limit($limit)
                ->get(['user_agent', 'last_activity'])
                ->map(function (object $row): array {
                    $device = self::deviceName((string) ($row->user_agent ?? ''));

                    return [
                        'kind' => 'browser',
                        'device' => null,
                        'name' => $device['name'],
                        'icon' => $device['icon'],
                        'time' => is_numeric($row->last_activity ?? null) ? Carbon::createFromTimestamp((int) $row->last_activity) : null,
                        'used' => true,
                    ];
                });

            return [$rows, (int) $query->count()];
        } catch (Throwable) {
            return [collect(), 0];
        }
    }

    /**
     * "Windows · Chrome", "iPhone · Safari": the system and the browser of a user agent, with an icon.
     *
     * @return array{name: string, icon: string}
     */
    public static function deviceName(string $agent): array
    {
        $system = match (true) {
            (bool) preg_match('/iPhone|iPod/i', $agent) => 'iPhone',
            (bool) preg_match('/iPad/i', $agent) || (str_contains($agent, 'Macintosh') && str_contains($agent, 'Mobile/')) => 'iPad',
            (bool) preg_match('/Android/i', $agent) => 'Android',
            (bool) preg_match('/CrOS/i', $agent) => 'ChromeOS',
            (bool) preg_match('/Windows/i', $agent) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $agent) => 'macOS',
            (bool) preg_match('/Linux/i', $agent) => 'Linux',
            default => '',
        };

        $browser = match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//i', $agent) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $agent) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $agent) => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/i', $agent) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/i', $agent) => 'Chrome',
            (bool) preg_match('/Safari\//i', $agent) => 'Safari',
            default => '',
        };

        $name = implode("\u{00A0}· ", array_filter([$system, $browser]));

        return [
            'name' => $name !== '' ? $name : __('A browser'),
            'icon' => in_array($system, ['iPhone', 'Android'], true) ? 'fa-mobile-screen' : ($system === 'iPad' ? 'fa-tablet-screen-button' : 'fa-desktop'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function premiumData(User $user): array
    {
        $subscription = Subscription::query()
            ->where('user_id', $user->getKey())
            ->with('plan')
            ->latest('id')
            ->first();

        $returnUrl = route('user.edit', ['section' => 'premium']);
        $billingUrl = null;

        if ($subscription !== null && $subscription->payment_provider !== 'paypal') {
            $user->setRelation('premium_subscription', $subscription);
            $url = $user->billingManagementUrl($returnUrl);
            $billingUrl = $url !== $returnUrl ? $url : null;
        }

        $invoices = Transaction::query()
            ->where('user_id', $user->getKey())
            ->with('subscription.plan')
            ->latest('date')
            ->latest('id')
            ->limit(3)
            ->get();

        return [
            'subscription' => $subscription,
            'plan' => $this->planCard($subscription),
            'billingUrl' => $billingUrl,
            'invoices' => $invoices,
            'canSell' => PremiumAvailability::canSell(),
        ];
    }

    /**
     * The plan card of Account settings in plain words (account-42): standard, free trial, active, payment problem,
     * cancelled but still on, ended.
     *
     * @return array<string, mixed>
     */
    private function planCard(?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['state' => 'standard'];
        }

        // the price in the member's language, as the Premium page and the invoices write it (R2A-038: "5 €", "€5")
        $price = $subscription->plan ? BillingFormat::price($subscription->plan) : '';
        $per = $subscription->plan ? PremiumAvailability::per($subscription->plan) : '';
        $card = trim((string) $subscription->card_last4);
        $paypal = $subscription->payment_provider === 'paypal' || strcasecmp($card, 'PayPal') === 0;

        $state = match (true) {
            $subscription->is_unpaid => 'past_due',
            ! $subscription->is_active => 'ended',
            $subscription->ends_at !== null => 'ending',
            $subscription->on_trial => 'trial',
            default => 'active',
        };

        return [
            'state' => $state,
            'price' => $price,
            'per' => $per,
            'card' => $paypal ? null : (preg_match('/^\d{2,4}$/', $card) ? $card : null),
            'paypal' => $paypal,
            'next' => $subscription->next_payment,
            'trial_ends' => $subscription->trial_ends_at,
            'ends' => $subscription->ends_at,
            // the months one payment pays for (1 monthly, 12 yearly; 0 when the plan is gone)
            'months' => (int) ($subscription->plan?->duration ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteData(User $user): array
    {
        return [
            'counts' => [
                'videos' => $user->videos()->count(),
                // Watch later and Liked videos are named, not counted (account-43).
                'playlists' => $user->playlists()->where('is_deletable', true)->count(),
                'comments' => $user->comments()->count(),
                'subscribers' => $user->subscribers()->count(),
                'subscriptions' => $user->subscriptions()->count(),
            ],
        ];
    }

    /**
     * Hands this device a new "remember me" cookie with the account's new token, when it signed in with "remember me"
     * (the old cookie carries the old token and would no longer sign it in). The cookie is made exactly as Laravel's
     * SessionGuard::queueRecallerCookie() makes it. Laravel's logoutOtherDevices() is not used: it needs the password
     * in plain text and only rehashes it, while these actions also rotate the "remember me" token and end the app
     * sign-ins and the other sessions (endOtherSessions()).
     */
    private function keepThisDeviceSignedIn(Request $request, User $user): void
    {
        try {
            $guard = Auth::guard('web');

            if (! $guard instanceof SessionGuard || ! $request->cookies->has($guard->getRecallerName())) {
                return;
            }

            $queue = function (User $user): void {
                /** @var SessionGuard $this */
                $this->queueRecallerCookie($user);
            };
            $queue->call($guard, $user->fresh() ?? $user);
        } catch (Throwable $exception) {
            Log::warning('Keeping this device signed in after a sign-out of the other devices failed.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * End the member's other sign-ins: the app sign-ins (API tokens) and the other browser sessions, wherever the site
     * keeps them (the sessions table, or the session files of the default file driver: those of this member are found
     * by their sign-in key and removed, at most 2.5 seconds of reading). Sessions of other drivers end at their time
     * limit; with the new "remember me" token none of them comes back by itself.
     */
    private function endOtherSessions(Request $request, User $user): void
    {
        $request->attributes->set(self::ENDED_OTHER_SESSIONS, true);
        $user->tokens()->delete();

        $current = $request->hasSession() ? $request->session()->getId() : null;

        try {
            match ((string) config('session.driver')) {
                'database' => DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->when($current, fn ($query) => $query->where('id', '!=', $current))
                    ->delete(),
                'file' => $this->forgetFileSessions($user, $current),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::warning('Ending the other sessions of a member failed.', ['user_id' => $user->getKey(), 'exception' => $exception->getMessage()]);
        }
    }

    /** Remove the session files of this member other than the current one. Returns how many were removed. */
    private function forgetFileSessions(User $user, ?string $current): int
    {
        $path = (string) config('session.files');
        $guard = Auth::guard('web');

        if ($path === '' || ! is_dir($path) || config('session.encrypt') || ! method_exists($guard, 'getName')) {
            return 0;
        }

        $key = serialize($guard->getName());
        $needles = [$key.serialize($user->getKey()), $key.serialize((string) $user->getKey())];
        $deadline = microtime(true) + 2.5;
        $seen = 0;
        $removed = 0;

        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot() || ! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            if (++$seen > 50000 || microtime(true) > $deadline) {
                break;
            }

            if ($file->getFilename() === $current || $file->getSize() > 512 * 1024) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    @unlink($file->getPathname());
                    $removed++;
                    break;
                }
            }
        }

        return $removed;
    }

    /**
     * The titles "Download my data" may print of the videos $ids (playlists, comments, watch history): the member's own
     * videos and the ones anybody can watch now (Video::scopePublic(): public, unlisted, a planned video that is out),
     * the rule of the playlist editor and of History. Another member's private, draft, banned or deleted video gives no
     * title (R1-088: the export printed the current title of such a video); its id stays, it is the member's own record.
     *
     * @param  iterable<mixed>  $ids
     * @return array<int, string> video id => title
     */
    private function readableVideoTitles(User $user, iterable $ids): array
    {
        $wanted = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $wanted[(int) $id] = true;
            }
        }

        $titles = [];

        foreach (array_chunk(array_keys($wanted), 1000) as $chunk) {
            Video::query()
                ->whereIn('id', $chunk)
                ->where(fn ($query) => $query->where('user_id', $user->getKey())->orWhere(fn ($visible) => $visible->public()))
                ->toBase()
                ->get(['id', 'title'])
                ->each(function (object $video) use (&$titles): void {
                    $titles[(int) $video->id] = (string) $video->title;
                });
        }

        return $titles;
    }

    /** The export file, written piece by piece. */
    private function writeExport(User $user): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $date = fn ($value): ?string => $value instanceof \DateTimeInterface ? Carbon::instance($value)->toIso8601String() : null;
        $seo = app(SeoService::class);

        // Written straight to the output: PHP's own output buffer (4 KB by default) passes it on as it fills, and a
        // flush every 64 KB hands it to the web server. An output buffer someone else opened (a test, a proxy layer)
        // is never emptied from here, so whoever captures the download gets all of it.
        $written = 0;
        $write = function (string $text) use (&$written): void {
            echo $text;
            $written += strlen($text);

            if ($written >= 65536) {
                $written = 0;
                flush();
            }
        };

        $section = function (string $name, iterable $rows, bool $first = false) use ($write, $flags): void {
            $write(($first ? '' : ",\n").'  '.json_encode($name).': [');
            $count = 0;

            foreach ($rows as $row) {
                $write(($count++ > 0 ? ',' : '')."\n    ".str_replace("\n", "\n    ", (string) json_encode($row, $flags)));
            }

            $write(($count > 0 ? "\n  " : '').']');
        };

        $profile = [
            'username' => $user->username,
            'channel_address' => '@'.$user->slug,
            'channel_url' => $user->route,
            'email' => $user->email,
            'new_email_waiting_for_confirmation' => $user->temporary_email,
            'country' => $user->country,
            'language' => $user->language,
            'phone' => $user->phone !== null ? $user->phoneInternational() : null,
            'phone_verified' => $user->hasVerifiedPhone(),
            'description' => $user->description,
            'website' => $user->website,
            'show_subscriber_count' => (bool) $user->show_subscribers,
            'joined_at' => $date($user->created_at),
            'last_sign_in_at' => $date($user->last_login_at),
            'email_verified_at' => $date($user->email_verified_at),
            'avatar_url' => $user->avatar_url,
            'banner_url' => filled($user->banner) ? $user->banner_url : null,
            'premium' => (bool) $user->is_premium,
            'subscribers' => $user->subscribers()->count(),
        ];

        $write("{\n");
        $write('  "about": '.json_encode([
            'site' => (string) config('app.name'),
            'site_url' => (string) config('app.url'),
            'exported_at' => now()->toIso8601String(),
            'format' => 1,
        ], $flags | JSON_PRETTY_PRINT));
        $write(",\n  \"profile\": ".str_replace("\n", "\n  ", (string) json_encode($profile, $flags)));
        $write(",\n  \"channel_settings\": ".str_replace("\n", "\n  ", (string) json_encode($user->channelCustomization(), $flags)));
        $write(",\n");

        $section('videos', (function () use ($user, $date, $seo) {
            $query = Video::query()->where('user_id', $user->getKey())->with($seo->videoPathRelations())->orderBy('id');

            foreach ($query->lazyById(200) as $video) {
                yield [
                    'id' => $video->id,
                    'title' => $video->title,
                    'description' => $video->description,
                    'status' => $video->status instanceof \BackedEnum ? strtolower($video->status->name) : $video->status,
                    'language' => $video->language,
                    'duration' => $video->duration,
                    'views' => (int) $video->views,
                    'is_short' => (bool) $video->is_short,
                    'allow_comments' => (bool) $video->allow_comments,
                    'created_at' => $date($video->created_at),
                    'published_at' => $date($video->published_at),
                    'scheduled_at' => $date($video->scheduled_at),
                    'url' => rescue(fn () => $video->route, null, false),
                ];
            }
        })(), true);

        // The titles of other members' videos: only of the ones anybody can watch (readableVideoTitles(), R1-088).
        $section('playlists', (function () use ($user, $date) {
            foreach (Playlist::query()->where('user_id', $user->getKey())->orderBy('id')->lazyById(100) as $playlist) {
                $ids = DB::table('playlist_has_videos')
                    ->join('videos', 'videos.id', '=', 'playlist_has_videos.video_id')
                    ->where('playlist_has_videos.playlist_id', $playlist->id)
                    ->orderBy('playlist_has_videos.position')
                    ->limit(5000)
                    ->pluck('videos.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $titles = $this->readableVideoTitles($user, $ids);

                yield [
                    'id' => $playlist->id,
                    'title' => $playlist->title,
                    'description' => $playlist->description,
                    'visibility' => $playlist->status instanceof \BackedEnum ? strtolower($playlist->status->name) : $playlist->status,
                    'made_by_the_site' => ! $playlist->is_deletable,
                    'created_at' => $date($playlist->created_at),
                    'videos' => array_map(fn (int $id): array => ['id' => $id, 'title' => $titles[$id] ?? null], $ids),
                ];
            }
        })());

        $section('comments', (function () use ($user) {
            $query = DB::table('comments')
                ->where('comments.user_id', $user->getKey())
                ->select(['comments.id', 'comments.content', 'comments.video_id', 'comments.parent_id', 'comments.created_at'])
                ->orderBy('comments.id');

            foreach ($query->lazyById(500, 'comments.id', 'id')->chunk(500) as $comments) {
                $titles = $this->readableVideoTitles($user, $comments->pluck('video_id')->all());

                foreach ($comments as $comment) {
                    yield [
                        'id' => $comment->id,
                        'text' => $comment->content,
                        'video_id' => $comment->video_id,
                        'video_title' => $titles[(int) $comment->video_id] ?? null,
                        'reply_to_comment' => $comment->parent_id,
                        'created_at' => $comment->created_at ? Carbon::parse($comment->created_at)->toIso8601String() : null,
                    ];
                }
            }
        })());

        $section('subscriptions', (function () use ($user, $date) {
            foreach ($user->subscriptions()->orderBy('users.id')->get(['users.id', 'users.username', 'users.slug', 'users.profile_uid']) as $channel) {
                yield [
                    'channel' => $channel->username,
                    'channel_url' => rescue(fn () => $channel->route, null, false),
                    'subscribed_at' => $date($channel->pivot?->subscribe_at ? Carbon::parse($channel->pivot->subscribe_at) : null),
                ];
            }
        })());

        $section('watch_history', (function () use ($user) {
            $rows = DB::table('views')
                ->where('views.user_id', $user->getKey())
                ->orderByDesc('views.view_at')
                ->limit(1000)
                ->get(['views.video_id', 'views.view_at']);
            $titles = $this->readableVideoTitles($user, $rows->pluck('video_id')->all());

            foreach ($rows as $row) {
                yield [
                    'video_id' => $row->video_id,
                    'video_title' => $titles[(int) $row->video_id] ?? null,
                    'watched_at' => $row->view_at ? Carbon::parse($row->view_at)->toIso8601String() : null,
                ];
            }
        })());

        $section('invoices', (function () use ($user, $date) {
            foreach (Transaction::query()->where('user_id', $user->getKey())->with('subscription.plan')->orderBy('id')->get() as $transaction) {
                yield [
                    'number' => InvoiceService::number($transaction),
                    'date' => $date($transaction->date),
                    'plan' => InvoiceService::planLabel($transaction),
                    // a data file (ISO dates): the amount in one form whatever the page's language ("€5.00")
                    'amount' => PremiumAvailability::money((int) $transaction->getRawOriginal('amount'), 2),
                    'paid_with' => $transaction->payment_provider ?? 'stripe',
                ];
            }
        })());

        $write("\n}\n");
        flush();
    }
}
