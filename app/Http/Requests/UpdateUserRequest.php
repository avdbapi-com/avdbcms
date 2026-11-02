<?php

namespace App\Http\Requests;

use App\Enums\ImageType;
use App\Models\User;
use App\Rules\MimetypeEnum;
use App\Support\ChannelCustomizationSettings;
use App\Support\SmsAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;
use Symfony\Component\Intl\Countries;
use Throwable;

/**
 * The Profile and Channel sections of Account settings (member design 6.9): every section posts only its own
 * fields to PUT /profile/update, with a hidden "section" that brings the member back to it.
 */
class UpdateUserRequest extends FormRequest
{
    /** The sections a save can come back to (Account settings, ?section=). */
    public const SECTIONS = ['profile', 'channel', 'security', 'notifications', 'premium', 'delete'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        $user = $this->user();

        // Every value below is read only when it is text: a list where text belongs (username[]=..., a hand-made
        // request) is left to the "string" rules, which refuse it with a message instead of an error page.

        // The phone number (6.17). "Remove this number" (remove_phone) or an emptied field removes it; a number is only
        // taken while the site can send the code, so nobody is asked for a number the site cannot check (account-11).
        if ($this->boolean('remove_phone')) {
            $this->merge(['phone' => null]);
        } elseif ($this->exists('phone') && (is_string($this->input('phone')) || $this->input('phone') === null)) {
            $phone = trim((string) $this->input('phone', ''));

            if ($phone === '') {
                $this->merge(['phone' => null]);
            } elseif (! SmsAvailability::canSend()) {
                $this->offsetUnset('phone');
            } else {
                $this->merge(['phone' => $this->e164($phone)]);
            }
        }

        // The language of the site and of the member's e-mails, saved with the Profile section.
        if ($this->exists('language') && ! config('site.allow_language_switch', true)) {
            $this->offsetUnset('language');
        }

        // A username the member keeps is never checked against the characters rule (only a new one).
        $username = $this->input('username');

        if ($user && is_string($username) && trim($username) === (string) $user->username) {
            $this->merge(['username' => $user->username]);
        }

        // Web addresses as people type them ("MyChannel.com", "https://www.MyChannel.com/videos?ref=yt"): trimmed, the
        // scheme in small letters; a button link without a scheme gets https:// (the channel page only links http(s)).
        $website = $this->input('website');

        if (is_string($website)) {
            $this->merge(['website' => self::lowerScheme(trim($website)) ?: null]);
        }

        $customization = $this->input('channel_customization');

        if (is_array($customization) && is_string($customization['cta_url'] ?? null)) {
            $link = self::lowerScheme(trim($customization['cta_url']));

            if ($link !== '' && self::schemeOf($link) === null && self::isWebsite($link)) {
                $link = 'https://'.$link;
            }

            $customization['cta_url'] = $link;
            $this->merge(['channel_customization' => $customization]);
        }
    }

    /**
     * A mobile number of the chosen country (the "code" list). The country is read here, as text only: the phone
     * package reads its country field typed as text and stops with an error on a list (code[]=...).
     */
    private function phoneRule(): Phone
    {
        $rule = (new Phone)->type('mobile');
        $code = $this->input('code');

        return is_string($code) && trim($code) !== '' ? $rule->country(strtoupper(trim($code))) : $rule;
    }

    /**
     * A web address a channel can link to: http or https (written or not: "example.com" is https://example.com), a
     * real host name or address with a dot in it, a path, a query and a fragment allowed; never another scheme
     * (javascript:, data:, file:) and never spaces.
     */
    public static function isWebsite(string $value): bool
    {
        if ($value === '' || preg_match('/\s/u', $value)) {
            return false;
        }

        $scheme = self::schemeOf($value);

        if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $candidate = $scheme !== null ? self::lowerScheme($value) : 'https://'.$value;

        if (! Str::isUrl($candidate, ['http', 'https'])) {
            return false;
        }

        $host = parse_url($candidate, PHP_URL_HOST);

        return is_string($host) && str_contains(trim($host, '.[]'), '.');
    }

    /**
     * The scheme an address starts with ("https", "javascript"), in small letters, or null: "example.com:8080/page"
     * has none (a dot before the colon is a host name).
     */
    private static function schemeOf(string $value): ?string
    {
        return preg_match('#^([a-z][a-z0-9+.-]*):#i', $value, $match) && ! str_contains($match[1], '.')
            ? strtolower($match[1])
            : null;
    }

    /** "HTTPS://Example.com" -> "https://Example.com" (only the scheme). */
    private static function lowerScheme(string $value): string
    {
        return (string) preg_replace_callback('#^(https?)://#i', static fn (array $m): string => strtolower($m[1]).'://', $value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        $user = $this->user();
        $username = $this->input('username');
        $newUsername = $user === null || ! is_string($username) || $username !== (string) $user->username;

        // "bail" everywhere a later rule (unique, phone, size) cannot take a list: the "string" rule refuses it first.
        return [
            'section' => ['bail', 'sometimes', 'nullable', 'string', Rule::in(self::SECTIONS)],
            'username' => array_values(array_filter([
                'bail',
                'sometimes',
                'required',
                'string',
                'min:'.config('validation.user.username.min'),
                'max:'.config('validation.user.username.max'),
                $newUsername ? 'regex:'.User::USERNAME_PATTERN : null,
                Rule::unique('users', 'username')->ignore(Auth::user()),
            ])),
            'slug' => [
                'bail',
                'sometimes',
                'required',
                'string',
                'regex:'.User::SLUG_PATTERN,
                'min:'.config('validation.user.slug.min'),
                'max:'.config('validation.user.slug.max'),
                Rule::unique('users', 'slug')->ignore(Auth::user())
            ],
            'email' => [
                'bail',
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore(Auth::user())
            ],
            'avatar' => [
                'sometimes',
                'nullable',
                'file',
                new MimetypeEnum(ImageType::class),
                'max:'.config('site.max_avatar_size', 2048),
                Rule::dimensions()->minWidth(98)->minHeight(98)
            ],
            'remove_avatar' => ['sometimes', 'boolean'],
            'phone' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                $this->phoneRule(),
                Rule::unique('users', 'phone')->ignore(Auth::user()),
            ],
            'remove_phone' => ['sometimes', 'boolean'],
            'code' => [
                'bail',
                'sometimes',
                'nullable',
                'required_with:phone',
                'string',
                Rule::in(array_column(config('phone.countries'), 'code'))
            ],
            'prefix' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(config('phone.countries'), 'prefix'))
            ],
            'banner' => [
                'sometimes',
                'nullable',
                'file',
                new MimetypeEnum(ImageType::class),
                'max:'.config('site.max_banner_size', 5120),
            ],
            'remove_banner' => ['sometimes', 'boolean'],
            'description' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                'max:'.config('validation.user.description.max'),
            ],
            'country' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                Rule::in(Countries::getCountryCodes())
            ],
            'language' => ['bail', 'sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('languages', [])))],
            // Any http(s) address a member really uses: capitals, a path, a query, a fragment, or no scheme at all
            // ("MyChannel.com"). The rule before refused "https://www.MyChannel.com" and every address with "?".
            'website' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                'max:'.config('validation.user.website.max'),
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! self::isWebsite($value)) {
                        $fail(__('Use a web address like example.com or https://example.com/page.'));
                    }
                },
            ],
            'show_subscribers' => 'sometimes|nullable|boolean',
            'channel_customization' => ['sometimes', 'array'],
            'channel_customization.layout' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['layout']))],
            'channel_customization.theme' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['theme']))],
            'channel_customization.accent' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['accent']))],
            'channel_customization.custom_accent' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'channel_customization.surface' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['surface']))],
            'channel_customization.banner_treatment' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['banner_treatment']))],
            'channel_customization.avatar_frame' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['avatar_frame']))],
            'channel_customization.motion' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['motion']))],
            'channel_customization.pattern' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['pattern']))],
            'channel_customization.header_shape' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['header_shape']))],
            'channel_customization.stats_style' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['stats_style']))],
            'channel_customization.video_layout' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['video_layout']))],
            'channel_customization.featured_mode' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['featured_mode']))],
            'channel_customization.featured_layout' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['featured_layout']))],
            'channel_customization.featured_columns' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'channel_customization.featured_rows' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'channel_customization.latest_uploads_layout' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['latest_uploads_layout']))],
            'channel_customization.latest_uploads_columns' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'channel_customization.latest_uploads_rows' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'channel_customization.playlists_layout' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['playlists_layout']))],
            'channel_customization.playlists_columns' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'channel_customization.playlists_rows' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'channel_customization.playlist_video_columns' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'channel_customization.playlist_video_rows' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'channel_customization.density' => ['sometimes', Rule::in(array_keys(ChannelCustomizationSettings::options()['density']))],
            'channel_customization.tagline' => ['sometimes', 'nullable', 'string', 'max:' . config('site.channel_tagline_max_length', 120)],
            'channel_customization.cta_label' => ['sometimes', 'nullable', 'string', 'max:' . config('site.channel_cta_label_max_length', 42)],
            // the button on the channel page links http(s) only (another scheme would only hide the button)
            'channel_customization.cta_url' => ['bail', 'sometimes', 'nullable', 'string', 'max:255', 'url:http,https'],
            'channel_customization.show_stats' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_featured_video' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_latest_uploads' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_playlists' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_about_panel' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_social_links' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_upload_rhythm' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.show_subscribe_cta' => ['sometimes', 'nullable', 'boolean'],
            'channel_customization.dedupe_home_sections' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages() : array
    {
        // the size as the crop window writes it, in the member's language ("2.5" / "2,5"; 1.2.3 round 3, R3-045)
        $mb = fn (int $kb): string => \App\Support\NumberFormat::decimal($kb / 1024, 1);

        return [
            'avatar.max' => __('This picture is too large. Use one of :size MB or less.', ['size' => $mb((int) config('site.max_avatar_size', 2048))]),
            'avatar.dimensions' => __('This picture is too small. Use one of at least :min_width x :min_height pixels.'),
            'banner.max' => __('This picture is too large. Use one of :size MB or less.', ['size' => $mb((int) config('site.max_banner_size', 5120))]),
            'banner.dimensions' => __('This picture is too small. Use one of at least :min_width x :min_height pixels.'),
            'username.regex' => __('Use letters, numbers, dots, dashes and underscores only.'),
            'slug.regex' => __('Use letters, numbers, dots, dashes and underscores only.'),
            'phone.unique' => __('This number is already used by another account.'),
            'phone.phone' => __('This is not a mobile number of the country you chose.'),
            'code.required_with' => __('Choose the country code of your number.'),
            'channel_customization.cta_url.url' => __('Use a web address like example.com or https://example.com/page.'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'username' => __('Username'),
            'slug' => __('Channel address'),
            'email' => __('E-mail'),
            'phone' => __('Phone'),
            'code' => __('Country code'),
            'country' => __('Country'),
            'language' => __('Language'),
            'website' => __('Website'),
            'description' => __('Description'),
            'channel_customization.tagline' => __('Tagline'),
            'channel_customization.cta_label' => __('Button on your channel'),
            'channel_customization.cta_url' => __('Button link'),
        ];
    }

    /**
     * The typed number in the international format the users.phone column keeps ("+33612345678"), so the unique rule
     * compares like with like; a number that cannot be read is passed on as typed and the phone rule refuses it.
     */
    private function e164(string $phone): string
    {
        $code = $this->input('code');
        $country = is_string($code) ? strtoupper(trim($code)) : '';

        if ($country === '' && str_starts_with($phone, '+') === false) {
            $prefix = $this->input('prefix');
            $prefix = is_string($prefix) ? trim($prefix) : '';
            $phone = $prefix !== '' ? $prefix.$phone : $phone;
        }

        try {
            return (new PhoneNumber($phone, $country !== '' ? $country : null))->formatE164();
        } catch (Throwable) {
            return $phone;
        }
    }
}
