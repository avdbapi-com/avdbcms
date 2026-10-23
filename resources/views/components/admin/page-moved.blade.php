{{--
    "What moved where" (sidebar footer): every page of the old admin menu and where it is now.
    Rendered by AdminSearchController::moved() from config/admin_nav.php old_menu.
--}}
@extends('layouts.admin', ['adminPage' => null])
@section('title', __('What moved where'))
@section('content')
    <x-admin.page-header :title="__('What moved where')" :description="__('The admin menu is now grouped in 8 sections. Every old address still works; here is where each old page lives now.')" :crumbs="[]" />
    <x-admin.table :label="__('What moved where')">
        <x-slot:head>
            <th>{{ __('Old menu') }}</th>
            <th>{{ __('Now in') }}</th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['old'] }}</td>
                <td>
                    @if ($row['url'])
                        <a href="{{ $row['url'] }}">{{ $row['section'] }} &rsaquo; {{ $row['page'] }}</a>
                    @else
                        {{ $row['section'] }} &rsaquo; {{ $row['page'] }}
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>
@endsection
