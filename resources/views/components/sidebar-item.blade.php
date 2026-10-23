<li class="nav-item">
    @php
        $localizedRoute = locale_url($route);
        $isActive = str_ends_with(rtrim(URL::current(), '/'), rtrim(parse_url($route, PHP_URL_PATH) ?? $route, '/'));
    @endphp
    <a
        {{ $attributes->except('newTab')->class(['nav-link avd-sidebar-link', 'menu-selected' => $isActive]) }}
        href="{{ $localizedRoute }}"
        @if($isActive) aria-current="page" @endif
        @if($attributes->get('newTab', false)) target="_blank" rel="noopener" @endif
    >
        {{$slot}}
    </a>
</li>
