@extends('layouts.admin')
@section('title', __('Thumbnails'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-image me-2"></i>{{__('Video Thumbnails')}}</h2>
    <span class="badge bg-primary fs-6">{{ $thumbnails->total() }} {{__('total')}}</span>
</div>
<hr>

<div class="row mb-4">
    @foreach([
        [__('Total'), $stats->total ?? 0, 'fa-image', 'primary'],
        [__('Active'), $stats->active ?? 0, 'fa-star', 'success'],
        [__('Approved'), $stats->approved ?? 0, 'fa-check', 'info'],
        [__('Videos with Thumbnails'), $stats->unique_videos ?? 0, 'fa-video', 'warning'],
    ] as $s)
    <div class="col mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }} fa-lg mb-1"></i>
            <div class="fw-bold fs-5">{{ number_format((int)$s[1]) }}</div>
            <small class="text-muted">{{ $s[0] }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="row g-3">
    @forelse($thumbnails as $thumb)
    <div class="col-md-3 col-sm-4 col-6">
        <div class="card shadow-sm {{ $thumb->is_active ? 'border-success border-2' : '' }}">
            <div class="position-relative">
                <img src="{{ url('video/' . $thumb->video_uuid . '/thumbnails/' . $thumb->id) }}" alt=""
                     class="card-img-top" style="height:140px;object-fit:cover"
                     onerror="this.src='{{ asset('images/placeholders/no-image.svg') }}'">
                @if($thumb->is_active)
                    <span class="position-absolute top-0 start-0 badge bg-success m-1">
                        <i class="fa-solid fa-star"></i>{{__('Active')}}</span>
                @endif
            </div>
            <div class="card-body p-2">
                <small class="text-truncate d-block fw-bold" title="{{ $thumb->video_title }}">
                    {{ Str::limit($thumb->video_title, 35) }}
                </small>
                <small class="text-muted">{{__('by')}} {{ $thumb->username }}</small>
                <div class="d-flex gap-1 mt-2">
                    @if(!$thumb->is_active)
                    <form method="POST" action="{{ route('admin.thumbnails.set-active', $thumb->id) }}">
                        @csrf
                        <button class="btn btn-xs btn-success py-0 px-2" style="font-size:11px">
                            <i class="fa-solid fa-star"></i> {{__('Set Active')}}
                        </button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('admin.thumbnails.delete', $thumb->id) }}" onsubmit='return confirm(@json(__('Delete thumbnail?')))'>
                        @csrf @method('DELETE')
                        <button class="btn btn-xs btn-danger py-0 px-2" style="font-size:11px" aria-label="{{ __('Delete thumbnail') }}">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="col-12 text-center py-5">
        <i class="fa-solid fa-image fa-3x mb-3 text-muted"></i>
        <p class="fw-bold text-muted">{{__('No thumbnails found')}}</p>
    </div>
    @endforelse
</div>
<x-admin.pagination :paginator="$thumbnails" />
@endsection
