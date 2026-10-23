{{--
    Every admin page extends this layout. The redesigned admin renders its own document (<x-admin.shell>);
    the old layout (the public layout with the back-office sidebar) stays for one release: config admin.ui = v1
    for everybody, or "Use the old admin for now" in an admin's account menu.

    Optional, from a view: @section('admin_page', 'settings.email') or $adminPage (the nav page when the route
    alone does not tell), @section('admin_width', 'form|wide') or $adminWidth, $adminLegacy (true/false).
--}}
@if (\App\Support\AdminPreferences::usesNewAdmin())
    <x-admin.shell
        :page="trim($__env->yieldContent('admin_page')) ?: ($adminPage ?? null)"
        :width="trim($__env->yieldContent('admin_width')) ?: ($adminWidth ?? null)"
        :legacy="$adminLegacy ?? null"
    />
@else
    <x-layout sidebar="back" type="admin">
        @section('class', 'overflow-auto')
        @if ($message = Session::get('success'))
            <div class="alert alert-success alert-dismissible">
                <strong>{{ $message }}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{__('Close')}}"></button>
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible">
                <strong>{{ $errors->first() }}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{__('Close')}}"></button>
            </div>
        @endif
        @include('admin.modals.confirm-action')
    </x-layout>
@endif
