@extends('layouts.admin')

@section('title', __('Playlists'))

@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-rectangle-list me-2"></i>{{__('Playlists')}}</h2>
    <span class="badge bg-primary fs-6">{{ $playlists->total() }} {{__('total')}}</span>
</div>
<hr>

{{-- Bulk Actions Bar --}}
<div class="card shadow-sm mb-3" id="bulkBar" style="display:none">
    <div class="card-body py-2 d-flex align-items-center gap-3">
        <span class="fw-bold text-primary"><span id="selectedCount">0</span> {{__('selected')}}</span>
        <form method="POST" action="{{ route('admin.bulk.playlists') }}" id="bulkForm" class="d-flex gap-2 align-items-center">
            @csrf
            <div id="bulkIds"></div>
            <select name="action" class="form-select form-select-sm" style="width:auto" required>
                <option value="">{{__('-- Bulk Action --')}}</option>
                <option value="delete">{{__('Delete')}}</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary" onclick='return confirm(@json(__('Apply bulk action to selected playlists?')))'>{{__('Apply')}}</button>
        </form>
    </div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th><input type="checkbox" id="selectAll" class="form-check-input" aria-label="{{ __('Select all') }}"></th>
            <th style="min-width:200px">{{__('User')}}</th>
            <th style="min-width:300px">{{__('Title')}}</th>
            <th>{{__('Status')}}</th>
            <th>{{__('Videos')}}</th>
            <th style="min-width:150px">{{__('Created')}}</th>
            <th style="min-width:100px">{{__('Actions')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($playlists as $playlist)
            <tr class="bg-light">
                <td class="align-middle"><input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $playlist->id }}"></td>
                <td class="align-middle">
                    @if($playlist->user)
                        <a href="{{ route('admin.users.edit', $playlist->user) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                            <img src="{{ $playlist->user->avatar_url }}" class="rounded" style="width:36px;height:36px;object-fit:cover">
                            <span>{{ $playlist->user->username }}</span>
                        </a>
                    @else
                        <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                    @endif
                </td>
                <td class="align-middle">
                    <a href="{{ $playlist->route }}" target="_blank" class="text-decoration-none">
                        {{ Str::limit($playlist->title, 60) }}
                    </a>
                </td>
                <td class="align-middle">
                    <span class="badge bg-{{ $playlist->status->value === 'public' ? 'success' : ($playlist->status->value === 'private' ? 'danger' : 'secondary') }}">
                        {{ ucfirst($playlist->status->value) }}
                    </span>
                </td>
                <td class="align-middle">
                    <span class="badge bg-info">{{ $playlist->videos_count }}</span>
                </td>
                <td class="align-middle">
                    <small>{{ $playlist->created_at->diffForHumans() }}</small>
                </td>
                <td class="align-middle">
                    <form method="POST" action="{{ route('admin.playlists.delete', $playlist) }}" onsubmit='return confirm(@json(__('Delete this playlist permanently?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger" title="{{__('Delete')}}">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center py-4">
                    <i class="fa-solid fa-rectangle-list fa-2x mb-3 text-muted"></i>
                    <p class="fw-bold text-muted">{{__('No playlists found')}}</p>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$playlists" />

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const bulkBar = document.getElementById('bulkBar');
    const bulkIds = document.getElementById('bulkIds');
    const selectedCount = document.getElementById('selectedCount');

    function updateBulk() {
        const checked = document.querySelectorAll('.bulk-check:checked');
        bulkIds.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
            bulkIds.appendChild(input);
        });
        selectedCount.textContent = checked.length;
        bulkBar.style.display = checked.length > 0 ? 'block' : 'none';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = this.checked);
            updateBulk();
        });
    }
    document.querySelectorAll('.bulk-check').forEach(cb => cb.addEventListener('change', updateBulk));
});
</script>
@endsection
