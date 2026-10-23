@extends('layouts.admin')
@section('title', __('Custom Pages'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-file-pen me-2"></i>{{__('Custom Pages (CMS)')}}</{{ $titleTag }}>
    <a href="{{ route('admin.pages.create') }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>{{__('Create Page')}}</a>
</div>

<div class="card shadow">
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>ID</th><th>{{__('Title')}}</th><th>{{__('Slug')}}</th><th>{{__('Status')}}</th><th>{{__('Placement')}}</th><th>{{__('Order')}}</th><th>{{__('Updated')}}</th><th>{{__('Actions')}}</th></tr>
            </thead>
            <tbody>
                @forelse($pages as $page)
                <tr>
                    <td>{{ $page->id }}</td>
                    <td><strong>{{ $page->title }}</strong></td>
                    <td><code>/page/{{ $page->slug }}</code></td>
                    <td>
                        @if($page->is_published)
                            <span class="badge bg-success">{{__('Published')}}</span>
                        @else
                            <span class="badge bg-secondary">{{__('Draft')}}</span>
                        @endif
                    </td>
                    <td>
                        @if($page->show_in_header)<span class="badge bg-info me-1">{{__('Header')}}</span>@endif
                        @if($page->show_in_footer)<span class="badge bg-warning me-1">{{__('Footer')}}</span>@endif
                        @if(!$page->show_in_header && !$page->show_in_footer)<span class="text-muted">-</span>@endif
                    </td>
                    <td>{{ $page->sort_order }}</td>
                    <td>{{ \Carbon\Carbon::parse($page->updated_at)->diffForHumans() }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="{{ route('admin.pages.edit', $page->id) }}" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen"></i></a>
                            <form method="POST" action="{{ route('admin.pages.toggle', $page->id) }}">@csrf
                                <button class="btn btn-outline-{{ $page->is_published ? 'warning' : 'success' }} btn-sm" title="{{ $page->is_published ? __('Unpublish') : __('Publish') }}">
                                    <i class="fa-solid fa-{{ $page->is_published ? 'eye-slash' : 'eye' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.pages.destroy', $page->id) }}" onsubmit='return confirm(@json(__('Delete this page?')))'>@csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No custom pages yet. Click "Create Page" to add one.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$pages" class="mt-0" /></div>
</div>
@endsection
