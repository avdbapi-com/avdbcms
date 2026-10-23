@extends('layouts.admin')
@section('title', __('Interactions'))
@section('content')
<div x-data="{selected:[]}">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-thumbs-up me-2"></i>{{ __('Interactions (Likes / Dislikes)') }}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.interactions') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected interactions?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.interactions.clear') }}" onsubmit='return confirm(@json(__('Clear ALL interactions?')))'>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
        </form>
    </div>
</div>
<hr>
<div class="row mb-4">
    @foreach([
        ['Total', number_format($stats->total ?? 0), 'fa-heart', 'primary'],
        ['Likes', number_format($stats->likes ?? 0), 'fa-thumbs-up', 'success'],
        ['Dislikes', number_format($stats->dislikes ?? 0), 'fa-thumbs-down', 'danger'],
        ['Unique Users', number_format($stats->unique_users ?? 0), 'fa-users', 'info'],
        ['Today', number_format($stats->today ?? 0), 'fa-calendar-day', 'warning'],
    ] as $s)
    <div class="col mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }} fa-lg mb-1"></i>
            <div class="fw-bold fs-5">{{ $s[1] }}</div>
            <small class="text-muted">{{ __($s[0]) }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm"><div class="card-header fw-bold"><i class="fa-solid fa-trophy text-warning me-1"></i>{{__('Top Liked Videos')}}</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                @forelse($topLiked as $v)
                <tr><td class="ps-3">{{ Str::limit($v->title, 35) }}</td><td class="text-end pe-3"><span class="badge bg-success">👍 {{ $v->likes }}</span></td></tr>
                @empty
                <tr><td class="text-center text-muted py-3">{{__('No data')}}</td></tr>
                @endforelse
            </table>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive" tabindex="0">
            <table class="table table-bordered table-striped">
                <thead><tr style="border-top:3px solid #0D6EFD">
                    <th scope="col">
                        <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                            @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                        >
                    </th>
                    <th>{{__('User')}}</th><th>{{__('Type')}}</th><th>{{__('Subject')}}</th><th>{{__('Status')}}</th><th>{{__('Date')}}</th><th>{{__('Action')}}</th>
                </tr></thead>
                <tbody>
                @forelse($interactions as $i)
                <tr>
                    <td class="align-middle">
                        <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $i->id }}" x-model="selected">
                    </td>
                    <td class="align-middle">
                        <div class="fw-bold">{{ $i->username }}</div>
                        <small class="text-muted">{{ $i->email }}</small>
                    </td>
                    <td class="align-middle"><span class="badge bg-secondary">{{ class_basename($i->likeable_type) }}</span></td>
                    <td class="align-middle"><small>#{{ $i->likeable_id }}</small></td>
                    <td class="align-middle">
                        @if($i->status == 1)
                            <span class="badge bg-success"><i class="fa-solid fa-thumbs-up"></i> {{__('Like')}}</span>
                        @else
                            <span class="badge bg-danger"><i class="fa-solid fa-thumbs-down"></i> {{__('Dislike')}}</span>
                        @endif
                    </td>
                    <td class="align-middle"><small>{{ \Carbon\Carbon::parse($i->perform_at)->diffForHumans() }}</small></td>
                    <td class="align-middle">
                        <form method="POST" action="{{ route('admin.interactions.delete', $i->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center py-4">
                    <i class="fa-solid fa-heart-crack fa-2x mb-3 text-muted"></i>
                    <p class="fw-bold text-muted">{{__('No interactions found')}}</p>
                </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$interactions" />
    </div>
</div>
</div>
@endsection
