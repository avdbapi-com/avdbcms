{{-- A menu link. "route" (its address) and "newTab" are this component's inputs, not attributes of the link: printed,
     "route" repeated the address in every menu link (an attribute nothing reads; R1-069). --}}
<li class="nav-item">
    @php
        $localizedRoute = locale_url($route);
        $isActive = str_ends_with(rtrim(URL::current(), '/'), rtrim(parse_url($route, PHP_URL_PATH) ?? $route, '/'));
    @endphp
    <a
        {{ $attributes->except(['newTab', 'route'])->class(['nav-link avd-sidebar-link', 'menu-selected' => $isActive]) }}
        href="{{ $localizedRoute }}"
        @if($isActive) aria-current="page" @endif
        @if($attributes->get('newTab', false)) target="_blank" rel="noopener" @endif
    >
        {{$slot}}
    </a>
</li>
