{{--
    Page-level status: icon + title + one sentence + action buttons.
    <x-admin.callout type="info|ok|warn|danger" title="Emails are not being delivered">Your site is set to ...<x-slot:actions>...</x-slot:actions></x-admin.callout>
--}}
@props(['type' => 'info', 'title' => null, 'icon' => null])
@php($symbol = $icon ?? ['ok' => 'check-circle', 'warn' => 'alert', 'danger' => 'alert-circle'][$type] ?? 'info')
<div {{ $attributes->class(['ad-callout', 'is-'.$type => $type !== 'info']) }}>
    {{ \App\Support\AdminTheme::icon($symbol) }}
    <div class="ad-callout-body">
        @if ($title)
            <h3>{{ $title }}</h3>
        @endif
        @if (trim((string) $slot) !== '')
            <p>{{ $slot }}</p>
        @endif
        @isset($actions)
            <div class="ad-callout-actions">{{ $actions }}</div>
        @endisset
    </div>
</div>
