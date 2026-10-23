{{--
    The settings form of one page of config/admin_settings.php: its cards, the folded "Advanced" cards, the manifest
    for posts without JavaScript, the save bar and the page script. Data: SettingsPageController::form().
    Any admin page that carries a settings card can include it the same way.
--}}
@include('admin.settings.partials.styles')
<form class="ad-settings-form" method="POST" action="{{ $saveUrl }}" autocomplete="off" novalidate data-settings-form data-page="{{ $pageId }}" data-config="{{ json_encode($config) }}">
    @csrf
    @method('PUT')

    @foreach ($cards as $card)
        @include('admin.settings.partials.section', ['card' => $card])
    @endforeach

    @if ($advancedCards !== [])
        @php($advancedCount = array_sum(array_map(fn (array $c): int => count($c['fields']) + count($c['more']), $advancedCards)))
        <details class="ad-adv" data-remember="{{ $pageId }}.advanced">
            <summary>{{ __('Advanced') }} <span class="ad-badge">{{ $advancedCount }}</span></summary>
            @foreach ($advancedCards as $card)
                @include('admin.settings.partials.section', ['card' => $card])
            @endforeach
        </details>
    @endif

    {{-- The manifest of the fields shown: without JavaScript the whole page is posted, and a switch that is
         absent from the post means off. Code boxes are left out (their value only arrives with JavaScript, so
         an empty box must never be saved). With JavaScript only the changed fields are sent. --}}
    @php($manifest = [])
    @foreach (array_merge($cards, $advancedCards) as $card)
        @foreach (array_merge($card['fields'], $card['more']) as $f)
            @unless (in_array($f['control'], ['code', 'code_editor'], true))
                @php($manifest[$f['group']][] = $f['key'])
            @endunless
        @endforeach
    @endforeach
    @foreach ($manifest as $group => $keys)
        <input type="hidden" name="fields[{{ $group }}]" value="{{ implode(',', $keys) }}">
    @endforeach

    {{-- A plain Save row until the page script takes over (it then shows the bar only while something changed),
         so the page can still be saved when the script is blocked or fails to load. --}}
    <div class="ad-savebar is-static" role="region" aria-label="{{ __('Unsaved changes') }}" data-savebar>
        <span class="ad-changes" data-changes aria-live="polite"></span>
        <span class="ad-spacer"></span>
        <button type="button" class="ad-btn" data-discard hidden>{{ __('Discard') }}</button>
        <button type="submit" class="ad-btn is-primary" data-save>{{ __('Save changes') }}</button>
    </div>
</form>
@if ($hiddenExpert > 0)
    <p class="ad-small ad-faint" style="margin-top:16px">{{ __('Settings for developers on this page: :count. They appear in Developer mode (account menu).', ['count' => $hiddenExpert]) }}</p>
@endif

@include('admin.settings.partials.script')
