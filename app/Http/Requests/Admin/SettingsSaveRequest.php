<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\User;
use App\Support\AdminIpLock;
use App\Support\AdminPreferences;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PUT /admin/settings/save (interface I7): the fields of one admin page, which may belong to several settings groups.
 *
 * Body: groups[group][key] = value, fields[group][] = key (the manifest of the fields the page sent) and optionally
 * clear[group][] = key for a password or key to remove. Only the listed keys are validated, with the rules the legacy
 * group save uses, and nothing is written unless every one of them is valid. A listed switch that is absent (an
 * unticked checkbox) means off. Errors are keyed "group.key".
 */
class SettingsSaveRequest extends FormRequest
{
    private ?array $schema = null;

    public function authorize(): bool
    {
        // The route sits behind the admin middleware.
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'fields' => ['required', 'array'],
            'groups' => ['sometimes', 'array'],
            'clear' => ['sometimes', 'array'],
            'clear.*' => ['array'],
            'clear.*.*' => ['string'],
        ];

        foreach ($this->manifest() as $group => $keys) {
            foreach ($keys as $key) {
                if (isset($this->schema()[$group][$key])) {
                    $rules["groups.{$group}.{$key}"] = $this->rulesFor($group, $key);
                }
            }
        }

        return $rules;
    }

    /**
     * Errors appear under their own field, so they speak about "this" value instead of repeating the label.
     * (The legacy group save keeps Laravel's wording.)
     */
    public function messages(): array
    {
        return [
            'required' => __('This cannot be empty.'),
            'integer' => __('Use a whole number.'),
            'numeric' => __('Use a number.'),
            // Size rules take one message per kind of value.
            'min' => ['numeric' => __('Use :min or more.'), 'string' => __('Use at least :min characters.'), 'array' => __('Choose at least :min.'), 'file' => __('Use a file of at least :min KB.')],
            'max' => ['numeric' => __('Use :max or less.'), 'string' => __('Use :max characters or fewer.'), 'array' => __('Choose :max at most.'), 'file' => __('Use a file of :max KB or less.')],
            'email' => __('Use an email address like name@example.com.'),
            'url' => __('Use a full address that starts with https://'),
            'in' => __('Choose one of the options.'),
            'regex' => __('This is not in the expected format.'),
            'boolean' => __('Use on or off.'),
            'timezone' => __('Choose a time zone from the list.'),
        ];
    }

    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->manifest() as $group => $keys) {
            foreach ($keys as $key) {
                $label = config("admin_settings.settings.{$key}.label");
                $attributes["groups.{$group}.{$key}"] = is_string($label) ? __($label) : str_replace('_', ' ', $key);
            }
        }

        return $attributes;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $developer = $this->developerMode();

            foreach ($this->manifest() as $group => $keys) {
                if (! isset($this->schema()[$group])) {
                    $validator->errors()->add($group, __('This settings group is not editable.'));

                    continue;
                }

                foreach ($keys as $key) {
                    if (! isset($this->schema()[$group][$key]) || ! self::editable($key, $developer)) {
                        $validator->errors()->add("{$group}.{$key}", __('This setting cannot be saved here.'));
                    }
                }
            }

            foreach ($this->clearList() as $group => $keys) {
                foreach ($keys as $key) {
                    if (! isset($this->schema()[$group][$key]) || ! SettingsPageController::isWriteOnly($key)) {
                        $validator->errors()->add("{$group}.{$key}", __('Only passwords and keys can be removed this way.'));
                    } elseif (! self::editable($key, $developer)) {
                        $validator->errors()->add("{$group}.{$key}", __('This setting cannot be saved here.'));
                    }
                }
            }
        }];
    }

    /** @return array<string, list<string>> group => keys the page sent */
    public function manifest(): array
    {
        return $this->listInput('fields');
    }

    /** @return array<string, list<string>> group => passwords / keys to remove */
    public function clearList(): array
    {
        return $this->listInput('clear');
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = [];

        foreach ($validator->errors()->messages() as $attribute => $messages) {
            $errors[str_starts_with($attribute, 'groups.') ? substr($attribute, 7) : $attribute] = $messages;
        }

        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'ok' => false,
                'message' => __('Nothing was saved. Check the highlighted settings.'),
                'errors' => $errors,
            ], 422));
        }

        // A plain form post goes back with the typed values, except passwords and keys: those never reach the
        // session (the exception handler would flash the whole input).
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())
            ->withInput($this->inputWithoutWriteOnly())
            ->withErrors($errors, $this->errorBag));
    }

    /**
     * Whether the pages may save this key for this admin: like the pages show them, expert settings only in
     * Developer mode and hidden ones never (AppearancePages::editableKeys() applies the same rule to the drafts).
     * Keys without a page definition keep the group schema's say.
     */
    public static function editable(string $key, bool $developer): bool
    {
        $level = config("admin_settings.settings.{$key}.level");

        return match ($level) {
            'hidden' => false,
            'expert' => $developer,
            default => true,
        };
    }

    /**
     * Developer mode of this admin, enforced like EnsureDeveloperMode does: the old admin (v1) keeps its behaviour
     * for its one release, and before "Finish update" adds the preferences column it cannot be switched on, so
     * nothing is refused then.
     */
    private function developerMode(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! AdminPreferences::usesNewAdmin($user) || ! AdminPreferences::available($user)) {
            return true;
        }

        return AdminPreferences::developerMode($user);
    }

    private function inputWithoutWriteOnly(): array
    {
        $input = $this->except(['_token', '_method', 'password', 'current_password', 'password_confirmation']);

        foreach ((array) ($input['groups'] ?? []) as $group => $values) {
            if (! is_array($values)) {
                continue;
            }

            foreach (array_keys($values) as $key) {
                if (is_string($key) && SettingsPageController::isWriteOnly($key)) {
                    unset($input['groups'][$group][$key]);
                }
            }
        }

        return $input;
    }

    private function rulesFor(string $group, string $key): array
    {
        $rules = $this->schema()[$group][$key]['rules'];

        if ($group === 'security' && $key === 'admin_ip_whitelist') {
            $rules[] = static function (string $attribute, mixed $value, Closure $fail): void {
                $invalid = AdminIpLock::invalidEntries((string) $value);

                if ($invalid !== []) {
                    $fail(__('Use one address per line, like 203.0.113.7 or 2001:db8::7, or a range like 203.0.113.0/24. Not an address: :entries', [
                        'entries' => implode(', ', array_slice($invalid, 0, 5)),
                    ]));
                }
            };
        }

        return $rules;
    }

    private function schema(): array
    {
        return $this->schema ??= app(SettingsController::class)->saveSchema();
    }

    /** @return array<string, list<string>> */
    private function listInput(string $name): array
    {
        $out = [];

        foreach ((array) $this->input($name, []) as $group => $keys) {
            // A page without JavaScript sends each group's list as one "key,key" field.
            if (is_string($keys)) {
                $keys = explode(',', $keys);
            }

            if (! is_string($group) || ! is_array($keys)) {
                continue;
            }

            $out[$group] = array_values(array_unique(array_filter($keys, 'is_string')));
        }

        return $out;
    }
}
