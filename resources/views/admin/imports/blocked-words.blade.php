{{--
    Content > Import's settings card (config/admin_settings.php, page content.import, card blocked_words): the words that
    keep AVDBAPI and XVIDAPI items out of the site (App\Support\ContentSafety), one card on both import pages, saved like
    every settings page. The new admin draws it with the settings form; the old admin (one release) gets the same list in
    a plain form of its own look, saved through the same save (PUT admin.settings.save: only this field is written; the
    same rules, the same limits), with the label and the help of the setting.
--}}
@if (\App\Support\AdminPreferences::usesNewAdmin())
    {{-- (block form: Blade does not mix @php(...) with an @php ... @endphp block in one file) --}}
    @php
        $blockedWordsForm = app(\App\Http\Controllers\Admin\SettingsPageController::class)->form(request(), 'content.import');
    @endphp
    @if ($blockedWordsForm['cards'] !== [] || $blockedWordsForm['advancedCards'] !== [])
        <div class="ad-page-settings mb-4" id="page-settings">
            @include('admin.settings.partials.form', $blockedWordsForm)
        </div>
    @endif
@else
    @php
        $blockedWordsSetting = (array) config('admin_settings.settings.'.\App\Support\ContentSafety::SETTING, []);
        $blockedWordsCard = (array) ((config('admin_settings.pages')['content.import']['cards']['blocked_words'] ?? []));
        $blockedWordsStored = \App\Models\Setting::getGroup(\App\Support\ContentSafety::SETTING_GROUP)[\App\Support\ContentSafety::SETTING] ?? null;
        $blockedWordsField = 'groups.'.\App\Support\ContentSafety::SETTING_GROUP.'.'.\App\Support\ContentSafety::SETTING;
        // the save keys its errors "group.key" (App\Http\Requests\Admin\SettingsSaveRequest)
        $blockedWordsError = $errors->first(\App\Support\ContentSafety::SETTING_GROUP.'.'.\App\Support\ContentSafety::SETTING);
    @endphp
    <div class="card shadow-sm mb-4" id="import_blocked_words">
        <div class="card-body">
            <h5 class="card-title mb-1"><i class="fa-solid fa-ban me-2" aria-hidden="true"></i>{{ __($blockedWordsCard['title'] ?? 'Blocked words') }}</h5>
            @if (! empty($blockedWordsCard['description']))
                <p class="text-muted small mb-3">{{ __($blockedWordsCard['description']) }}</p>
            @endif
            <form method="POST" action="{{ route('admin.settings.save') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="fields[{{ \App\Support\ContentSafety::SETTING_GROUP }}][]" value="{{ \App\Support\ContentSafety::SETTING }}">
                <label for="import_blocked_words_field" class="form-label fw-semibold">{{ __($blockedWordsSetting['label'] ?? 'Blocked words') }}</label>
                <textarea id="import_blocked_words_field" name="groups[{{ \App\Support\ContentSafety::SETTING_GROUP }}][{{ \App\Support\ContentSafety::SETTING }}]"
                    rows="8" dir="auto" spellcheck="false" class="form-control font-monospace @if ($blockedWordsError) is-invalid @endif"
                    aria-describedby="import_blocked_words_help{{ $blockedWordsError ? ' import_blocked_words_error' : '' }}">{{ old($blockedWordsField, $blockedWordsStored ?? \App\Support\ContentSafety::defaultText()) }}</textarea>
                @if ($blockedWordsError)
                    <div id="import_blocked_words_error" class="invalid-feedback d-block">{{ $blockedWordsError }}</div>
                @endif
                <div id="import_blocked_words_help" class="form-text">{{ __($blockedWordsSetting['help'] ?? '') }}</div>
                <button type="submit" class="btn btn-primary btn-sm mt-3">{{ __('Save') }}</button>
            </form>
        </div>
    </div>
@endif
