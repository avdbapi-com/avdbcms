{{--
    Any settings page of config/admin_settings.php (Settings > Site basics ... Advanced, Money > Ads, Payment setup,
    Premium page text). One form: changes collect in the save bar and go to PUT /admin/settings/save, which may
    write several settings groups at once. Without JavaScript the form still posts every field it shows.
--}}
@extends('layouts.admin')
@section('title', __($page['label']))
@section('admin_page', $pageId)
@section('admin_width', 'form')

@section('content')
    @include('admin.settings.partials.header', [
        'page' => $pageId,
        'title' => __($page['label']),
        'description' => __($page['description']),
        'crumbs' => [[__($page['section'] === 'money' ? 'Money' : 'Settings'), $page['section'] === 'money' ? url('/admin/money') : route('admin.settings.index')], [__($page['label']), null]],
        'actions' => $headerActions ?? null,
    ])

    @foreach ($extrasTop ?? [] as $extra)
        @include($extra)
    @endforeach

    @include('admin.settings.partials.form')

    @foreach ($extrasBottom ?? [] as $extra)
        @include($extra)
    @endforeach

@endsection
