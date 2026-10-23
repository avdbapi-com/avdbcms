{{--
    Save bar: appears only when the form has unsaved changes ("N unsaved changes", Discard, Save changes);
    Ctrl/Cmd+S saves; leaving with unsaved changes asks first (resources/admin/modules/savebar.ts).
    Put it inside a <form data-ad-form id="..."> or name the form: <x-admin.savebar form="settings-email" />.
    Props: form (form id), save (button label).
--}}
@props(['form' => null, 'save' => null])
<div {{ $attributes->class(['ad-savebar']) }} data-ad-savebar="{{ $form ?? '' }}" role="region" aria-label="{{ __('Unsaved changes') }}" hidden>
    <span class="ad-changes" data-ad-changes aria-live="polite">{{ __('1 unsaved change') }}</span>
    <button type="button" class="ad-btn" data-ad-discard>{{ __('Discard') }}</button>
    <button type="button" class="ad-btn is-primary" data-ad-save>{{ $save ?? __('Save changes') }}</button>
</div>
