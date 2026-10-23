{{--
    Secret field (passwords and keys): never renders the stored value. Shows "Saved"; an empty submit keeps the
    old value (the page's save code must skip an empty secret input).
    <x-admin.secret name="smtp_password" :saved="$hasPassword" label="Password" help="An app password from your provider." />
--}}
@props(['name', 'saved' => false, 'label' => '', 'help' => null, 'id' => null])
@php($secretId = $id ?? $name)
<x-admin.field :name="$name" :label="$label" :help="$help" :id="$secretId" {{ $attributes }}>
    <div class="ad-secret" data-ad-secret>
        @if ($saved)
            <x-admin.status type="ok">{{ __('Saved') }}</x-admin.status>
        @endif
        <input type="password" class="ad-input" id="{{ $secretId }}" name="{{ $name }}" value="" autocomplete="new-password" spellcheck="false"
               placeholder="{{ $saved ? __('Leave empty to keep the saved one') : '' }}">
    </div>
</x-admin.field>
