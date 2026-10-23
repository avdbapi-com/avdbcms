<div @class(["offcanvas offcanvas-start fixed overflow-auto show_sidebar avd-sidebar avd-back-sidebar"]) id="responsive-sidebar" tabindex="-1" data-bs-keyboard="true" data-bs-backdrop="true" data-bs-scroll="false" aria-labelledby="responsive-sidebar-title" aria-modal="true" role="dialog">
    <div class="offcanvas-header d-flex justify-content-between align-items-center d-md-none border-bottom py-2">
        <a id="responsive-sidebar-title" href="{{locale_url(route('pages.home'))}}" class="offcanvas-title h5 fw-bold text-decoration-none d-flex align-items-center gap-2 avd-brand">
            <span class="avd-brand-mark"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
            <span>{{config('app.name')}}</span>
        </a>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{__('Close')}}"></button>
        </div>
    </div>
    <div class="offcanvas-body px-0 pt-0 pb-0">
        <nav class="flex-column flex-shrink-0 d-flex overflow-auto h-100 position-relative avd-sidebar-body avd-back-sidebar-body">
            <ul class="nav nav-pills flex-column mb-auto text-center avd-back-sidebar-menu">
                @foreach(config('menu.'.$type) as $menu)
                    @if(isset($menu['group']))
                        {{-- ─── Collapsible Group ─── --}}
                        @php
                            $groupSlug = Str::slug($menu['group']);
                            $groupActive = false;
                            foreach ($menu['children'] as $child) {
                                if (($child['requires_ops'] ?? false) && !config('admin.operational_tools_enabled', false)) {
                                    continue;
                                }

                                $childPath = rtrim(parse_url(route($child['route']), PHP_URL_PATH) ?? '', '/');
                                if (str_ends_with(rtrim(URL::current(), '/'), $childPath)) {
                                    $groupActive = true;
                                    break;
                                }
                            }
                        @endphp
                        <li class="nav-item avd-sidebar-group {{ $groupActive ? 'is-open' : '' }}">
                            <button class="avd-sidebar-group-toggle" onclick="this.closest('.avd-sidebar-group').classList.toggle('is-open')" type="button">
                                <i style="width:24px" class="fa-solid fa-{{$menu['group_icon']}}" aria-hidden="true"></i>
                                <span class="text-sm">{{__($menu['group'])}}</span>
                                <i class="fa-solid fa-chevron-down avd-group-chevron ms-auto" aria-hidden="true"></i>
                            </button>
                            <ul class="avd-sidebar-group-children">
                                @foreach($menu['children'] as $child)
                                    @continue(($child['requires_ops'] ?? false) && !config('admin.operational_tools_enabled', false))
                                    <x-sidebar-item route="{{route($child['route'])}}" newTab="{{$child['new_tab'] ?? false}}" style="height:2.6rem">
                                        <i style="width:20px" class="fa-solid fa-{{$child['icon']}} text-xs" aria-hidden="true"></i>
                                        <span class="text-sm">{{__($child['title'])}}</span>
                                    </x-sidebar-item>
                                @endforeach
                            </ul>
                        </li>
                    @else
                        {{-- ─── Standalone item ─── --}}
                        <x-sidebar-item route="{{route($menu['route'])}}" newTab="{{$menu['new_tab'] ?? false}}" style="height: 3rem">
                            <i style="width: 24px" class="fa-solid fa-{{$menu['icon']}}" aria-hidden="true"></i>
                            <span @class(['text-sm', $menu['label_class'] ?? null])>{{__($menu['title'])}}</span>
                        </x-sidebar-item>
                    @endif
                @endforeach
            </ul>
            <ul class="nav nav-pills flex-column text-center avd-back-sidebar-account">
                @if (($type ?? null) === 'admin' && config('admin.ui', 'v2') === 'v2' && \App\Support\AdminPreferences::get(auth()->user(), 'admin_ui') === 'v1')
                    {{-- This admin switched back to the old admin for now: one click returns to the new one (admin pages only). --}}
                    <li class="nav-item px-3 pb-2">
                        <form method="POST" action="{{ route('admin.shell.preferences') }}">
                            @csrf
                            <input type="hidden" name="key" value="admin_ui">
                            <input type="hidden" name="value" value="v2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">{{ __('Use the new admin') }}</button>
                        </form>
                    </li>
                @endif
                <li class="nav-item">
                    <a
                        @class(['nav-link d-flex align-items-center gap-3 py-3 avd-sidebar-account-card', 'is-active' => request()->url() === url(locale_url(route('user.edit')))])
                        href="{{locale_url(route('user.edit'))}}"
                        aria-current="page"
                    >
                        <img style="width: 40px" class="rounded-circle" src="{{auth()->user()->avatar_url}}" alt="{{auth()->user()->username}} avatar">
                        <div class="d-flex flex-column gap-2 text-start">
                            <span class="text-sm fw-bold">{{auth()->user()->username}}</span>
                            @if(auth()->user()->is_premium)
                                <div class="badge bg-warning">
                                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                                    <span>{{ __('Premium account') }}</span>
                                </div>
                            @else
                                <div class="badge bg-secondary">{{ __('Standard account') }}</div>
                            @endif
                        </div>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</div>
