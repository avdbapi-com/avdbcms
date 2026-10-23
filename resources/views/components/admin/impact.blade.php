{{--
    What an effect costs on a weak phone: <x-admin.impact level="none|low|mid|high" where="all|not-weak|strong" />
--}}
@props(['level' => 'low', 'where' => null])
@php
    $labels = ['none' => __('No cost'), 'low' => __('Low cost'), 'mid' => __('Medium'), 'high' => __('Heavy')];
    $places = ['all' => __('all devices'), 'not-weak' => __('not on weak phones'), 'strong' => __('strong computers only')];
@endphp
<span {{ $attributes->class(['ad-impact', 'is-'.$level]) }} data-level="{{ $labels[$level] ?? $level }}">{{ \App\Support\AdminTheme::icon('zap') }}{{ $labels[$level] ?? $level }}</span>
@if ($where && isset($places[$where]))
    <span class="ad-where">{{ \App\Support\AdminTheme::icon($where === 'strong' ? 'monitor' : 'phone') }}{{ $places[$where] }}</span>
@endif
