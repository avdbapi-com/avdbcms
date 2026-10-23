{{--
    Page header: breadcrumbs, the page's one H1, a one-line description, at most one primary action (right)
    and the tabs of merged pages. With no props it describes the current page from config/admin_nav.php,
    which is how the shell gives every existing view a header. A page that renders its own header replaces
    the automatic one (this component marks the request).

    <x-admin.page-header title="Email" description="How your site sends ..." :crumbs="[['label' => 'Settings', 'url' => ...], ['label' => 'Email']]">
        <x-slot:actions><x-admin.button href="..." icon="send">Send a test email</x-admin.button></x-slot:actions>
    </x-admin.page-header>

    Props: page (nav page array or id), title, description, crumbs (list of [label, url]), tabs (list of
    [label, url, active, count]; default: the page's tabs), primary (false hides the nav primary action).
    Slots: actions (replaces the primary action), default slot (under the description).
--}}
@use('App\Support\AdminNav')
@use('App\Support\AdminTheme')
@props(['page' => null, 'title' => null, 'description' => null, 'crumbs' => null, 'tabs' => null, 'primary' => true])
@php
    request()->attributes->set('admin.header', true);

    $page = is_string($page) ? AdminNav::page($page) : ($page ?? (($title === null) ? AdminNav::current() : null));
    $title ??= $page !== null ? __($page['label']) : '';
    $description ??= $page !== null && ! empty($page['description']) ? __($page['description']) : null;
    $crumbs ??= array_map(fn (array $crumb) => ['label' => __($crumb['label']), 'url' => $crumb['url']], AdminNav::crumbs($page));
    $tabs ??= AdminNav::tabs($page);
    $action = $primary !== false && ! isset($actions) ? AdminNav::primaryAction($page) : null;
@endphp
@if ($title !== '')
<div {{ $attributes->class(['ad-ph']) }}>
    <div class="ad-ph-text">
        @if (count($crumbs) > 1)
            <nav class="ad-crumbs" aria-label="{{ __('Breadcrumb') }}">
                @foreach ($crumbs as $crumb)
                    @if (! $loop->first){{ AdminTheme::icon('chev-right') }}@endif
                    @if (! $loop->last && ! empty($crumb['url']))
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                    @else
                        <span @if($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
        <h1>{{ $title }}</h1>
        @if ($description)
            <p>{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>
    @if (isset($actions) && trim((string) $actions) !== '')
        <div class="ad-ph-actions">{{ $actions }}</div>
    @elseif ($action)
        <div class="ad-ph-actions">
            <a class="ad-btn is-primary" href="{{ $action['url'] }}">{{ __($action['label']) }}</a>
        </div>
    @endif
</div>
@if (count($tabs) > 0)
    <x-admin.tabs :tabs="$tabs" />
@endif
@endif
