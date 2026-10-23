<?php

namespace App\Http\Requests;

use App\Enums\ImageType;
use App\Rules\MimetypeEnum;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;
use Symfony\Component\Intl\Countries;

class UpdateUserRequest extends FormRequest
{
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
        // Only concatenate the dial prefix with the typed number when the
        // user actually entered something. Empty / whitespace-only values
        // used to be sent through as "+231" which failed the Phone
        // "mobile" validator and blocked the entire profile update
        // (avatar + everything else) for users who hadn't set a phone.
        $phone = $this->get('phone');
        if ($phone === null || trim((string) $phone) === '') {
            // Nothing to do: leave 'phone' absent (or empty) so the
            // `sometimes|nullable` rule short-circuits cleanly and the
            // controller skips the phone-verification SMS trigger.
            return;
        }

        $this->merge([
            'phone' => $this->get('prefix') . $phone,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules() : array
    {
        return [
            'username' => [
                'sometimes',
                'required',
                'min:'.config('validation.user.username.min'),
                'max:'.config('validation.user.username.max'),
                Rule::unique('users', 'username')->ignore(Auth::user())
            ],
            'slug' => [
                'sometimes',
                'required',
                'regex:/^[a-zA-Z0-9._-]+$/',
                'min:'.config('validation.user.slug.min'),
                'max:'.config('validation.user.slug.max'),
                Rule::unique('users', 'slug')->ignore(Auth::user())
            ],
            'email' => [
                'sometimes',
                'required',
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
            'phone' => [
                'sometimes',
                Rule::requiredIf(!is_null($this->user()->getPhone())),
                'nullable',
                'string',
                (new Phone)->countryField('code')->type('mobile')
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(config('phone.countries'), 'code'))
            ],
            'prefix' => [
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
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.config('validation.user.description.max'),
            ],
            'country' => [
                'sometimes',
                'nullable',
                Rule::in(Countries::getCountryCodes())
            ],
            'website' => [
                'sometimes',
                'nullable',
                'regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w@ \.-]*)*\/?$/',
                'max:'.config('validation.user.website.max'),
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
            'channel_customization.cta_url' => ['sometimes', 'nullable', 'url', 'max:255'],
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
        return [
            'avatar.max' => 'The avatar file is too large. Its size should not exceed '.round(config('site.max_avatar_size', 2048)/1024, 1).' MB.',
            'avatar.dimensions' => 'The avatar image must be at least :min_width x :min_height pixels',
            'banner.max' => 'The banner file is too large. Its size should not exceed '.round(config('site.max_banner_size', 5120)/1024, 1).' MB.',
            'banner.dimensions' => 'The banner image must be at least :min_width x :min_height pixels',
            'slug.regex' => ':attribute can only contain letters, numbers, periods (.), dashes (-), and underscores (_)'
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
            'slug' => 'Handle',
        ];
    }
}
