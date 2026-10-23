{{--
    Checklist of a real sequence (numbered). Done steps turn green and shrink to one line; the next step is
    highlighted with its action button.
    <x-admin.steps :steps="[
        ['title' => 'Name your site', 'text' => 'Shown in the header and in emails.', 'done' => true],
        ['title' => 'Import your first videos', 'text' => 'From AVDBAPI or XVIDAPI.', 'action' => ['Import videos', route('admin.avdb-import.index')]],
    ]" />
--}}
@props(['steps' => []])
@php($currentIndex = collect($steps)->search(fn (array $step) => empty($step['done'])))
<ol {{ $attributes->class(['ad-steps']) }}>
    @foreach ($steps as $index => $step)
        @php($done = ! empty($step['done']))
        <li @class(['is-done' => $done, 'is-current' => $index === $currentIndex])>
            <span class="ad-step-n" aria-hidden="true">@if($done){{ \App\Support\AdminTheme::icon('check') }}@endif</span>
            <div class="ad-step-text">
                <b>{{ $step['title'] }}</b>
                @if (! empty($step['text']))
                    <span>{{ $step['text'] }}</span>
                @endif
                @if ($done)
                    <span class="ad-vh">{{ __('Done') }}</span>
                @endif
            </div>
            @if (! $done && ! empty($step['action']))
                <a @class(['ad-btn', 'sm', 'is-primary' => $index === $currentIndex]) href="{{ $step['action'][1] }}">{{ $step['action'][0] }}</a>
            @endif
        </li>
    @endforeach
</ol>
