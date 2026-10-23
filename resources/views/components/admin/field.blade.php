{{--
    Field row: label (plain words), one-line help, the control, "Default: X" + Reset, the error line.
    The input's id is the setting key, so search can deep-link to /admin/settings/email#smtp_host.
    <x-admin.field name="smtp_host" label="Server address" help="From your provider, for example smtp.gmail.com" default="smtp.gmail.com">
        <input class="ad-input" id="smtp_host" name="smtp_host" value="...">
    </x-admin.field>
    Without a slot it renders an input: <x-admin.field name="site_name" label="Site name" :value="$value" />
    Props: name, label, help, value, type (text|email|url|number|password|textarea), default, error (default: from
    $errors), placeholder, suffix, phone (bool: a phone-only setting), id (default: name).
--}}
@props(['name', 'label' => '', 'help' => null, 'value' => null, 'type' => 'text', 'default' => null, 'error' => null, 'placeholder' => null, 'suffix' => null, 'phone' => false, 'id' => null])
@php
    $fieldId = $id ?? $name;
    $error ??= isset($errors) ? $errors->first($name) : null;
    $helpId = $help ? $fieldId.'-help' : null;
    $errorId = $error ? $fieldId.'-error' : null;
    $describedBy = trim(($helpId ?? '').' '.($errorId ?? '')) ?: null;
    $current = old($name, $value);
@endphp
<div {{ $attributes->class(['ad-field', 'is-error' => (bool) $error]) }} data-ad-field="{{ $name }}">
    <label for="{{ $fieldId }}">{{ $label }}@if($phone) {{ \App\Support\AdminTheme::icon('phone', 'sm') }}<span class="ad-vh">{{ __('(phones only)') }}</span>@endif</label>
    @if ($help)
        <p class="ad-help" id="{{ $helpId }}">{{ $help }}</p>
    @endif
    <div class="ad-control">
        @if (trim((string) $slot) !== '')
            {{ $slot }}
        @elseif ($type === 'textarea')
            <textarea class="ad-textarea" id="{{ $fieldId }}" name="{{ $name }}" @if($placeholder) placeholder="{{ $placeholder }}" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif>{{ $current }}</textarea>
        @elseif ($suffix)
            <div class="ad-input-group">
                <input class="ad-input" type="{{ $type }}" id="{{ $fieldId }}" name="{{ $name }}" value="{{ $current }}" @if($placeholder) placeholder="{{ $placeholder }}" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif>
                <span class="ad-suffix">{{ $suffix }}</span>
            </div>
        @else
            <input class="ad-input" type="{{ $type }}" id="{{ $fieldId }}" name="{{ $name }}" value="{{ $current }}" @if($placeholder) placeholder="{{ $placeholder }}" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($error) aria-invalid="true" @endif>
        @endif
    </div>
    @if ($default !== null && $default !== '')
        <div class="ad-field-meta">
            <span>{{ __('Default: :value', ['value' => $default]) }}</span>
            <button type="button" data-ad-reset="{{ $fieldId }}" data-ad-reset-value="{{ $default }}">{{ __('Reset') }}</button>
        </div>
    @endif
    @if ($error)
        <p class="ad-error" id="{{ $errorId }}" role="alert">{{ \App\Support\AdminTheme::icon('alert-circle', 'sm') }}{{ $error }}</p>
    @endif
</div>
