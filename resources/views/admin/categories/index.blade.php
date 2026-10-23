@extends('layouts.admin')

@section('title', __('Categories'))

@section('content')
    {{ Breadcrumbs::render('categories') }}
    <div class="d-flex justify-content-between align-items-center my-3">
        <h2 class="mb-0">{{__('Categories')}}</h2>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.categories.create') }}" class="btn btn-success d-flex align-items-center gap-1">
                <i class="fa-solid fa-plus"></i>
                <span>{{__('Create')}}</span>
            </a>
            <button class="btn btn-primary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#categories_organise">
                <i class="fa-solid fa-sort"></i>
                <span>{{__('Sort')}}</span>
            </button>
        </div>
    </div>
    <hr>
    <div class="table-responsive" tabindex="0">
        <table class="table table-bordered table-striped">
            <thead>
                <tr style="border-top: 3px solid #0D6EFD;">
                    <th style="min-width: 160px">{{__('Category')}}</th>
                    <th style="min-width: 150px">{{__('Slug')}}</th>
                    <th>{{__('Icon')}}</th>
                    <th style="min-width: 80px">{{__('In menu')}}</th>
                    <th>{{__('Videos')}}</th>
                    <th>{{__('Position')}}</th>
                    <th style="min-width: 120px">{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($all_categories as $category)
                <tr class="bg-light">
                    <td class="align-middle">
                        <small>{{$category->title}}</small>
                    </td>
                    <td class="align-middle">
                        <small>{{$category->slug}}</small>
                    </td>
                    <td class="align-middle">
                        <i class="fa-solid fa-{{$category->icon_name}}"></i>
                    </td>
                    <td class="align-middle">
                        @if($category->in_menu) <span class="badge bg-success">{{__('Yes')}}</span> @else <span class="badge bg-secondary">{{__('No')}}</span> @endif
                    </td>
                    <td class="align-middle">
                        <span class="badge bg-info">{{ $category->videos_count ?? 0 }}</span>
                    </td>
                    <td class="align-middle">
                        <small>{{$category->position}}</small>
                    </td>
                    <td class="align-middle">
                        <div class="d-flex gap-1">
                            <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-primary" aria-label="{{__('Edit')}}"><i class="fa-solid fa-edit"></i></a>
                            <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" onsubmit='return confirm(@json(__('Delete?')))'>
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger" aria-label="{{__('Delete')}}"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">
                        <i class="fa-solid fa-database fa-2x my-3"></i>
                        <p class="fw-bold">{{__('No matching categories')}}</p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <x-admin.pagination :paginator="$all_categories" />
    @include('admin.categories.modals.organise', ['categories' => $active_categories])
@endsection
