{{-- Search results of the settings hub (also returned alone for the live search). --}}
@if ($results === [])
    <p class="ad-hub-results" style="padding:14px 20px;margin-top:12px">{{ __('No setting matches ":query". Try another word, for example "email", "logo" or "google".', ['query' => $query]) }}</p>
@else
    <div class="ad-hub-results">
        @foreach ($results as $result)
            <a href="{{ url($result['url']) }}">
                <small>{{ $result['where'] }}</small>
                <b>{{ $result['label'] }}</b>
                <span>{{ $result['help'] }}</span>
            </a>
        @endforeach
    </div>
@endif
