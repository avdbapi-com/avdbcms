{{--
    Upload field for the logo and favicon: drop zone with a preview and a file input; the link stays available
    under "Use a link instead". The page's controller stores the file and saves the URL setting.
    <x-admin.upload name="logo_url" :value="$url" accept="image/*" label="Logo" help="PNG or SVG, at least 200 px wide." />
    Props: name (the URL setting), value, accept, label, help, file (name of the file input, default <name>_file).
--}}
@props(['name', 'value' => null, 'accept' => 'image/*', 'label' => '', 'help' => null, 'file' => null, 'id' => null])
@php
    $uploadId = $id ?? $name;
    $fileName = $file ?? $name.'_file';
    $current = (string) old($name, $value);
@endphp
<x-admin.field :name="$fileName" :label="$label" :help="$help" :id="$uploadId.'_file'" {{ $attributes }}>
    <div class="ad-drop" data-ad-drop>
        <img src="{{ $current !== '' ? site_asset_url($current) : '' }}" alt="" @if($current === '') hidden @endif data-ad-drop-preview>
        <div class="ad-stack">
            <input type="file" id="{{ $uploadId }}_file" name="{{ $fileName }}" accept="{{ $accept }}" data-ad-drop-input>
            <details>
                <summary class="ad-small">{{ __('Use a link instead') }}</summary>
                <input type="url" class="ad-input" id="{{ $uploadId }}" name="{{ $name }}" value="{{ $current }}" placeholder="https://">
            </details>
        </div>
    </div>
</x-admin.field>
