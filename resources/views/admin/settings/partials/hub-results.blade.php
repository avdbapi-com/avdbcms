{{-- Search results of the settings hub (also returned alone for the live search). Every setting can be found: a developer
     one while Developer mode is off says so (it opens its page, which has the switch), a hidden one says where it is
     changed now (its hint, in place of its help). --}}
@if ($results === [])
    <p class="ad-hub-results" style="padding:14px 20px;margin-top:12px">{{ __('No setting matches ":query". Try another word, for example "email", "logo" or "google".', ['query' => $query]) }}</p>
@else
    <div class="ad-hub-results">
        @foreach ($results as $result)
            <a href="{{ $result['url'] }}" data-setting="{{ $result['key'] }}">
                <small>{{ $result['where'] }}</small>
                <b>{{ $result['label'] }}@if ($result['developer']) <em class="ad-badge">{{ $result['note'] }}</em>@endif</b>
                @if (($result['hidden'] ?? false) && $result['note'] !== null)
                    <span class="ad-hub-note">{{ $result['note'] }}</span>
                @else
                    <span>{{ $result['help'] }}</span>
                    @if ($result['note'] !== null && ! $result['developer'])
                        <span class="ad-hub-note">{{ $result['note'] }}</span>
                    @endif
                @endif
            </a>
        @endforeach
    </div>
    @if (($more ?? 0) > 0)
        <p class="ad-small ad-faint" style="margin-top:8px">{{ trans_choice('1 more setting matches. Add a word to narrow the search.|:count more settings match. Add a word to narrow the search.', $more, ['count' => number_format($more)]) }}</p>
    @endif
@endif
