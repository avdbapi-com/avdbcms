{{--
    Page header: the shell's <x-admin.page-header> when the redesigned admin is installed (rendering it here
    replaces the automatic header, so the page can add its own action), else the same markup.
    Expects $title, $description, optional $page (nav page id), $crumbs ([label, url|null] pairs) and $actions (HTML).
--}}
@php($crumbs = $crumbs ?? [])
@if (view()->exists('components.admin.page-header'))
    <x-dynamic-component component="admin.page-header" :page="$page ?? null" :title="$title" :description="$description"
        :crumbs="array_map(fn (array $crumb): array => ['label' => $crumb[0], 'url' => $crumb[1]], $crumbs)">
        @isset($actions)
            <x-slot:actions>{!! $actions !!}</x-slot:actions>
        @endisset
    </x-dynamic-component>
@else
    <div class="ad-ph">
        <div class="ad-ph-text">
            @if (count($crumbs) > 1)
                <nav class="ad-crumbs" aria-label="{{ __('Breadcrumb') }}">
                    @foreach ($crumbs as [$crumbLabel, $crumbUrl])
                        @if ($crumbUrl)
                            <a href="{{ $crumbUrl }}">{{ $crumbLabel }}</a> <span aria-hidden="true">&rsaquo;</span>
                        @else
                            <span aria-current="page">{{ $crumbLabel }}</span>
                        @endif
                    @endforeach
                </nav>
            @endif
            <h1>{{ $title }}</h1>
            @if ($description)
                <p>{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="ad-ph-actions">{!! $actions !!}</div>
        @endisset
    </div>
@endif
